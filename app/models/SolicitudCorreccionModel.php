<?php
declare(strict_types=1);

class SolicitudCorreccionModel extends BaseModel
{
    protected string $table = 'solicitudes_correccion';

    /** Solicitudes con datos del torneo, enfrentamiento (nombres) y solicitante. */
    public function getConDetalle(string $estado = '', ?int $organizadorId = null): array
    {
        $where  = [];
        $params = [];
        if ($estado !== '') { $where[] = 's.estado = :estado'; $params[':estado'] = $estado; }
        if ($organizadorId !== null) {
            $where[] = 's.torneo_id IN (SELECT torneo_id FROM torneo_organizadores WHERE usuario_id = :uid)';
            $params[':uid'] = $organizadorId;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->fetchAll(
            "SELECT s.*,
                    t.nombre AS torneo_nombre, t.modalidad,
                    u.nombre AS solicitante_nombre,
                    rv.nombre AS revisor_nombre,
                    pa.nombre AS participante_a_nombre, pb.nombre AS participante_b_nombre,
                    ea.nombre AS equipo_a_nombre, eb.nombre AS equipo_b_nombre,
                    res.puntos_a AS actual_a, res.puntos_b AS actual_b
             FROM solicitudes_correccion s
             JOIN torneos t            ON t.id = s.torneo_id
             JOIN usuarios u           ON u.id = s.solicitado_por
             LEFT JOIN usuarios rv     ON rv.id = s.revisado_por
             JOIN enfrentamientos enf  ON enf.id = s.enfrentamiento_id
             LEFT JOIN participantes pa ON pa.id = enf.participante_a_id
             LEFT JOIN participantes pb ON pb.id = enf.participante_b_id
             LEFT JOIN equipos ea       ON ea.id = enf.equipo_a_id
             LEFT JOIN equipos eb       ON eb.id = enf.equipo_b_id
             LEFT JOIN resultados res   ON res.enfrentamiento_id = s.enfrentamiento_id
             {$sqlWhere}
             ORDER BY (s.estado = 'pendiente') DESC, s.created_at DESC",
            $params
        );
    }

    public function getByIdDetalle(int $id): ?array
    {
        $r = $this->fetchOne(
            "SELECT s.*, t.modalidad FROM solicitudes_correccion s
             JOIN torneos t ON t.id = s.torneo_id
             WHERE s.id = :id LIMIT 1",
            [':id' => $id]
        );
        return $r ?: null;
    }

    public function hasPendiente(int $enfrentamientoId): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM solicitudes_correccion
             WHERE enfrentamiento_id = :e AND estado = 'pendiente'",
            [':e' => $enfrentamientoId]
        );
    }

    /** IDs de enfrentamientos con solicitud pendiente para un torneo. */
    public function pendientesIdsByTorneo(int $torneoId): array
    {
        $rows = $this->fetchAll(
            "SELECT enfrentamiento_id FROM solicitudes_correccion
             WHERE torneo_id = :t AND estado = 'pendiente'",
            [':t' => $torneoId]
        );
        return array_map('intval', array_column($rows, 'enfrentamiento_id'));
    }

    public function countPendientes(): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM solicitudes_correccion WHERE estado = 'pendiente'"
        );
    }

    public function marcarResuelta(int $id, string $estado, int $revisorId, string $motivoRechazo = ''): void
    {
        $this->query(
            "UPDATE solicitudes_correccion
             SET estado = :e, revisado_por = :r, motivo_rechazo = :mr, resuelto_at = NOW()
             WHERE id = :id",
            [':e' => $estado, ':r' => $revisorId, ':mr' => $motivoRechazo ?: null, ':id' => $id]
        );
    }
}
