<?php
declare(strict_types=1);

class InscripcionModel extends BaseModel
{
    protected string $table = 'inscripciones';

    /** Devuelve todas las inscripciones de un torneo con datos del participante o equipo */
    public function getByTorneo(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    p.nombre AS participante_nombre, p.nick, p.email AS participante_email,
                    e.nombre AS equipo_nombre
             FROM inscripciones i
             LEFT JOIN participantes p ON p.id = i.participante_id
             LEFT JOIN equipos e ON e.id = i.equipo_id
             WHERE i.torneo_id = :tid AND i.estado = 'activa'
             ORDER BY i.orden_seed ASC, i.id ASC",
            [':tid' => $torneoId]
        );
    }

    /** Los inscritos de un torneo como los ve un visitante: sin el email del participante. */
    public function getByTorneoPublico(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT i.*,
                    p.nombre AS participante_nombre, p.nick,
                    e.nombre AS equipo_nombre
             FROM inscripciones i
             LEFT JOIN participantes p ON p.id = i.participante_id
             LEFT JOIN equipos e ON e.id = i.equipo_id
             WHERE i.torneo_id = :tid AND i.estado = 'activa'
             ORDER BY i.orden_seed ASC, i.id ASC",
            [':tid' => $torneoId]
        );
    }

    public function isInscritoParticipante(int $torneoId, int $participanteId): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscripciones
             WHERE torneo_id = :t AND participante_id = :p AND estado = 'activa'",
            [':t' => $torneoId, ':p' => $participanteId]
        );
    }

    public function isInscritoEquipo(int $torneoId, int $equipoId): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscripciones
             WHERE torneo_id = :t AND equipo_id = :e AND estado = 'activa'",
            [':t' => $torneoId, ':e' => $equipoId]
        );
    }

    public function desinscribirParticipante(int $torneoId, int $participanteId): bool
    {
        $stmt = $this->query(
            "UPDATE inscripciones SET estado = 'retirada'
             WHERE torneo_id = :t AND participante_id = :p",
            [':t' => $torneoId, ':p' => $participanteId]
        );
        return $stmt->rowCount() > 0;
    }

    public function desinscribirEquipo(int $torneoId, int $equipoId): bool
    {
        $stmt = $this->query(
            "UPDATE inscripciones SET estado = 'retirada'
             WHERE torneo_id = :t AND equipo_id = :e",
            [':t' => $torneoId, ':e' => $equipoId]
        );
        return $stmt->rowCount() > 0;
    }

    public function getByParticipante(int $participanteId): array
    {
        return $this->fetchAll(
            "SELECT i.*, t.nombre AS torneo_nombre, tt.slug AS tipo_slug, t.estado AS torneo_estado
             FROM inscripciones i
             JOIN torneos t ON t.id = i.torneo_id
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             WHERE i.participante_id = :pid
             ORDER BY i.id DESC",
            [':pid' => $participanteId]
        );
    }

    /**
     * Los torneos ya empezados en los que compite una persona, sola o a través de uno de sus equipos,
     * con el formato y el campeón. Primero los que están en curso. Sirve para el panel del
     * participante (su posición en cada uno).
     *
     * @param int[] $equipoIds los equipos del participante
     */
    public function competenciasDe(int $participanteId, array $equipoIds, int $limite): array
    {
        $params = [':pid' => $participanteId];
        $cond   = 'i.participante_id = :pid';
        if ($equipoIds !== []) {
            $marcas = [];
            foreach (array_values($equipoIds) as $n => $equipoId) {
                $marcas[]          = ":e{$n}";
                $params[":e{$n}"]  = (int) $equipoId;
            }
            $cond .= ' OR i.equipo_id IN (' . implode(',', $marcas) . ')';
        }
        return $this->fetchAll(
            "SELECT i.participante_id, i.equipo_id, eq.nombre AS equipo_nombre,
                    t.id AS torneo_id, t.nombre AS torneo_nombre, t.estado AS torneo_estado,
                    t.campeon_participante_id, t.campeon_equipo_id, tt.slug AS tipo_slug
             FROM inscripciones i
             JOIN torneos t ON t.id = i.torneo_id
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             LEFT JOIN equipos eq ON eq.id = i.equipo_id
             WHERE i.estado = 'activa'
               AND t.estado IN ('en_curso', 'finalizado')
               AND ({$cond})
             ORDER BY (t.estado = 'en_curso') DESC, t.id DESC"
            . $this->limiteDe($limite, 0),
            $params
        );
    }

    /**
     * Una página de las inscripciones de un participante, de la más nueva a la más vieja. Busca el
     * texto en el nombre del torneo y filtra por el estado del torneo. Misma forma de filas que
     * getByParticipante().
     *
     * @param array{q?:string,estado?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarPorParticipante(int $participanteId, array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condicionesDeParticipante($participanteId, $f);
        return $this->fetchAll(
            "SELECT i.*, t.nombre AS torneo_nombre, tt.slug AS tipo_slug, t.estado AS torneo_estado
             FROM inscripciones i
             JOIN torneos t ON t.id = i.torneo_id
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             {$donde}
             ORDER BY i.id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarPorParticipante(int $participanteId, array $f): int
    {
        [$donde, $params] = $this->condicionesDeParticipante($participanteId, $f);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscripciones i JOIN torneos t ON t.id = i.torneo_id {$donde}",
            $params
        );
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condicionesDeParticipante(int $participanteId, array $f): array
    {
        $condiciones = ['i.participante_id = :pid'];
        $params      = [':pid' => $participanteId];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''), ['t.nombre']);
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 't.estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        return [$this->dondeDe($condiciones), $params];
    }

    /** Buscar inscripcion existente por torneo + participante o equipo (cualquier estado) */
    public function findInscripcion(int $torneoId, ?int $participanteId, ?int $equipoId): ?array
    {
        if ($participanteId) {
            $r = $this->fetchOne(
                "SELECT * FROM inscripciones WHERE torneo_id = :t AND participante_id = :p LIMIT 1",
                [':t' => $torneoId, ':p' => $participanteId]
            );
            return $r ?: null;
        }
        if ($equipoId) {
            $r = $this->fetchOne(
                "SELECT * FROM inscripciones WHERE torneo_id = :t AND equipo_id = :e LIMIT 1",
                [':t' => $torneoId, ':e' => $equipoId]
            );
            return $r ?: null;
        }
        return null;
    }

    /** Reactivar una inscripcion existente (activa + limpiar orden_seed) */
    public function reactivar(int $id): bool
    {
        return $this->update($id, ['estado' => 'activa', 'orden_seed' => null]);
    }

    /** Extrae los IDs de equipo o participante de un listado de inscripciones (según modalidad). */
    public static function idsDesdeInscripciones(array $inscripciones, bool $esEquipos): array
    {
        return array_map(
            fn($i) => $esEquipos ? (int)$i['equipo_id'] : (int)$i['participante_id'],
            $inscripciones
        );
    }
}
