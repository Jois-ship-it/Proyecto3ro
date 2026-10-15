<?php
declare(strict_types=1);

class UsuarioModel extends BaseModel
{
    protected string $table = 'usuarios';

    /**
     * Devuelve el usuario por email sin filtrar por estado (para lógica de login).
     * `bloqueo_vencido` vale 1 si la cuenta tiene un bloqueo con fecha de fin y esa fecha ya pasó;
     * lo calcula la base con su propio reloj, el mismo que escribió `bloqueada_hasta`.
     */
    public function findByEmailAnyStatus(string $email): ?array
    {
        $r = $this->fetchOne(
            "SELECT u.*, r.nombre AS rol_nombre,
                    (u.bloqueada_hasta IS NOT NULL AND u.bloqueada_hasta <= NOW()) AS bloqueo_vencido
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE u.email = :email
             LIMIT 1",
            [':email' => $email]
        );
        return $r ?: null;
    }

    public function incrementFailedAttempts(int $userId): int
    {
        $this->execute("UPDATE usuarios SET failed_attempts = failed_attempts + 1 WHERE id = :id", [':id' => $userId]);
        return (int) $this->fetchColumn("SELECT failed_attempts FROM usuarios WHERE id = :id", [':id' => $userId]);
    }

    public function resetFailedAttempts(int $userId): void
    {
        $this->execute("UPDATE usuarios SET failed_attempts = 0 WHERE id = :id", [':id' => $userId]);
    }

    /**
     * Bloquea una cuenta ACTIVA. Devuelve false, sin tocar nada, si no lo estaba.
     *
     * Antes bloqueaba cualquier cuenta: una `pendiente` o `rechazada` con intentos de login
     * fallidos pasaba a `bloqueada`, y al desbloquearla quedaba `activa` sin que nadie la
     * hubiera aprobado. Solo una cuenta activa puede bloquearse, y por eso desbloquearla la
     * devuelve a un estado que ya tenía.
     *
     * @param int $minutos cuánto dura. 0 = hasta que un administrador la desbloquee. Con una fecha
     *                     de fin, la cuenta se desbloquea sola en el próximo intento de inicio de sesión.
     */
    public function lockAccount(int $userId, int $minutos = 0): bool
    {
        // La cuenta y su perfil se marcan juntos: no puede quedar una bloqueada y el otro activo.
        return Database::transaccion(function () use ($userId, $minutos): bool {
            $hasta = $minutos > 0 ? 'NOW() + INTERVAL ' . (int) $minutos . ' MINUTE' : 'NULL';
            $cambio = $this->execute(
                "UPDATE usuarios SET estado = 'bloqueada', bloqueada_hasta = {$hasta}
                  WHERE id = :id AND estado = 'activo'",
                [':id' => $userId]
            );
            if ($cambio === 0) return false;

            // El perfil de participante, si hay, se suspende para que el administrador lo vea.
            $this->execute(
                "UPDATE participantes SET estado = 'suspendido' WHERE usuario_id = :id AND estado = 'activo'",
                [':id' => $userId]
            );
            return true;
        });
    }

    /**
     * Desbloquea una cuenta BLOQUEADA. Devuelve false, sin tocar nada, si no lo estaba: ni una
     * cuenta pendiente de aprobación ni una rechazada ni una desactivada se activan por acá.
     */
    public function unlockAccount(int $userId): bool
    {
        // Igual que al bloquear: la cuenta y su perfil vuelven juntos.
        return Database::transaccion(function () use ($userId): bool {
            $cambio = $this->execute(
                "UPDATE usuarios SET estado = 'activo', failed_attempts = 0, bloqueada_hasta = NULL
                  WHERE id = :id AND estado = 'bloqueada'",
                [':id' => $userId]
            );
            if ($cambio === 0) return false;

            $this->execute(
                "UPDATE participantes SET estado = 'activo' WHERE usuario_id = :id AND estado = 'suspendido'",
                [':id' => $userId]
            );
            return true;
        });
    }

    /** @return int[] ids de los administradores con la cuenta activa */
    public function idsAdministradoresActivos(): array
    {
        $filas = $this->fetchAll(
            "SELECT u.id FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = 'administrador' AND u.estado = 'activo'
             ORDER BY u.id"
        );
        return array_map('intval', array_column($filas, 'id'));
    }

    /** Administradores con la cuenta activa, sin contar a $exceptoId. */
    public function contarAdministradoresActivos(int $exceptoId = 0): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = 'administrador' AND u.estado = 'activo' AND u.id <> :ex",
            [':ex' => $exceptoId]
        );
    }

    public function findAllConRol(): array
    {
        return $this->fetchAll(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             ORDER BY u.id DESC"
        );
    }

    /** Cuentas de participante pendientes de aprobación (con datos del perfil). */
    public function findParticipantesPendientes(): array
    {
        return $this->fetchAll(
            "SELECT u.*, p.id AS participante_id, p.nick, p.telefono, p.documento
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             LEFT JOIN participantes p ON p.usuario_id = u.id
             WHERE r.nombre = 'participante' AND u.estado = 'pendiente'
             ORDER BY u.created_at ASC"
        );
    }

    /**
     * Una página de los registros pendientes de aprobación, del más antiguo al más nuevo (se
     * atienden en el orden en que llegaron). Busca el texto en nombre, apodo y correo. Misma forma de
     * filas que findParticipantesPendientes().
     *
     * @param array{q?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarParticipantesPendientes(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condicionesPendientes($f);
        return $this->fetchAll(
            "SELECT u.*, p.id AS participante_id, p.nick, p.telefono, p.documento
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             LEFT JOIN participantes p ON p.usuario_id = u.id
             {$donde}
             ORDER BY u.created_at ASC, u.id ASC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarParticipantesPendientes(array $f): int
    {
        [$donde, $params] = $this->condicionesPendientes($f);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             LEFT JOIN participantes p ON p.usuario_id = u.id
             {$donde}",
            $params
        );
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condicionesPendientes(array $f): array
    {
        $condiciones = ["r.nombre = 'participante'", "u.estado = 'pendiente'"];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''), ['u.nombre', 'u.email', 'p.nick']);
        return [$this->dondeDe($condiciones), $params];
    }

    public function countParticipantesPendientes(): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = 'participante' AND u.estado = 'pendiente'"
        );
    }

    /** Usuarios de un rol específico (por nombre de rol). */
    public function findByRolNombre(string $rolNombre): array
    {
        return $this->fetchAll(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = :rol
             ORDER BY u.id DESC",
            [':rol' => $rolNombre]
        );
    }

    /**
     * Una página de las cuentas de un rol. Busca el texto en nombre y correo, y filtra por
     * estado. Misma forma de filas que findByRolNombre().
     *
     * @param array{q?:string,estado?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarPorRol(string $rolNombre, array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condicionesPorRol($rolNombre, $f);
        return $this->fetchAll(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             {$donde}
             ORDER BY u.id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarPorRol(string $rolNombre, array $f): int
    {
        [$donde, $params] = $this->condicionesPorRol($rolNombre, $f);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id {$donde}",
            $params
        );
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condicionesPorRol(string $rolNombre, array $f): array
    {
        $condiciones = ['r.nombre = :rol'];
        $params      = [':rol' => $rolNombre];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''), ['u.nombre', 'u.email']);
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 'u.estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        return [$this->dondeDe($condiciones), $params];
    }

    public function findByIdConRol(int $id): ?array
    {
        $r = $this->fetchOne(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE u.id = :id LIMIT 1",
            [':id' => $id]
        );
        return $r ?: null;
    }

    public function emailExiste(string $email, int $excludeId = 0): bool
    {
        $r = $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios WHERE email = :e AND id != :ex",
            [':e' => $email, ':ex' => $excludeId]
        );
        return (int)$r > 0;
    }
}
