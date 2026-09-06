<?php
declare(strict_types=1);

class UsuarioModel extends BaseModel
{
    protected string $table = 'usuarios';

    /** Devuelve el usuario por email sin filtrar por estado (para lógica de login). */
    public function findByEmailAnyStatus(string $email): ?array
    {
        $r = $this->fetchOne(
            "SELECT u.*, r.nombre AS rol_nombre
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

    public function lockAccount(int $userId): void
    {
        // Marcar usuario como bloqueado
        $this->execute("UPDATE usuarios SET estado = 'bloqueada' WHERE id = :id", [':id' => $userId]);
        // Si existe perfil participante asociado, marcarlo como suspendido para que el admin lo vea
        $this->execute("UPDATE participantes SET estado = 'suspendido' WHERE usuario_id = :id", [':id' => $userId]);
    }

    public function unlockAccount(int $userId): void
    {
        $this->execute("UPDATE usuarios SET estado = 'activo', failed_attempts = 0 WHERE id = :id", [':id' => $userId]);
        // Restaurar participante asociado (si existe) a activo
        $this->execute("UPDATE participantes SET estado = 'activo' WHERE usuario_id = :id", [':id' => $userId]);
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
