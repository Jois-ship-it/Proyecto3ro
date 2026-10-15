<?php
declare(strict_types=1);

class EnfrentamientoModel extends BaseModel
{
    protected string $table = 'enfrentamientos';

    /**
     * Los partidos de una ronda, con los nombres de sus dos lados. Sale de la vista
     * v_calendario, que además trae el torneo, la ronda, el ganador y el resultado
     * (`puntos_a`, `puntos_b`, null si el partido no se jugó). `resultado_corregido` dice si ese
     * resultado fue corregido después de cargarse: la vista lo muestra con una marca.
     *
     * Con esto las pantallas no tienen que pedir el resultado partido por partido (ver
     * View::resultadoDe()).
     */
    public function getByRonda(int $rondaId): array
    {
        return $this->fetchAll(
            "SELECT c.*, r.corregido AS resultado_corregido
             FROM v_calendario c
             LEFT JOIN resultados r ON r.enfrentamiento_id = c.id
             WHERE c.ronda_id = :rid
             ORDER BY c.orden ASC",
            [':rid' => $rondaId]
        );
    }

    /**
     * El filtro «este partido es de este competidor»: el participante, o cualquiera de sus equipos.
     * Un partido de un torneo por equipos lo juega el equipo, no la persona.
     *
     * @param int[] $equipoIds
     * @return array{0:string,1:array} el fragmento de SQL (sobre el alias `c`) y sus parámetros
     */
    private function esDelCompetidor(int $participanteId, array $equipoIds): array
    {
        $partes = ['c.participante_a_id = :pa', 'c.participante_b_id = :pb'];
        $params = [':pa' => $participanteId, ':pb' => $participanteId];
        if ($equipoIds !== []) {
            // Con consultas preparadas nativas un nombre no puede repetirse: uno por cada equipo y lado.
            $lados = ['a' => [], 'b' => []];
            foreach (array_values($equipoIds) as $i => $equipoId) {
                foreach (['a', 'b'] as $lado) {
                    $lados[$lado][] = ":e{$lado}{$i}";
                    $params[":e{$lado}{$i}"] = (int) $equipoId;
                }
            }
            $partes[] = 'c.equipo_a_id IN (' . implode(',', $lados['a']) . ')';
            $partes[] = 'c.equipo_b_id IN (' . implode(',', $lados['b']) . ')';
        }
        return ['(' . implode(' OR ', $partes) . ')', $params];
    }

    /**
     * Los próximos partidos de un competidor: los que le faltan jugar en torneos en curso, con los
     * dos rivales ya definidos (en una llave, hasta que no se juega la ronda anterior no hay partido
     * que mostrar). Primero los que tienen fecha, del más cercano al más lejano; después los demás,
     * por torneo y ronda.
     *
     * @param int[] $equipoIds los equipos del participante
     */
    public function proximosDe(int $participanteId, array $equipoIds, int $limite): array
    {
        [$esSuyo, $params] = $this->esDelCompetidor($participanteId, $equipoIds);
        return $this->fetchAll(
            "SELECT c.id, c.torneo_id, c.torneo_nombre, c.ronda_numero, c.ronda_nombre, c.orden, c.fecha_programada,
                    c.participante_a_id, c.participante_b_id, c.equipo_a_id, c.equipo_b_id, c.lado_a, c.lado_b
             FROM v_calendario c
             JOIN torneos t ON t.id = c.torneo_id
             WHERE t.estado = 'en_curso'
               AND c.estado IN ('pendiente', 'en_curso')
               AND c.es_bye = 0
               AND c.lado_a IS NOT NULL AND c.lado_b IS NOT NULL
               AND {$esSuyo}
             ORDER BY (c.fecha_programada IS NULL), c.fecha_programada ASC, c.torneo_id ASC, c.ronda_numero ASC, c.orden ASC, c.id ASC"
            . $this->limiteDe($limite, 0),
            $params
        );
    }

    /**
     * Los últimos partidos jugados de un competidor (no cuenta el bye, que no tiene rival), del más
     * reciente al más viejo. Trae los puntos de los dos lados: quién ganó se decide por ellos.
     *
     * @param int[] $equipoIds los equipos del participante
     */
    public function recientesDe(int $participanteId, array $equipoIds, int $limite): array
    {
        [$esSuyo, $params] = $this->esDelCompetidor($participanteId, $equipoIds);
        return $this->fetchAll(
            "SELECT c.id, c.torneo_id, c.torneo_nombre, c.ronda_numero, c.ronda_nombre, c.orden,
                    c.participante_a_id, c.participante_b_id, c.equipo_a_id, c.equipo_b_id, c.lado_a, c.lado_b,
                    c.puntos_a, c.puntos_b,
                    COALESCE(c.fecha_fin_real, c.updated_at) AS fecha
             FROM v_calendario c
             WHERE c.estado = 'finalizado'
               AND c.es_bye = 0
               AND c.puntos_a IS NOT NULL
               AND c.lado_a IS NOT NULL AND c.lado_b IS NOT NULL
               AND {$esSuyo}
             ORDER BY fecha DESC, c.id DESC"
            . $this->limiteDe($limite, 0),
            $params
        );
    }

    /**
     * Todos los partidos de un competidor en un torneo, del más reciente al más viejo (por ronda). Sirve
     * para saber cómo va en una llave: si sigue en carrera o en qué ronda quedó afuera.
     *
     * @param int[] $equipoIds los equipos del participante
     */
    public function delCompetidorEnTorneo(int $torneoId, int $participanteId, array $equipoIds): array
    {
        [$esSuyo, $params] = $this->esDelCompetidor($participanteId, $equipoIds);
        $params[':tid'] = $torneoId;
        return $this->fetchAll(
            "SELECT c.id, c.estado, c.es_bye, c.ronda_numero, c.ronda_nombre,
                    c.participante_a_id, c.equipo_a_id,
                    c.ganador_participante_id, c.ganador_equipo_id
             FROM v_calendario c
             WHERE c.torneo_id = :tid AND {$esSuyo}
             ORDER BY c.ronda_numero DESC, c.orden DESC",
            $params
        );
    }

    public function getFinalizadosByTorneo(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT * FROM enfrentamientos
             WHERE torneo_id = :tid AND estado IN ('finalizado','bye')
             ORDER BY id ASC",
            [':tid' => $torneoId]
        );
    }

    public function getByRondaYOrden(int $rondaId, int $orden): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM enfrentamientos WHERE ronda_id = :rid AND orden = :o LIMIT 1",
            [':rid' => $rondaId, ':o' => $orden]
        );
        return $r ?: null;
    }

    /** Historial de enfrentamientos de un torneo (pares de participantes que ya jugaron) */
    public function getHistorialParticipantes(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT participante_a_id, participante_b_id
             FROM enfrentamientos
             WHERE torneo_id = :tid AND es_bye = 0
               AND participante_a_id IS NOT NULL AND participante_b_id IS NOT NULL",
            [':tid' => $torneoId]
        );
    }

    public function getHistorialEquipos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT equipo_a_id, equipo_b_id
             FROM enfrentamientos
             WHERE torneo_id = :tid AND es_bye = 0
               AND equipo_a_id IS NOT NULL AND equipo_b_id IS NOT NULL",
            [':tid' => $torneoId]
        );
    }

    /**
     * Cierra el partido y anota quién ganó y quién perdió. En un EMPATE no hay ni uno ni otro: se
     * pasan null y las columnas quedan en NULL (antes se guardaba al lado A como «ganador», un dato
     * falso que cualquier lista o API que lo leyera habría mostrado como victoria).
     */
    public function updateGanadorParticipante(int $id, ?int $ganadorId, ?int $perdedorId): void
    {
        $this->query(
            "UPDATE enfrentamientos
             SET ganador_participante_id = :g, perdedor_participante_id = :p,
                 estado = 'finalizado',
                 fecha_inicio_real = COALESCE(fecha_inicio_real, NOW()),
                 fecha_fin_real = NOW(), updated_at = NOW()
             WHERE id = :id",
            [':g' => $ganadorId, ':p' => $perdedorId, ':id' => $id]
        );
    }

    /** Lo mismo que updateGanadorParticipante(), para un torneo por equipos. */
    public function updateGanadorEquipo(int $id, ?int $ganadorId, ?int $perdedorId): void
    {
        $this->query(
            "UPDATE enfrentamientos
             SET ganador_equipo_id = :g, perdedor_equipo_id = :p,
                 estado = 'finalizado',
                 fecha_inicio_real = COALESCE(fecha_inicio_real, NOW()),
                 fecha_fin_real = NOW(), updated_at = NOW()
             WHERE id = :id",
            [':g' => $ganadorId, ':p' => $perdedorId, ':id' => $id]
        );
    }

    /** Programa (o reprograma) la fecha/hora de un partido. NULL la limpia. */
    public function programar(int $id, ?string $fechaProgramada): void
    {
        $this->query(
            "UPDATE enfrentamientos SET fecha_programada = :f, updated_at = NOW() WHERE id = :id",
            [':f' => $fechaProgramada, ':id' => $id]
        );
    }

    /** Cantidad de partidos con fecha programada fuera del rango [iniDate 00:00, finDate 23:59]. */
    public function contarProgramadosFueraDeRango(int $torneoId, string $iniDate, string $finDate): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM enfrentamientos
             WHERE torneo_id = :t AND fecha_programada IS NOT NULL
               AND (fecha_programada < :ini OR fecha_programada > :fin)",
            [':t' => $torneoId, ':ini' => $iniDate . ' 00:00:00', ':fin' => $finDate . ' 23:59:59']
        );
    }

    /** Comprueba si el ganador ya participó en rondas posteriores (para bloquear corrección) */
    public function ganadorYaAvanzó(int $enfId, int $torneoId): bool
    {
        $enf = $this->findById($enfId);
        if (!$enf) return false;

        $ronda = $this->fetchOne(
            "SELECT numero FROM rondas WHERE id = :rid",
            [':rid' => $enf['ronda_id']]
        );
        if (!$ronda) return false;

        // Verificar si el ganador aparece en enfrentamientos de rondas posteriores
        $ganadorPart  = $enf['ganador_participante_id'];
        $ganadorEquipo = $enf['ganador_equipo_id'];

        // Nota: con PDO::ATTR_EMULATE_PREPARES=false no se puede reusar un
        // placeholder con nombre; por eso se usan :ga y :gb (mismo valor).
        if ($ganadorPart) {
            $cnt = $this->fetchColumn(
                "SELECT COUNT(*) FROM enfrentamientos enf
                 JOIN rondas r ON r.id = enf.ronda_id
                 WHERE enf.torneo_id = :tid
                   AND r.numero > :rn
                   AND (enf.participante_a_id = :ga OR enf.participante_b_id = :gb)",
                [':tid' => $torneoId, ':rn' => $ronda['numero'], ':ga' => $ganadorPart, ':gb' => $ganadorPart]
            );
            return (int)$cnt > 0;
        }

        if ($ganadorEquipo) {
            $cnt = $this->fetchColumn(
                "SELECT COUNT(*) FROM enfrentamientos enf
                 JOIN rondas r ON r.id = enf.ronda_id
                 WHERE enf.torneo_id = :tid
                   AND r.numero > :rn
                   AND (enf.equipo_a_id = :ga OR enf.equipo_b_id = :gb)",
                [':tid' => $torneoId, ':rn' => $ronda['numero'], ':ga' => $ganadorEquipo, ':gb' => $ganadorEquipo]
            );
            return (int)$cnt > 0;
        }

        return false;
    }
}
