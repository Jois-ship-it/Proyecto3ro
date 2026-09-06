<?php
declare(strict_types=1);

class EquipoModel extends BaseModel
{
    protected string $table = 'equipos';

    public function findAllConConteo(): array
    {
        return $this->fetchAll(
            "SELECT e.*, COUNT(ep.participante_id) AS total_integrantes
             FROM equipos e
             LEFT JOIN equipo_participantes ep ON ep.equipo_id = e.id
             GROUP BY e.id
             ORDER BY e.nombre ASC"
        );
    }

    public function findByIdConParticipantes(int $id): ?array
    {
        $equipo = $this->findById($id);
        if (!$equipo) return null;
        $equipo['participantes'] = $this->getParticipantes($id);
        return $equipo;
    }

    public function getParticipantes(int $equipoId): array
    {
        return $this->fetchAll(
            "SELECT p.*, ep.rol_en_equipo
             FROM participantes p
             JOIN equipo_participantes ep ON ep.participante_id = p.id
             WHERE ep.equipo_id = :eid
             ORDER BY p.nombre ASC",
            [':eid' => $equipoId]
        );
    }

    public function agregarParticipante(int $equipoId, int $participanteId, string $rol = 'jugador'): bool
    {
        try {
            $this->query(
                "INSERT IGNORE INTO equipo_participantes (equipo_id, participante_id, rol_en_equipo)
                 VALUES (:e, :p, :r)",
                [':e' => $equipoId, ':p' => $participanteId, ':r' => $rol]
            );
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function quitarParticipante(int $equipoId, int $participanteId): bool
    {
        $stmt = $this->query(
            "DELETE FROM equipo_participantes WHERE equipo_id = :e AND participante_id = :p",
            [':e' => $equipoId, ':p' => $participanteId]
        );
        return $stmt->rowCount() > 0;
    }

    public function tieneParticipante(int $equipoId, int $participanteId): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM equipo_participantes
             WHERE equipo_id = :e AND participante_id = :p",
            [':e' => $equipoId, ':p' => $participanteId]
        );
    }

    /** Equipos NO inscritos en un torneo, con su cantidad de integrantes. */
    public function findNoInscritos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT e.*, COUNT(ep.participante_id) AS total_integrantes
             FROM equipos e
             LEFT JOIN equipo_participantes ep ON ep.equipo_id = e.id
             WHERE e.estado = 'activo'
               AND e.id NOT IN (
                   SELECT equipo_id FROM inscripciones
                   WHERE torneo_id = :tid AND equipo_id IS NOT NULL AND estado = 'activa'
               )
             GROUP BY e.id
             ORDER BY e.nombre ASC",
            [':tid' => $torneoId]
        );
    }

    /** ¿Existe otro equipo con el mismo nombre? (excluye $exceptId) */
    public function nombreExiste(string $nombre, int $exceptId = 0): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM equipos WHERE LOWER(nombre) = LOWER(:n) AND id <> :id",
            [':n' => trim($nombre), ':id' => $exceptId]
        );
    }

    /** Cantidad de integrantes de un equipo. */
    public function contarIntegrantes(int $equipoId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM equipo_participantes WHERE equipo_id = :e",
            [':e' => $equipoId]
        );
    }
}
