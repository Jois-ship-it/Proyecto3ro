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

    public function updateEstado(int $id, string $estado): void
    {
        $this->query(
            "UPDATE rondas SET estado = :e WHERE id = :id",
            [':e' => $estado, ':id' => $id]
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
