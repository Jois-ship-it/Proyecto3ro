<?php
declare(strict_types=1);

class TablaPosicionesService
{
    private TablaPosicionesModel $model;
    private EnfrentamientoModel  $enfModel;
    private ResultadoModel       $resModel;
    private InscripcionModel     $insModel;
    private TorneoModel          $torneoModel;

    public function __construct()
    {
        $this->model       = new TablaPosicionesModel();
        $this->enfModel    = new EnfrentamientoModel();
        $this->resModel    = new ResultadoModel();
        $this->insModel    = new InscripcionModel();
        $this->torneoModel = new TorneoModel();
    }

    public function getByTorneo(int $torneoId): array
    {
        return $this->model->getByTorneo($torneoId);
    }

    /**
     * Recalcula COMPLETAMENTE la tabla de posiciones desde cero.
     * Se llama tras cada carga o corrección de resultado.
     */
    public function recalcular(int $torneoId): void
    {
        $torneo        = $this->torneoModel->findByIdCompleto($torneoId);
        $inscripciones = $this->insModel->getByTorneo($torneoId);

        if (empty($inscripciones)) return;

        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Inicializar acumuladores
        $tabla = [];
        foreach ($inscripciones as $ins) {
            $key = $esEquipos ? 'e_' . $ins['equipo_id'] : 'p_' . $ins['participante_id'];
            $tabla[$key] = [
                'torneo_id'       => $torneoId,
                'participante_id' => $esEquipos ? null : (int)$ins['participante_id'],
                'equipo_id'       => $esEquipos ? (int)$ins['equipo_id'] : null,
                'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0,
                'pf' => 0, 'pc' => 0, 'diferencia' => 0, 'puntos' => 0,
                'byes_recibidos' => 0, 'buchholz' => 0, 'posicion' => 0,
            ];
        }

        // Procesar enfrentamientos finalizados
        $enfrentamientos = $this->enfModel->getFinalizadosByTorneo($torneoId);

        foreach ($enfrentamientos as $enf) {
            $keyA = $esEquipos ? 'e_' . $enf['equipo_a_id'] : 'p_' . $enf['participante_a_id'];

            if ($enf['es_bye']) {
                if (!isset($tabla[$keyA])) continue;
                $tabla[$keyA]['byes_recibidos']++;
                $tabla[$keyA]['pj']++;
                // Puntos por bye según config del torneo
                $tabla[$keyA]['puntos'] += $this->puntosbye($torneo, $enf);
                continue;
            }

            $keyB = $esEquipos ? 'e_' . $enf['equipo_b_id'] : 'p_' . $enf['participante_b_id'];
            if (!isset($tabla[$keyA]) || !isset($tabla[$keyB])) continue;

            $resultado = $this->resModel->getByEnfrentamiento((int)$enf['id']);
            if (!$resultado) continue;

            $pA = (float)$resultado['puntos_a'];
            $pB = (float)$resultado['puntos_b'];

            $tabla[$keyA]['pj']++;
            $tabla[$keyB]['pj']++;
            $tabla[$keyA]['pf'] += (int)$pA;
            $tabla[$keyA]['pc'] += (int)$pB;
            $tabla[$keyB]['pf'] += (int)$pB;
            $tabla[$keyB]['pc'] += (int)$pA;

            if ($pA > $pB) {
                $tabla[$keyA]['pg']++;
                $tabla[$keyA]['puntos'] += (int)$torneo['puntos_victoria'];
                $tabla[$keyB]['pp']++;
                $tabla[$keyB]['puntos'] += (int)$torneo['puntos_derrota'];
            } elseif ($pA < $pB) {
                $tabla[$keyB]['pg']++;
                $tabla[$keyB]['puntos'] += (int)$torneo['puntos_victoria'];
                $tabla[$keyA]['pp']++;
                $tabla[$keyA]['puntos'] += (int)$torneo['puntos_derrota'];
            } else {
                $tabla[$keyA]['pe']++;
                $tabla[$keyA]['puntos'] += (int)$torneo['puntos_empate'];
                $tabla[$keyB]['pe']++;
                $tabla[$keyB]['puntos'] += (int)$torneo['puntos_empate'];
            }
        }

        // Calcular diferencia y buchholz
        foreach ($tabla as $key => &$row) {
            $row['diferencia'] = $row['pf'] - $row['pc'];
            // Buchholz = suma de puntos de los rivales
            $row['buchholz'] = $this->calcularBuchholz($torneoId, $row, $tabla, $esEquipos);
        }
        unset($row);

        // Ordenar: puntos → diferencia → pf → pg → buchholz → id
        uasort($tabla, function (array $a, array $b): int {
            if ($a['puntos'] !== $b['puntos'])     return $b['puntos']     - $a['puntos'];
            if ($a['diferencia'] !== $b['diferencia']) return $b['diferencia'] - $a['diferencia'];
            if ($a['pf'] !== $b['pf'])             return $b['pf']         - $a['pf'];
            if ($a['pg'] !== $b['pg'])             return $b['pg']         - $a['pg'];
            if ($a['buchholz'] !== $b['buchholz']) return (int)($b['buchholz'] - $a['buchholz']);
            $idA = $a['participante_id'] ?? $a['equipo_id'] ?? 0;
            $idB = $b['participante_id'] ?? $b['equipo_id'] ?? 0;
            return $idA - $idB;
        });

        // Asignar posiciones y persistir
        $pos = 1;
        $this->model->deleteByTorneo($torneoId);
        foreach ($tabla as $row) {
            $row['posicion'] = $pos++;
            $this->model->upsert($row);
        }
    }

    private function puntosbye(array $torneo, array $enf): int
    {
        return match ($torneo['bye_suizo'] ?? 'sin_puntos') {
            'victoria'    => (int)$torneo['puntos_victoria'],
            'personalizado' => (int)$torneo['puntos_bye_suizo'],
            default       => 0,
        };
    }

    private function calcularBuchholz(int $torneoId, array $row, array $tabla, bool $esEquipos): float
    {
        // Para cada rival enfrentado, sumar sus puntos actuales
        $idPropio = $esEquipos ? $row['equipo_id'] : $row['participante_id'];
        $historial = $esEquipos
            ? $this->enfModel->getHistorialEquipos($torneoId)
            : $this->enfModel->getHistorialParticipantes($torneoId);

        $buchholz = 0.0;
        foreach ($historial as $h) {
            $aId = (int)($esEquipos ? $h['equipo_a_id'] : $h['participante_a_id']);
            $bId = (int)($esEquipos ? $h['equipo_b_id'] : $h['participante_b_id']);

            $rivalId = null;
            if ($aId === $idPropio) $rivalId = $bId;
            if ($bId === $idPropio) $rivalId = $aId;
            if (!$rivalId) continue;

            $rivalKey = ($esEquipos ? 'e_' : 'p_') . $rivalId;
            if (isset($tabla[$rivalKey])) {
                $buchholz += (float)$tabla[$rivalKey]['puntos'];
            }
        }
        return $buchholz;
    }
}
