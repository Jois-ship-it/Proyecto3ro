<?php
declare(strict_types=1);

class SistemaSuizoService
{
    use DesempateTrait;
    use ModuloActivoTrait;

    /** Tope de nodos explorados por intento de emparejamiento (salvaguarda de tiempo). */
    private const MAX_NODOS_EMPAREJAMIENTO = 50000;

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
        // La ronda 1 es el arranque del torneo: si el módulo está deshabilitado no
        // se empieza. generarSiguienteRonda() a propósito NO tiene esta guarda: un
        // suizo ya arrancado tiene que poder completar sus rondas y coronar campeón.
        $this->assertModuloActivo('suizo');

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
        $ids = InscripcionModel::idsDesdeInscripciones($inscripciones, $esEquipos);

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
        // Las rondas recién creadas arrancan con el estado que les corresponde
        // (ver RondaService: 'en_curso' si son jugables, 'pendiente' si les faltan cruces).
        (new RondaService())->sincronizarTorneo($torneoId);
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

        // Las rondas recién creadas arrancan con el estado que les corresponde
        // (ver RondaService: 'en_curso' si son jugables, 'pendiente' si les faltan cruces).
        (new RondaService())->sincronizarTorneo($torneoId);

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
        if ($this->hayEmpateEnCima($tabla, $torneo)) {
            $this->crearRondaDesempate($torneoId, $tabla[0], $tabla[1], $esEquipos);
            return false;
        }

        // Hay un líder claro → campeón.
        $campeon = $tabla[0];
        $this->torneoModel->setCampeon($esEquipos, $torneoId, (int)($esEquipos ? $campeon['equipo_id'] : $campeon['participante_id']));
        $this->auditoria->log('finalizar_torneo', 'torneos', $torneoId, "Torneo Suizo {$torneoId} finalizado");
        return true;
    }

    // ─── Helpers privados ─────────────────────────────────────

    /**
     * Empareja la ronda minimizando revanchas.
     *
     * $ids llega ordenado por ranking (1.º primero). El barrido codicioso anterior
     * emparejaba de a uno sin poder deshacer decisiones: cuando los dos últimos
     * que quedaban libres ya se habían enfrentado, el "float" forzaba la revancha
     * aunque bastara reacomodar un par anterior para evitarla.
     *
     * Ahora se busca con backtracking y límite creciente de revanchas: primero se
     * intenta un emparejamiento perfecto con CERO revanchas y solo si se demuestra
     * que no existe se admite una, después dos, etc. Así la revancha queda como
     * último recurso demostrado, no como efecto colateral del orden de barrido.
     *
     * Dentro de cada intento el recorrido respeta el ranking (el jugador libre mejor
     * ubicado se prueba primero contra el rival disponible más cercano), de modo que
     * cuando no hace falta deshacer nada el resultado es el mismo emparejamiento
     * estilo suizo de antes.
     */
    private function emparejar(array $ids, array $yaEnfrentados): array
    {
        $n = count($ids);
        if ($n < 2) return [];

        $maxRevanchas = intdiv($n, 2);
        for ($limite = 0; $limite <= $maxRevanchas; $limite++) {
            $usados = array_fill(0, $n, false);
            $pares  = [];
            $nodos  = 0;

            if ($this->buscarEmparejamiento($ids, $yaEnfrentados, $usados, $pares, 0, $limite, $nodos)) {
                return $pares;
            }
        }

        // Con $limite == n/2 cualquier combinación es válida y la primera rama de la
        // búsqueda ya la encuentra, así que llegar acá significa un error de lógica.
        throw new RuntimeException('No se pudo emparejar la ronda: ningún emparejamiento completo encontrado.');
    }

    /**
     * Backtracking sobre el emparejamiento.
     *
     * Toma el primer id libre a partir de $desde (el mejor ubicado sin rival) y lo
     * prueba contra cada id libre posterior en orden de ranking. Las revanchas solo
     * se usan mientras queden disponibles en $revanchasDisponibles.
     *
     * @param array $usados Marca por índice, se modifica y restaura en cada rama.
     * @param array $pares  Acumulador del emparejamiento en construcción.
     * @param int   $nodos  Contador de nodos explorados (salvaguarda de tiempo).
     */
    private function buscarEmparejamiento(
        array $ids,
        array $yaEnfrentados,
        array &$usados,
        array &$pares,
        int $desde,
        int $revanchasDisponibles,
        int &$nodos
    ): bool {
        $n = count($ids);

        while ($desde < $n && $usados[$desde]) $desde++;
        if ($desde >= $n) return true; // todos emparejados

        // Tope defensivo: si un caso patológico agota la búsqueda, se abandona este
        // límite de revanchas y se reintenta con uno mayor (peor pero siempre resuelve).
        if (++$nodos > self::MAX_NODOS_EMPAREJAMIENTO) return false;

        $usados[$desde] = true;

        for ($j = $desde + 1; $j < $n; $j++) {
            if ($usados[$j]) continue;

            $esRevancha = isset($yaEnfrentados[$ids[$desde]][$ids[$j]]);
            if ($esRevancha && $revanchasDisponibles === 0) continue;

            $usados[$j] = true;
            $pares[]    = [$ids[$desde], $ids[$j]];

            $ok = $this->buscarEmparejamiento(
                $ids, $yaEnfrentados, $usados, $pares,
                $desde + 1,
                $revanchasDisponibles - ($esRevancha ? 1 : 0),
                $nodos
            );
            if ($ok) return true;

            array_pop($pares);
            $usados[$j] = false;
        }

        $usados[$desde] = false;
        return false;
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
