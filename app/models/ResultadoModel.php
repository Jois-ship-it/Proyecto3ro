<?php
declare(strict_types=1);

class ResultadoModel extends BaseModel
{
    protected string $table = 'resultados';

    public function getByEnfrentamiento(int $enfrentamientoId): ?array
    {
        $r = $this->fetchOne(
            "SELECT r.*,
                    gp.nombre AS ganador_participante_nombre,
                    ge.nombre AS ganador_equipo_nombre,
                    uc.nombre AS cargado_por_nombre,
                    ucorr.nombre AS usuario_correccion_nombre
             FROM resultados r
             LEFT JOIN participantes gp ON gp.id = r.ganador_participante_id
             LEFT JOIN equipos ge ON ge.id = r.ganador_equipo_id
             LEFT JOIN usuarios uc ON uc.id = r.cargado_por
             LEFT JOIN usuarios ucorr ON ucorr.id = r.usuario_correccion_id
             WHERE r.enfrentamiento_id = :eid LIMIT 1",
            [':eid' => $enfrentamientoId]
        );
        return $r ?: null;
    }

    public function getByTorneo(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT r.* FROM resultados r
             JOIN enfrentamientos e ON e.id = r.enfrentamiento_id
             WHERE e.torneo_id = :tid
             ORDER BY r.fecha_carga DESC",
            [':tid' => $torneoId]
        );
    }
}
