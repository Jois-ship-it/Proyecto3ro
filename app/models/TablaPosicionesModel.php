<?php
declare(strict_types=1);

class TablaPosicionesModel extends BaseModel
{
    protected string $table = 'tabla_posiciones';

    public function getByTorneo(int $torneoId): array
    {
        // La vista v_posiciones junta cada fila con el nombre de su competidor.
        return $this->fetchAll(
            "SELECT * FROM v_posiciones WHERE torneo_id = :tid ORDER BY posicion ASC",
            [':tid' => $torneoId]
        );
    }

    /**
     * La fila de un competidor en la tabla de un torneo, con cuántos compiten en total. Se pasa el
     * participante o el equipo (el otro en null), según la modalidad del torneo.
     */
    public function filaDe(int $torneoId, ?int $participanteId, ?int $equipoId): ?array
    {
        $fila = $this->fetchOne(
            "SELECT tp.posicion, tp.puntos, tp.pj, tp.pg, tp.pe, tp.pp,
                    (SELECT COUNT(*) FROM tabla_posiciones x WHERE x.torneo_id = tp.torneo_id) AS total
             FROM tabla_posiciones tp
             WHERE tp.torneo_id = :tid AND (tp.participante_id = :p OR tp.equipo_id = :e)
             LIMIT 1",
            [':tid' => $torneoId, ':p' => $participanteId, ':e' => $equipoId]
        );
        return $fila ?: null;
    }

    /** Inserta o actualiza una fila de la tabla de posiciones */
    public function upsert(array $data): void
    {
        $cols  = implode(', ', array_keys($data));
        $binds = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data)));
        $sets  = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_keys($data)));

        $this->query(
            "INSERT INTO tabla_posiciones ({$cols}) VALUES ({$binds})
             ON DUPLICATE KEY UPDATE {$sets}",
            $data
        );
    }

    public function deleteByTorneo(int $torneoId): void
    {
        $this->query("DELETE FROM tabla_posiciones WHERE torneo_id = :tid", [':tid' => $torneoId]);
    }

}
