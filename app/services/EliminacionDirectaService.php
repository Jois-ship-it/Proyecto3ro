<?php
declare(strict_types=1);

class EliminacionDirectaService
{
    private TorneoModel         $torneoModel;
    private InscripcionModel    $insModel;
    private RondaModel          $rondaModel;
    private EnfrentamientoModel $enfModel;
    private ResultadoModel      $resModel;
    private AuditoriaService    $auditoria;

    public function __construct()
    {
        $this->torneoModel = new TorneoModel();
        $this->insModel    = new InscripcionModel();
        $this->rondaModel  = new RondaModel();
        $this->enfModel    = new EnfrentamientoModel();
        $this->resModel    = new ResultadoModel();
        $this->auditoria   = new AuditoriaService();
    }

    /**
     * Genera el bracket de eliminación directa.
     * Soporta cantidades no potencia de 2 mediante byes automáticos.
     */
    public function generarBracket(int $torneoId): void
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');

        $inscripciones = $this->insModel->getByTorneo($torneoId);
        $n = count($inscripciones);
        if ($n < 2)   throw new RuntimeException('Se necesitan al menos 2 inscritos.');
        if ($n > 128) throw new RuntimeException('La Eliminación Directa admite hasta 128 inscritos.');

        $esEquipos = $torneo['modalidad'] === 'equipos';
        $ids = array_map(
            fn($i) => $esEquipos ? (int)$i['equipo_id'] : (int)$i['participante_id'],
            $inscripciones
        );
        shuffle($ids); // Distribución aleatoria

        // Potencia de 2 más cercana hacia arriba
        $potencia = 1;
        while ($potencia < $n) $potencia *= 2;

        // Completar con nulls para los byes
        while (count($ids) < $potencia) $ids[] = null;

        $nombreRonda = $this->getNombreRonda($potencia);
        $rondaId = $this->rondaModel->insert([
            'torneo_id' => $torneoId,
            'numero'    => 1,
            'nombre'    => $nombreRonda,
            'estado'    => 'en_curso',
        ]);

        $mitad = $potencia / 2;
        $byes  = []; // byes a avanzar en segunda pasada

        for ($i = 0; $i < $mitad; $i++) {
            $a = $ids[$i];
            $b = $ids[$potencia - 1 - $i];

            $esBye = ($b === null);
            $enf = [
                'torneo_id' => $torneoId,
                'ronda_id'  => $rondaId,
                'estado'    => $esBye ? 'bye' : 'pendiente',
                'es_bye'    => $esBye ? 1 : 0,
                'orden'     => $i + 1,
            ];
            if ($esEquipos) {
                $enf['equipo_a_id'] = $a;
                if (!$esBye) $enf['equipo_b_id'] = $b;
            } else {
                $enf['participante_a_id'] = $a;
                if (!$esBye) $enf['participante_b_id'] = $b;
            }

            $enfId = $this->enfModel->insert($enf);

            // Registrar el resultado del bye (sin avanzar todavía)
            if ($esBye && $a !== null) {
                $this->registrarResultadoBye($enfId, $a, $esEquipos);
                $byes[] = ['enfId' => $enfId, 'ganador' => $a];
            }
        }

        $this->torneoModel->updateEstado($torneoId, 'en_curso');

        // Segunda pasada: avanzar a los ganadores de byes. Se hace ahora que
        // TODOS los partidos de la ronda 1 existen, para que avanzarGanador
        // cuente correctamente la ronda y los ubique en la ronda siguiente.
        foreach ($byes as $bye) {
            $this->avanzarGanador($bye['enfId'], $bye['ganador'], $esEquipos);
        }

        $this->auditoria->log('generar_bracket', 'torneos', $torneoId, "Bracket Eliminación Directa generado");
    }

    /** Registra el resultado de un bye (avance automático, sin rival). */
    private function registrarResultadoBye(int $enfId, int $ganadorId, bool $esEquipos): void
    {
        $resultado = [
            'enfrentamiento_id' => $enfId,
            'puntos_a'          => 0,
            'puntos_b'          => 0,
            'estado'            => 'cargado',
            'cargado_por'       => Auth::id(),
        ];
        if ($esEquipos) {
            $resultado['ganador_equipo_id'] = $ganadorId;
        } else {
            $resultado['ganador_participante_id'] = $ganadorId;
        }
        $this->resModel->insert($resultado);

        $this->auditoria->log('asignar_bye', 'enfrentamientos', $enfId, "Bye asignado al participante/equipo {$ganadorId}");
    }

    /**
     * Avanza al ganador a la siguiente ronda.
     * Se llama tras cargar un resultado en Eliminación Directa.
     */
    public function avanzarGanador(int $enfrentamientoId, int $ganadorId, bool $esEquipos): void
    {
        $enf   = $this->enfModel->findById($enfrentamientoId);
        $ronda = $this->rondaModel->findById((int)$enf['ronda_id']);

        // Contar partidos en la ronda actual
        $matchesEnRonda = $this->enfModel->getByRonda((int)$ronda['id']);
        $totalMatches   = count($matchesEnRonda);

        // Si solo hay 1 partido en la ronda (es la final)
        if ($totalMatches === 1) {
            if ($esEquipos) {
                $this->torneoModel->setCampeonEquipo((int)$enf['torneo_id'], $ganadorId);
            } else {
                $this->torneoModel->setCampeonParticipante((int)$enf['torneo_id'], $ganadorId);
            }
            $this->auditoria->log('definir_campeon', 'torneos', (int)$enf['torneo_id'], "Campeón definido: {$ganadorId}");
            return;
        }

        // Buscar o crear la siguiente ronda
        $siguienteNum  = (int)$ronda['numero'] + 1;
        $siguienteRonda = $this->rondaModel->getByTorneoYNumero((int)$enf['torneo_id'], $siguienteNum);

        if (!$siguienteRonda) {
            $siguienteRondaId = $this->rondaModel->insert([
                'torneo_id' => $enf['torneo_id'],
                'numero'    => $siguienteNum,
                'nombre'    => $this->getNombreRondaSiguiente($totalMatches),
                'estado'    => 'pendiente',
            ]);
        } else {
            $siguienteRondaId = (int)$siguienteRonda['id'];
        }

        // Determinar slot en la siguiente ronda (emparejamiento por bracket)
        $ordenActual   = (int)$enf['orden'];
        $slotSiguiente = (int)ceil($ordenActual / 2);

        $matchSiguiente = $this->enfModel->getByRondaYOrden($siguienteRondaId, $slotSiguiente);

        if (!$matchSiguiente) {
            // Crear slot con el ganador como participante A
            $nuevo = [
                'torneo_id' => $enf['torneo_id'],
                'ronda_id'  => $siguienteRondaId,
                'estado'    => 'pendiente',
                'es_bye'    => 0,
                'orden'     => $slotSiguiente,
            ];
            if ($esEquipos) {
                $nuevo['equipo_a_id'] = $ganadorId;
            } else {
                $nuevo['participante_a_id'] = $ganadorId;
            }
            $this->enfModel->insert($nuevo);
        } else {
            // Completar con el ganador como participante B
            if ($esEquipos) {
                $this->enfModel->update((int)$matchSiguiente['id'], ['equipo_b_id' => $ganadorId]);
            } else {
                $this->enfModel->update((int)$matchSiguiente['id'], ['participante_b_id' => $ganadorId]);
            }
        }

        $this->auditoria->log('avanzar_ganador', 'enfrentamientos', $enfrentamientoId, "Ganador {$ganadorId} avanzó a ronda {$siguienteNum}");
    }

    public function puedeCorregir(int $enfrentamientoId, int $torneoId): bool
    {
        return !$this->enfModel->ganadorYaAvanzó($enfrentamientoId, $torneoId);
    }

    private function getNombreRonda(int $potencia): string
    {
        return match ($potencia) {
            2  => 'Final',
            4  => 'Semifinales',
            8  => 'Cuartos de Final',
            16 => 'Octavos de Final',
            32 => 'Dieciseisavos de Final',
            default => 'Ronda de ' . ($potencia / 2),
        };
    }

    private function getNombreRondaSiguiente(int $matchesEnRondaActual): string
    {
        return match ($matchesEnRondaActual) {
            2  => 'Final',
            4  => 'Semifinales',
            8  => 'Cuartos de Final',
            16 => 'Octavos de Final',
            default => 'Siguiente Ronda',
        };
    }
}
