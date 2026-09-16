<?php
declare(strict_types=1);

/**
 * Lógica de desempate compartida entre LigaService y SistemaSuizoService.
 * Requiere que la clase que la usa tenga las propiedades $rondaModel
 * (RondaModel), $enfModel (EnfrentamientoModel) y $auditoria (AuditoriaService).
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
