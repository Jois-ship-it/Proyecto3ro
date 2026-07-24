<?php
declare(strict_types=1);

class SistemaSuizoService
{
    private TorneoModel          $torneoModel;
    private InscripcionModel     $insModel;
    private RondaModel           $rondaModel;
    private EnfrentamientoModel  $enfModel;
    private ResultadoModel       $resModel;
    private TablaPosicionesModel $tablaModel;
    private TablaPosicionesService $tablaService;
    private AuditoriaService     $auditoria;

    public function __construct()
    {
        $this->torneoModel  = new TorneoModel();
        $this->insModel     = new InscripcionModel();
        $this->rondaModel   = new RondaModel();
        $this->enfModel     = new EnfrentamientoModel();
        $this->resModel     = new ResultadoModel();
        $this->tablaModel   = new TablaPosicionesModel();
        $this->tablaService = new TablaPosicionesService();
        $this->auditoria    = new AuditoriaService();
    }

    /**
     * Genera la primera ronda del torneo suizo.
     * Emparejamiento por split: top mitad vs bottom mitad.
     */
    public function generarPrimeraRonda(int $torneoId): void
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');
        if ($this->rondaModel->countByTorneo($torneoId) > 0) {
            throw new RuntimeException('Ya existe una ronda generada para este torneo.');
        }

        $inscripciones = $this->insModel->getByTorneo($torneoId);
        $n = count($inscripciones);
        if ($n < 2)   throw new RuntimeException('Se necesitan al menos 2 inscritos.');
        if ($n > 256) throw new RuntimeException('El Sistema Suizo admite hasta 256 inscritos.');

        $esEquipos = $torneo['modalidad'] === 'equipos';
        $ids = array_map(
            fn($i) => $esEquipos ? (int)$i['equipo_id'] : (int)$i['participante_id'],
            $inscripciones
        );

        $rondaId = $this->rondaModel->insert([
            'torneo_id' => $torneoId,
            'numero'    => 1,
            'nombre'    => 'Ronda 1',
            'estado'    => 'en_curso',
        ]);

        // Determinar bye si cantidad impar
        $byeId = null;
        if ($n % 2 !== 0) {
            $byeId = array_pop($ids); // El último recibe bye en primera ronda
            $n--;
        }

        // Split-pairing: top vs bottom
        $mitad  = $n / 2;
        $top    = array_slice($ids, 0, $mitad);
        $bottom = array_slice($ids, $mitad);

        for ($i = 0; $i < $mitad; $i++) {
            $this->crearEnfrentamiento($torneoId, $rondaId, $top[$i], $bottom[$i], $esEquipos, $i + 1);
        }

        if ($byeId !== null) {
            $this->asignarBye($torneoId, $rondaId, $byeId, $esEquipos, $mitad + 1, $torneo);
        }

        $this->torneoModel->updateEstado($torneoId, 'en_curso');
        $this->tablaService->recalcular($torneoId);
        $this->auditoria->log('generar_ronda_suiza', 'torneos', $torneoId, "Ronda 1 (Suizo) generada para torneo {$torneoId}");
    }

    /**
     * Genera la siguiente ronda del torneo suizo.
     * Solo se puede generar si la ronda anterior está completa.
     */
    public function generarSiguienteRonda(int $torneoId): void
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');

        $rondasJugadas = $this->rondaModel->countByTorneo($torneoId);
        $totalRondas   = (int)$torneo['rondas_suizo'];

        if ($rondasJugadas >= $totalRondas) {
            throw new RuntimeException("Ya se completaron todas las rondas ({$totalRondas}) del torneo.");
        }

        if (!$this->rondaModel->ultimaRondaCompleta($torneoId)) {
            throw new RuntimeException('No se puede generar la siguiente ronda: hay partidos pendientes en la ronda anterior.');
        }

        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Obtener ranking actual
        $ranking = $this->tablaModel->getByTorneo($torneoId);
        $ids = array_map(
            fn($r) => $esEquipos ? (int)$r['equipo_id'] : (int)$r['participante_id'],
            $ranking
        );
        $byesRecibidos = array_column($ranking, 'byes_recibidos', $esEquipos ? 'equipo_id' : 'participante_id');

        // Obtener historial de enfrentamientos (pares que ya jugaron)
        $historial = $esEquipos
            ? $this->enfModel->getHistorialEquipos($torneoId)
            : $this->enfModel->getHistorialParticipantes($torneoId);

        $yaEnfrentados = [];
        foreach ($historial as $h) {
            $aKey = $esEquipos ? $h['equipo_a_id'] : $h['participante_a_id'];
            $bKey = $esEquipos ? $h['equipo_b_id'] : $h['participante_b_id'];
            $yaEnfrentados[$aKey][$bKey] = true;
            $yaEnfrentados[$bKey][$aKey] = true;
        }

        // Determinar bye si impar
        $byeId = null;
        $n     = count($ids);
        if ($n % 2 !== 0) {
            // Preferir quien tenga menos byes y menor puntaje (está al final del ranking)
            for ($i = $n - 1; $i >= 0; $i--) {
                if (($byesRecibidos[$ids[$i]] ?? 0) === 0) {
                    $byeId = $ids[$i];
                    array_splice($ids, $i, 1);
                    break;
                }
            }
            if ($byeId === null) {
                $byeId = array_pop($ids);
            }
            $n--;
        }

        // Emparejar por ranking (Dutch pairing simplificado)
        $pares = $this->emparejar($ids, $yaEnfrentados);

        $siguienteNum = $rondasJugadas + 1;
        $rondaId = $this->rondaModel->insert([
            'torneo_id' => $torneoId,
            'numero'    => $siguienteNum,
            'nombre'    => 'Ronda ' . $siguienteNum,
            'estado'    => 'en_curso',
        ]);

        foreach ($pares as $idx => $par) {
            $this->crearEnfrentamiento($torneoId, $rondaId, $par[0], $par[1], $esEquipos, $idx + 1);
        }

        if ($byeId !== null) {
            $this->asignarBye($torneoId, $rondaId, $byeId, $esEquipos, count($pares) + 1, $torneo);
        }

        $this->auditoria->log('generar_ronda_suiza', 'torneos', $torneoId, "Ronda {$siguienteNum} (Suizo) generada para torneo {$torneoId}");
    }

    /**
     * Verifica si el torneo suizo debe finalizar y declara campeón.
     *
     * Desempate: si los dos primeros puestos terminan empatados en TODAS las
     * métricas, se genera un nuevo partido de desempate entre ellos. El partido
     * de desempate suma a la tabla como uno más, de modo que un ganador rompe el
     * empate (queda 1.º = campeón) y un nuevo empate dispara otro desempate.
     * El proceso se repite sin límite hasta que exista un ganador real.
     */
    public function intentarFinalizar(int $torneoId): bool
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);

        // Deben haberse generado todas las rondas suizas configuradas (sin contar desempates).
        $rondasSuizas = $this->rondaModel->countByTorneo($torneoId)
                      - $this->rondaModel->contarDesempates($torneoId);
        if ($rondasSuizas < (int)$torneo['rondas_suizo']) return false;

        // Todos los partidos finalizados (incluye un eventual desempate en curso).
        if (!$this->rondaModel->ultimaRondaCompleta($torneoId)) return false;

        $tabla = $this->tablaModel->getByTorneo($torneoId);
        if (empty($tabla)) return false;

        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Empate exacto entre los dos primeros → generar (otro) partido de desempate.
        if ($this->hayEmpateEnCima($tabla)) {
            $this->crearRondaDesempate($torneoId, $tabla[0], $tabla[1], $esEquipos);
            return false;
        }

        // Hay un líder claro → campeón.
        $campeon = $tabla[0];
        if ($esEquipos) {
            $this->torneoModel->setCampeonEquipo($torneoId, (int)$campeon['equipo_id']);
        } else {
            $this->torneoModel->setCampeonParticipante($torneoId, (int)$campeon['participante_id']);
        }
        $this->auditoria->log('finalizar_torneo', 'torneos', $torneoId, "Torneo Suizo {$torneoId} finalizado");
        return true;
    }

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

    // ─── Helpers privados ─────────────────────────────────────

    private function emparejar(array $ids, array $yaEnfrentados): array
    {
        $n = count($ids);
        $usados = array_fill(0, $n, false);
        $pares  = [];

        for ($i = 0; $i < $n; $i++) {
            if ($usados[$i]) continue;

            $emparejado = false;

            // Intentar emparejar con el siguiente disponible que no haya enfrentado
            for ($j = $i + 1; $j < $n; $j++) {
                if ($usados[$j]) continue;
                if (isset($yaEnfrentados[$ids[$i]][$ids[$j]])) continue; // ya jugaron

                $pares[]    = [$ids[$i], $ids[$j]];
                $usados[$i] = $usados[$j] = true;
                $emparejado = true;
                break;
            }

            // Float: si no se encontró rival ideal, forzar con el siguiente disponible
            if (!$emparejado) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($usados[$j]) continue;
                    $pares[]    = [$ids[$i], $ids[$j]];
                    $usados[$i] = $usados[$j] = true;
                    break;
                }
            }
        }

        return $pares;
    }

    private function crearEnfrentamiento(int $torneoId, int $rondaId, int $a, int $b, bool $esEquipos, int $orden): void
    {
        $enf = [
            'torneo_id' => $torneoId,
            'ronda_id'  => $rondaId,
            'estado'    => 'pendiente',
            'es_bye'    => 0,
            'orden'     => $orden,
        ];
        if ($esEquipos) {
            $enf['equipo_a_id'] = $a;
            $enf['equipo_b_id'] = $b;
        } else {
            $enf['participante_a_id'] = $a;
            $enf['participante_b_id'] = $b;
        }
        $this->enfModel->insert($enf);
    }

    private function asignarBye(int $torneoId, int $rondaId, int $participanteId, bool $esEquipos, int $orden, array $torneo): void
    {
        $enf = [
            'torneo_id' => $torneoId,
            'ronda_id'  => $rondaId,
            'estado'    => 'bye',
            'es_bye'    => 1,
            'orden'     => $orden,
        ];
        if ($esEquipos) {
            $enf['equipo_a_id'] = $participanteId;
        } else {
            $enf['participante_a_id'] = $participanteId;
        }
        $enfId = $this->enfModel->insert($enf);

        // Registrar resultado del bye automáticamente
        $puntosA = match ($torneo['bye_suizo'] ?? 'sin_puntos') {
            'victoria'      => (float)$torneo['puntos_victoria'],
            'personalizado' => (float)$torneo['puntos_bye_suizo'],
            default         => 0.0,
        };

        $res = [
            'enfrentamiento_id' => $enfId,
            'puntos_a'          => $puntosA,
            'puntos_b'          => 0,
            'estado'            => 'cargado',
            'cargado_por'       => Auth::id() ?? null,
        ];
        if ($esEquipos) {
            $res['ganador_equipo_id'] = $participanteId;
        } else {
            $res['ganador_participante_id'] = $participanteId;
        }
        $this->resModel->insert($res);

        $this->auditoria->log('asignar_bye', 'enfrentamientos', $enfId, "Bye asignado (Suizo): {$participanteId}");
    }
}
