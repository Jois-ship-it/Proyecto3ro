<?php
declare(strict_types=1);

/**
 * Lógica de desempate compartida entre LigaService y SistemaSuizoService.
 * Requiere que la clase que la usa tenga las propiedades $rondaModel
 * (RondaModel), $enfModel (EnfrentamientoModel), $torneoModel (TorneoModel),
 * $tablaService (TablaPosicionesService) y $auditoria (AuditoriaService).
 */
trait DesempateTrait
{
    /**
     * ¿Los dos primeros puestos están empatados en todo lo que el torneo mira?
     *
     * Usa EXACTAMENTE los mismos criterios que el orden de la tabla
     * (`TablaPosicionesService::comparar()`), sin el id: si el torneo no usa los
     * puntos a favor, dos participantes con distinta diferencia de goles están
     * igual de empatados que si tuvieran la misma, porque ese criterio no cuenta
     * para él. Que el orden y la decisión del campeón usaran criterios distintos
     * sería incoherente: la tabla mostraría un líder que el sistema no reconoce.
     *
     * El id queda afuera a propósito: es el desempate técnico que hace estable al
     * orden, no un criterio deportivo, y un campeonato no puede decidirse por él.
     */
    private function hayEmpateEnCima(array $tabla, ?array $torneo = null): bool
    {
        if (count($tabla) < 2) return false;

        $a = $tabla[0];
        $b = $tabla[1];

        if ((int)$a['puntos'] !== (int)$b['puntos']) return false;

        if (TablaPosicionesService::usaPuntosFavor($torneo)) {
            if ((int)$a['diferencia'] !== (int)$b['diferencia']) return false;
            if ((int)$a['pf']         !== (int)$b['pf'])         return false;
        }

        return (int)$a['pg'] === (int)$b['pg']
            && abs((float)$a['buchholz'] - (float)$b['buchholz']) < 0.01;
    }

    /**
     * Se corrigió un resultado de un torneo YA finalizado y la tabla ya se recalculó: vuelve a decidir
     * el campeón con la misma regla con que se decidió la primera vez.
     *
     *   - Sigue habiendo un líder claro y es el mismo campeón: no cambia nada.
     *   - Hay un líder claro distinto: pasa a ser el campeón (la auditoría guarda el anterior).
     *   - Los dos primeros quedan empatados en todo: nadie es campeón todavía. El torneo vuelve a
     *     «en curso» y se crea el partido de desempate, igual que cuando ese empate aparece mientras
     *     se juega (un campeonato no se decide por el id).
     *
     * Antes la corrección dejaba la tabla al día y el campeón como estaba, así que el reporte, el perfil
     * y la ficha pública podían nombrar a alguien que ya no era el primero.
     *
     * Hay que llamarlo dentro de la transacción de la corrección. Devuelve un aviso para quien corrigió
     * si algo cambió, o null si no.
     */
    public function reevaluarCampeon(int $torneoId): ?string
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo || $torneo['estado'] !== 'finalizado') return null;

        $tabla = $this->tablaService->getByTorneo($torneoId);
        if (empty($tabla)) return null;

        $esEquipos = $torneo['modalidad'] === 'equipos';
        $antes     = (string) ($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? '—');

        if ($this->hayEmpateEnCima($tabla, $torneo)) {
            $this->torneoModel->reabrir($torneoId);
            $this->crearRondaDesempate($torneoId, $tabla[0], $tabla[1], $esEquipos);
            $this->auditoria->log('reabrir_torneo', 'torneos', $torneoId,
                "Torneo {$torneoId} reabierto: una corrección dejó empatados a los dos primeros puestos (campeón anterior: {$antes}).");
            return 'La corrección dejó empatados a los dos primeros puestos: el torneo volvió a «En curso» y se creó un partido de desempate.';
        }

        $lider    = $tabla[0];
        $nuevoId  = (int) ($esEquipos ? $lider['equipo_id'] : $lider['participante_id']);
        $actualId = (int) ($esEquipos ? $torneo['campeon_equipo_id'] : $torneo['campeon_participante_id']);
        if ($nuevoId === $actualId) return null;

        $ahora = (string) ($lider['competidor'] ?? '');
        $this->torneoModel->setCampeon($esEquipos, $torneoId, $nuevoId);
        $this->auditoria->log('cambiar_campeon', 'torneos', $torneoId,
            "Campeón del torneo {$torneoId} cambiado por una corrección: antes {$antes}, ahora {$ahora}.",
            ['campeon' => $antes], ['campeon' => $ahora]);
        return "El campeón cambió: antes {$antes}, ahora {$ahora}.";
    }

    /** Crea una ronda "Desempate N" con un único partido entre los dos empatados. */
    private function crearRondaDesempate(int $torneoId, array $primero, array $segundo, bool $esEquipos): void
    {
        $ultimaRonda  = $this->rondaModel->getUltimaByTorneo($torneoId);
        $nextNum      = $ultimaRonda ? (int)$ultimaRonda['numero'] + 1 : 1;
        $numDesempate = $this->rondaModel->contarDesempates($torneoId) + 1;

        $rondaId = $this->rondaModel->insert([
            'torneo_id' => $torneoId,
            'numero'    => $nextNum,
            'nombre'    => 'Desempate ' . $numDesempate,
            'estado'    => 'en_curso',
        ]);

        $enf = [
            'torneo_id' => $torneoId,
            'ronda_id'  => $rondaId,
            'estado'    => 'pendiente',
            'es_bye'    => 0,
            'orden'     => 1,
        ];
        if ($esEquipos) {
            $enf['equipo_a_id'] = (int)$primero['equipo_id'];
            $enf['equipo_b_id'] = (int)$segundo['equipo_id'];
        } else {
            $enf['participante_a_id'] = (int)$primero['participante_id'];
            $enf['participante_b_id'] = (int)$segundo['participante_id'];
        }
        $this->enfModel->insert($enf);

        // La ronda de desempate nace jugable: que su estado lo refleje.
        (new RondaService())->sincronizarTorneo($torneoId);

        $this->auditoria->log('crear_desempate', 'torneos', $torneoId,
            "Desempate {$numDesempate} creado — torneo {$torneoId}");
    }
}
