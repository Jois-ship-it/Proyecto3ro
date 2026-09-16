<?php
declare(strict_types=1);

class RondaModel extends BaseModel
{
    protected string $table = 'rondas';

    public function getByTorneo(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT * FROM rondas WHERE torneo_id = :tid ORDER BY numero ASC",
            [':tid' => $torneoId]
        );
    }

    public function getUltimaByTorneo(int $torneoId): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM rondas WHERE torneo_id = :tid ORDER BY numero DESC LIMIT 1",
            [':tid' => $torneoId]
        );
        return $r ?: null;
    }

    public function getByTorneoYNumero(int $torneoId, int $numero): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM rondas WHERE torneo_id = :tid AND numero = :n LIMIT 1",
            [':tid' => $torneoId, ':n' => $numero]
        );
        return $r ?: null;
    }

    public function countByTorneo(int $torneoId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM rondas WHERE torneo_id = :tid",
            [':tid' => $torneoId]
        );
    }

    /** Cantidad de rondas de desempate generadas para un torneo. */
    public function contarDesempates(int $torneoId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM rondas WHERE torneo_id = :tid AND nombre LIKE 'Desempate%'",
            [':tid' => $torneoId]
        );
    }

    public function updateEstado(int $rondaId, string $estado): void
    {
        $this->query(
            "UPDATE rondas SET estado = :e WHERE id = :id",
            [':e' => $estado, ':id' => $rondaId]
        );
    }

    /**
     * Situación de los partidos de una ronda, para decidir su estado:
     *   total        — cuántos enfrentamientos tiene
     *   terminados   — finalizado / bye / cancelado (ya no admiten carga)
     *   incompletos  — todavía sin los dos lados definidos (bracket a medio armar)
     *
     * @return array{total:int,terminados:int,incompletos:int}
     */
    public function contarPartidos(int $rondaId): array
    {
        $r = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(estado IN ('finalizado','bye','cancelado')) AS terminados,
                    SUM(es_bye = 0
                        AND ((participante_a_id IS NULL AND equipo_a_id IS NULL)
                          OR (participante_b_id IS NULL AND equipo_b_id IS NULL))) AS incompletos
             FROM enfrentamientos
             WHERE ronda_id = :rid",
            [':rid' => $rondaId]
        );

        return [
            'total'       => (int) ($r['total']       ?? 0),
            'terminados'  => (int) ($r['terminados']  ?? 0),
            'incompletos' => (int) ($r['incompletos'] ?? 0),
        ];
    }

    /** Ronda a la que pertenece un enfrentamiento (null si no existe). */
    public function findByEnfrentamiento(int $enfrentamientoId): ?array
    {
        $r = $this->fetchOne(
            "SELECT r.* FROM rondas r
             JOIN enfrentamientos e ON e.ronda_id = r.id
             WHERE e.id = :eid LIMIT 1",
            [':eid' => $enfrentamientoId]
        );
        return $r ?: null;
    }

    /** Verifica si la última ronda tiene todos los partidos finalizados */
    public function ultimaRondaCompleta(int $torneoId): bool
    {
        $ultima = $this->getUltimaByTorneo($torneoId);
        if (!$ultima) return true;

        $pendientes = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM enfrentamientos
             WHERE ronda_id = :rid AND estado NOT IN ('finalizado','bye','cancelado')",
            [':rid' => $ultima['id']]
        );
        return $pendientes === 0;
    }
}
