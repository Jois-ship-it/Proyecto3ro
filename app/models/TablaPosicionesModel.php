<?php
declare(strict_types=1);

class TablaPosicionesModel extends BaseModel
{
    protected string $table = 'tabla_posiciones';

    public function getByTorneo(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT tp.*,
                    p.nombre AS participante_nombre, p.nick,
                    e.nombre AS equipo_nombre
             FROM tabla_posiciones tp
             LEFT JOIN participantes p ON p.id = tp.participante_id
             LEFT JOIN equipos e ON e.id = tp.equipo_id
             WHERE tp.torneo_id = :tid
             ORDER BY tp.posicion ASC",
            [':tid' => $torneoId]
        );
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

    public function getByParticipanteYTorneo(int $torneoId, int $participanteId): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM tabla_posiciones
             WHERE torneo_id = :tid AND participante_id = :pid LIMIT 1",
            [':tid' => $torneoId, ':pid' => $participanteId]
        );
        return $r ?: null;
    }

    public function getPuntosByParticipante(int $torneoId, int $participanteId): int
    {
        $r = $this->fetchColumn(
            "SELECT puntos FROM tabla_posiciones
             WHERE torneo_id = :tid AND participante_id = :pid",
            [':tid' => $torneoId, ':pid' => $participanteId]
        );
        return (int)($r ?? 0);
    }
}
