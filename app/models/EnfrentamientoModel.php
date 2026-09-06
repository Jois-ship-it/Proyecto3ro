<?php
declare(strict_types=1);

class EnfrentamientoModel extends BaseModel
{
    protected string $table = 'enfrentamientos';

    public function getByRonda(int $rondaId): array
    {
        return $this->fetchAll(
            "SELECT enf.*,
                    pa.nombre AS participante_a_nombre, pa.nick AS nick_a,
                    pb.nombre AS participante_b_nombre, pb.nick AS nick_b,
                    ea.nombre AS equipo_a_nombre,
                    eb.nombre AS equipo_b_nombre
             FROM enfrentamientos enf
             LEFT JOIN participantes pa ON pa.id = enf.participante_a_id
             LEFT JOIN participantes pb ON pb.id = enf.participante_b_id
             LEFT JOIN equipos ea ON ea.id = enf.equipo_a_id
             LEFT JOIN equipos eb ON eb.id = enf.equipo_b_id
             WHERE enf.ronda_id = :rid
             ORDER BY enf.orden ASC",
            [':rid' => $rondaId]
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

    public function updateGanadorParticipante(int $id, int $ganadorId, int $perdedorId): void
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

    public function updateGanadorEquipo(int $id, int $ganadorId, int $perdedorId): void
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
