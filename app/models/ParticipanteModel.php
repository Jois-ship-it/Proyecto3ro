<?php
declare(strict_types=1);

class ParticipanteModel extends BaseModel
{
    protected string $table = 'participantes';

    public function findAllConEquipo(): array
    {
        return $this->fetchAll(
            "SELECT p.*,
                    GROUP_CONCAT(e.nombre SEPARATOR ', ') AS equipos
             FROM participantes p
             LEFT JOIN equipo_participantes ep ON ep.participante_id = p.id
             LEFT JOIN equipos e ON e.id = ep.equipo_id
             GROUP BY p.id
             ORDER BY p.nombre ASC"
        );
    }

    public function findByIdConEquipos(int $id): ?array
    {
        $p = $this->findById($id);
        if (!$p) return null;
        $p['equipos'] = $this->fetchAll(
            "SELECT e.*, ep.rol_en_equipo
             FROM equipos e
             JOIN equipo_participantes ep ON ep.equipo_id = e.id
             WHERE ep.participante_id = :pid",
            [':pid' => $id]
        );
        return $p;
    }

    public function search(string $term): array
    {
        $t = '%' . $term . '%';
        return $this->fetchAll(
            "SELECT * FROM participantes
             WHERE nombre LIKE :t OR nick LIKE :t OR email LIKE :t OR documento LIKE :t
             ORDER BY nombre ASC
             LIMIT 50",
            [':t' => $t]
        );
    }

    /** Participantes NO inscritos en un torneo dado */
    public function findNoInscritos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT p.* FROM participantes p
             WHERE p.estado = 'activo'
               AND p.id NOT IN (
                   SELECT participante_id FROM inscripciones
                   WHERE torneo_id = :tid AND participante_id IS NOT NULL
               )
             ORDER BY p.nombre ASC",
            [':tid' => $torneoId]
        );
    }

    /** ¿Existe otro participante con el mismo documento? (excluye $exceptId) */
    public function documentoExiste(string $documento, int $exceptId = 0): bool
    {
        $documento = trim($documento);
        if ($documento === '') return false;
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM participantes WHERE documento = :d AND id <> :id",
            [':d' => $documento, ':id' => $exceptId]
        );
    }

    public function findByUsuario(int $usuarioId): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM participantes WHERE usuario_id = :uid LIMIT 1",
            [':uid' => $usuarioId]
        );
        return $r ?: null;
    }
}
