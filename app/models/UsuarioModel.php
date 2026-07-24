<?php
declare(strict_types=1);

class UsuarioModel extends BaseModel
{
    protected string $table = 'usuarios';

    public function findByEmail(string $email): ?array
    {
        $r = $this->fetchOne(
            "SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE u.email = :email AND u.estado = 'activo'
             LIMIT 1",
            [':email' => $email]
        );
        return $r ?: null;
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

    public function countByRol(string $rolNombre): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = :r AND u.estado = 'activo'",
            [':r' => $rolNombre]
        );
    }
}
