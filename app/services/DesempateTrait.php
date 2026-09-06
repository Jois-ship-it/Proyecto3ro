<?php
declare(strict_types=1);

/**
 * Lógica de desempate compartida entre LigaService y SistemaSuizoService.
 * Requiere que la clase que la usa tenga las propiedades $rondaModel
 * (RondaModel), $enfModel (EnfrentamientoModel) y $auditoria (AuditoriaService).
 */
trait DesempateTrait
{
    /** ¿Los dos primeros puestos están empatados en todas las métricas de orden? */
    private function hayEmpateEnCima(array $tabla): bool
    {
        if (count($tabla) < 2) return false;
        $a = $tabla[0];
        $b = $tabla[1];
        return (int)$a['puntos']     === (int)$b['puntos']
            && (int)$a['diferencia'] === (int)$b['diferencia']
            && (int)$a['pf']         === (int)$b['pf']
            && (int)$a['pg']         === (int)$b['pg']
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

        $this->auditoria->log('crear_desempate', 'torneos', $torneoId,
            "Desempate {$numDesempate} creado — torneo {$torneoId}");
    }
}
