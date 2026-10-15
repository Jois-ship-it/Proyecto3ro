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

    /**
     * Una página de equipos con su cantidad de integrantes. Busca el texto en nombre, categoría
     * y disciplina, y filtra por estado. Misma forma de filas que findAllConConteo().
     *
     * @param array{q?:string,estado?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarConConteo(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condiciones($f);
        return $this->fetchAll(
            "SELECT e.*, COUNT(ep.participante_id) AS total_integrantes
             FROM equipos e
             LEFT JOIN equipo_participantes ep ON ep.equipo_id = e.id
             {$donde}
             GROUP BY e.id
             ORDER BY e.nombre ASC, e.id ASC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarBuscados(array $f): int
    {
        [$donde, $params] = $this->condiciones($f);
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM equipos e {$donde}", $params);
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condiciones(array $f): array
    {
        $condiciones = [];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''),
            ['e.nombre', 'e.categoria', 'e.disciplina']);
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 'e.estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        return [$this->dondeDe($condiciones), $params];
    }

    public function findByIdConParticipantes(int $id): ?array
    {
        $equipo = $this->findById($id);
        if (!$equipo) return null;
        $equipo['participantes'] = $this->getParticipantes($id);
        return $equipo;
    }

    /** El equipo con sus integrantes TAL COMO LOS VE UN VISITANTE (sin datos de contacto). */
    public function findPublicoConParticipantes(int $id): ?array
    {
        $equipo = $this->findById($id);
        if (!$equipo) return null;
        $equipo['participantes'] = $this->fetchAll(
            "SELECT pp.*, ep.rol_en_equipo
             FROM v_participantes_publicos pp
             JOIN equipo_participantes ep ON ep.participante_id = pp.id
             WHERE ep.equipo_id = :eid
             ORDER BY pp.nombre ASC",
            [':eid' => $id]
        );
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

    /** Los identificadores de los equipos a los que pertenece un participante. @return int[] */
    public function idsDeParticipante(int $participanteId): array
    {
        $filas = $this->fetchAll(
            "SELECT equipo_id FROM equipo_participantes WHERE participante_id = :p ORDER BY equipo_id",
            [':p' => $participanteId]
        );
        return array_map(fn(array $f) => (int) $f['equipo_id'], $filas);
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
