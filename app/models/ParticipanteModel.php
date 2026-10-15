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

    /**
     * Una página de participantes con sus equipos. Busca el texto en nombre, apodo, documento y
     * correo, y filtra por estado. Misma forma de filas que findAllConEquipo().
     *
     * @param array{q?:string,estado?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarConEquipo(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condiciones($f);
        return $this->fetchAll(
            "SELECT p.*,
                    GROUP_CONCAT(e.nombre SEPARATOR ', ') AS equipos
             FROM participantes p
             LEFT JOIN equipo_participantes ep ON ep.participante_id = p.id
             LEFT JOIN equipos e ON e.id = ep.equipo_id
             {$donde}
             GROUP BY p.id
             ORDER BY p.nombre ASC, p.id ASC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarBuscados(array $f): int
    {
        [$donde, $params] = $this->condiciones($f);
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM participantes p {$donde}", $params);
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condiciones(array $f): array
    {
        $condiciones = [];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''),
            ['p.nombre', 'p.nick', 'p.documento', 'p.email']);
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 'p.estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        return [$this->dondeDe($condiciones), $params];
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

    /**
     * El perfil que ve un visitante: sale de v_participantes_publicos, que no tiene
     * documento, email ni teléfono. Devuelve null si el participante no existe o
     * todavía no es público (pendiente de aprobación o rechazado).
     */
    public function findPublicoConEquipos(int $id): ?array
    {
        $p = $this->fetchOne("SELECT * FROM v_participantes_publicos WHERE id = :id", [':id' => $id]);
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

    /** Participantes NO inscritos en un torneo dado */
    public function findNoInscritos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT p.* FROM participantes p
             WHERE p.estado = 'activo'
               AND p.id NOT IN (
                   SELECT participante_id FROM inscripciones
                   WHERE torneo_id = :tid AND participante_id IS NOT NULL AND estado = 'activa'
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
