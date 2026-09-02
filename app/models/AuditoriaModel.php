<?php
declare(strict_types=1);

class AuditoriaModel extends BaseModel
{
    protected string $table = 'auditoria';

    public function registrar(array $data): int
    {
        // Serializar valores anteriores y nuevos si son arrays
        if (isset($data['valor_anterior']) && is_array($data['valor_anterior'])) {
            $data['valor_anterior'] = json_encode($data['valor_anterior'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['valor_nuevo']) && is_array($data['valor_nuevo'])) {
            $data['valor_nuevo'] = json_encode($data['valor_nuevo'], JSON_UNESCAPED_UNICODE);
        }

        // IP y user agent
        $data['ip']         = $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $data['user_agent'] = $data['user_agent'] ?? (substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

        return $this->insert($data);
    }

    public function getRecientes(int $limit = 100, int $offset = 0): array
    {
        return $this->fetchAll(
            "SELECT a.*, u.nombre AS usuario_nombre, u.email AS usuario_email
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             ORDER BY a.created_at DESC
             LIMIT :lim OFFSET :off",
            [':lim' => $limit, ':off' => $offset]
        );
    }

    public function countTotal(): int
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM auditoria");
    }

    public function getByUsuario(int $userId, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT * FROM auditoria WHERE usuario_id = :uid
             ORDER BY created_at DESC LIMIT :lim",
            [':uid' => $userId, ':lim' => $limit]
        );
    }
}
