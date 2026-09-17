<?php
declare(strict_types=1);

class ResultadoService
{
    private ResultadoModel      $resModel;
    private EnfrentamientoModel $enfModel;
    private TorneoModel         $torneoModel;
    private TipoTorneoModel     $tipoModel;
    private TablaPosicionesService $tablaService;
    private LigaService           $ligaService;
    private EliminacionDirectaService $eliminacionService;
    private SistemaSuizoService   $suizoService;
    private RondaService          $rondaService;
    private AuditoriaService      $auditoria;

    public function __construct()
    {
        $this->resModel           = new ResultadoModel();
        $this->enfModel           = new EnfrentamientoModel();
        $this->torneoModel        = new TorneoModel();
        $this->tipoModel          = new TipoTorneoModel();
        $this->tablaService       = new TablaPosicionesService();
        $this->ligaService        = new LigaService();
        $this->eliminacionService = new EliminacionDirectaService();
        $this->suizoService       = new SistemaSuizoService();
        $this->rondaService       = new RondaService();
        $this->auditoria          = new AuditoriaService();
    }

    /**
     * Carga el resultado de un enfrentamiento.
     * Valida, persiste, recalcula y audita.
     */
    public function cargar(int $enfrentamientoId, float $puntosA, float $puntosB, int $cargadoPor): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new RuntimeException('Enfrentamiento no encontrado.');

        if ($enf['estado'] === 'finalizado') {
            throw new RuntimeException('Este partido ya tiene resultado. Usá "Corregir resultado" para modificarlo.');
        }
        if (!in_array($enf['estado'], ['pendiente', 'en_curso'], true)) {
            throw new RuntimeException('No se puede cargar resultado para un partido en estado: ' . $enf['estado']);
        }

        // Una ronda cerrada (a mano por el organizador) no admite carga.
        $this->rondaService->assertAbiertaParaCarga($enfrentamientoId);

        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);

        if ($torneo['estado'] !== 'en_curso') {
            throw new RuntimeException('Solo se pueden cargar resultados en torneos en curso.');
        }

        if ($puntosA < 0 || $puntosB < 0) {
            throw new RuntimeException('Los puntos no pueden ser negativos.');
        }

        // Validar empates según configuración y tipo de ronda.
        if ($puntosA === $puntosB) {
            $ronda       = (new RondaModel())->findById((int)$enf['ronda_id']);
            $esDesempate = $ronda && str_starts_with((string)($ronda['nombre'] ?? ''), 'Desempate');

            if ($tipo['slug'] === 'eliminacion_directa') {
                throw new RuntimeException('Los empates no están permitidos en Eliminación Directa.');
            }
            // En Liga/Suizo un empate en un partido de desempate es válido: dispara otro desempate.
            if (!$esDesempate && !$torneo['permite_empates']) {
                throw new RuntimeException('Este torneo no permite empates. Cargá un resultado con un ganador.');
            }
        }

        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Determinar ganador y perdedor
        [$ganadorPart, $perdedorPart, $ganadorEquipo, $perdedorEquipo] = $this->determinarGanador(
            $enf, $puntosA, $puntosB, $esEquipos
        );

        // Persistir resultado
        $resData = [
            'enfrentamiento_id' => $enfrentamientoId,
            'puntos_a'          => $puntosA,
            'puntos_b'          => $puntosB,
            'estado'            => 'cargado',
            'cargado_por'       => $cargadoPor,
            'ganador_participante_id' => $ganadorPart,
            'ganador_equipo_id'       => $ganadorEquipo,
        ];

        // Todo el flujo (persistir resultado + recalcular tabla + avanzar/finalizar)
        // se ejecuta dentro de una transacción para mantener la consistencia.
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $resId = $this->resModel->insert($resData);

            // Actualizar estado del enfrentamiento
            if ($esEquipos) {
                $this->enfModel->updateGanadorEquipo($enfrentamientoId, (int)$ganadorEquipo, (int)$perdedorEquipo);
            } else {
                $this->enfModel->updateGanadorParticipante($enfrentamientoId, (int)$ganadorPart, (int)$perdedorPart);
            }

            // Acciones específicas por formato
            $slug = $tipo['slug'];
            if ($slug === 'liga') {
                $this->tablaService->recalcular((int)$torneo['id']);
                $this->ligaService->intentarFinalizar((int)$torneo['id']);
            } elseif ($slug === 'eliminacion_directa') {
                $ganadorId = $esEquipos ? (int)$ganadorEquipo : (int)$ganadorPart;
                $this->eliminacionService->avanzarGanador($enfrentamientoId, $ganadorId, $esEquipos);
            } elseif ($slug === 'suizo') {
                $this->tablaService->recalcular((int)$torneo['id']);
                $this->suizoService->intentarFinalizar((int)$torneo['id']);
            }

            // El estado de las rondas se recalcula al final, cuando ya se sabe si
            // este resultado completó la ronda o si se generó una nueva.
            $this->rondaService->sincronizarTorneo((int)$torneo['id']);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->auditoria->log('cargar_resultado', 'resultados', $resId,
            "Resultado cargado — Enfrentamiento {$enfrentamientoId}: {$puntosA}-{$puntosB}");
    }

    /**
     * El motivo por el que este partido ya no se puede corregir, o null si se puede.
     *
     * La regla depende del formato: en Eliminación Directa el bracket ya ubicó al
     * ganador en la ronda siguiente, y en Suizo el emparejamiento de la ronda
     * siguiente se armó con esos puntajes. Revertir cualquiera de las dos cosas
     * es rehacer el torneo desde ahí. La Liga no tiene el problema: el fixture
     * está completo desde el arranque y la tabla se recalcula entera.
     *
     * Es pública y devuelve el motivo en vez de lanzar porque la consultan DOS
     * caminos: el que aplica la corrección (corregir()) y el que la pide
     * (CorreccionService::solicitar()). Cuando solo la miraba el primero, se
     * registraban solicitudes que nunca se iban a poder aprobar.
     */
    public function motivoBloqueoCorreccion(int $enfrentamientoId): ?string
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) return null;   // que no exista lo reporta quien llama

        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);

        if ($tipo['slug'] === 'eliminacion_directa'
            && !$this->eliminacionService->puedeCorregir($enfrentamientoId, (int)$torneo['id'])) {
            return 'No se puede modificar este resultado porque ya generó una ronda posterior. La corrección requeriría revertir el bracket.';
        }

        if ($tipo['slug'] === 'suizo') {
            $rondaModel  = new RondaModel();
            $ronda       = $rondaModel->findById((int)$enf['ronda_id']);
            $ultimaRonda = $rondaModel->getUltimaByTorneo((int)$torneo['id']);
            if ($ronda && $ultimaRonda && (int)$ronda['numero'] < (int)$ultimaRonda['numero']) {
                return 'No se puede modificar este resultado porque ya se generó una ronda posterior.';
            }
        }

        return null;
    }

    /**
     * Corrige un resultado ya cargado.
     */
    public function corregir(int $enfrentamientoId, float $puntosA, float $puntosB, string $motivo, int $usuarioId): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new RuntimeException('Enfrentamiento no encontrado.');
        if ($enf['estado'] !== 'finalizado') {
            throw new RuntimeException('Solo se pueden corregir partidos finalizados.');
        }

        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
        $slug   = $tipo['slug'];

        $bloqueo = $this->motivoBloqueoCorreccion($enfrentamientoId);
        if ($bloqueo !== null) throw new RuntimeException($bloqueo);

        if (empty($motivo)) throw new RuntimeException('El motivo de la corrección es obligatorio.');
        if ($puntosA < 0 || $puntosB < 0) throw new RuntimeException('Los puntos no pueden ser negativos.');

        $resActual = $this->resModel->getByEnfrentamiento($enfrentamientoId);
        if (!$resActual) throw new RuntimeException('Resultado original no encontrado.');

        $esEquipos = $torneo['modalidad'] === 'equipos';
        [$ganadorPart, $perdedorPart, $ganadorEquipo, $perdedorEquipo] = $this->determinarGanador(
            $enf, $puntosA, $puntosB, $esEquipos
        );

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Guardar corrección
            $this->resModel->update((int)$resActual['id'], [
                'puntos_a'               => $puntosA,
                'puntos_b'               => $puntosB,
                'ganador_participante_id' => $ganadorPart,
                'ganador_equipo_id'      => $ganadorEquipo,
                'estado'                 => 'corregido',
                'corregido'              => 1,
                'motivo_correccion'      => $motivo,
                'resultado_anterior_a'   => $resActual['puntos_a'],
                'resultado_anterior_b'   => $resActual['puntos_b'],
                'fecha_correccion'       => date('Y-m-d H:i:s'),
                'usuario_correccion_id'  => $usuarioId,
            ]);

            // Actualizar ganador en el enfrentamiento
            if ($esEquipos) {
                $this->enfModel->updateGanadorEquipo($enfrentamientoId, (int)$ganadorEquipo, (int)$perdedorEquipo);
            } else {
                $this->enfModel->updateGanadorParticipante($enfrentamientoId, (int)$ganadorPart, (int)$perdedorPart);
            }

            // Recalcular según formato
            if (in_array($slug, ['liga', 'suizo'], true)) {
                $this->tablaService->recalcular((int)$torneo['id']);
            }

            $this->rondaService->sincronizarTorneo((int)$torneo['id']);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->auditoria->log('corregir_resultado', 'resultados', (int)$resActual['id'],
            "Resultado corregido — Enfrentamiento {$enfrentamientoId}: {$puntosA}-{$puntosB} | Motivo: {$motivo}",
            ['puntos_a' => $resActual['puntos_a'], 'puntos_b' => $resActual['puntos_b']],
            ['puntos_a' => $puntosA, 'puntos_b' => $puntosB],
            $usuarioId
        );
    }

    /**
     * Programa o reprograma la fecha/hora de un partido (no aplica a byes ni finalizados).
     */
    public function programar(int $enfrentamientoId, ?string $fechaProgramada, int $usuarioId): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new RuntimeException('Enfrentamiento no encontrado.');
        if ((int)$enf['es_bye'] === 1) throw new RuntimeException('No se puede programar un bye.');
        if (in_array($enf['estado'], ['finalizado', 'cancelado'], true)) {
            throw new RuntimeException('No se puede programar un partido finalizado o cancelado.');
        }

        $fecha = null;
        if (!empty($fechaProgramada)) {
            $ts = strtotime($fechaProgramada);
            if ($ts === false) throw new RuntimeException('La fecha programada no es válida.');
            // La fecha debe caer dentro del rango del torneo.
            $torneo = $this->torneoModel->findById((int)$enf['torneo_id']);
            self::assertFechaEnRangoTorneo($ts, $torneo);
            $fecha = date('Y-m-d H:i:s', $ts);
        }

        $this->enfModel->programar($enfrentamientoId, $fecha);
        $this->auditoria->log('programar_partido', 'enfrentamientos', $enfrentamientoId,
            "Partido {$enfrentamientoId} programado: " . ($fecha ?? 'sin fecha'));
    }

    /**
     * Verifica que un timestamp caiga dentro del rango de fechas del torneo
     * (inclusive, jornada completa 00:00–23:59). Lanza RuntimeException si no.
     * Si el torneo no tiene fechas definidas (datos heredados), no valida.
     */
    public static function assertFechaEnRangoTorneo(int $ts, ?array $torneo): void
    {
        if (!$torneo) return;
        $ini = $torneo['fecha_inicio'] ?? null;
        $fin = $torneo['fecha_fin'] ?? null;
        if (empty($ini) || empty($fin)) return;

        $iniTs = strtotime($ini . ' 00:00:00');
        $finTs = strtotime($fin . ' 23:59:59');
        if ($ts < $iniTs || $ts > $finTs) {
            if ($ini === $fin) {
                throw new RuntimeException(
                    'Los torneos de una sola jornada solo permiten programar partidos en la fecha del torneo (' .
                    date('d/m/Y', $iniTs) . ').'
                );
            }
            throw new RuntimeException(
                'La fecha del partido debe estar dentro del rango del torneo (' .
                date('d/m/Y', $iniTs) . ' a ' . date('d/m/Y', $finTs) . ').'
            );
        }
    }

    private function determinarGanador(array $enf, float $pA, float $pB, bool $esEquipos): array
    {
        if ($esEquipos) {
            $aId = (int)$enf['equipo_a_id'];
            $bId = (int)$enf['equipo_b_id'];
            if ($pA > $pB)  return [null, null, $aId, $bId];
            if ($pB > $pA)  return [null, null, $bId, $aId];
            // Empate (en elim directa ya se validó que no hay empates)
            return [null, null, $aId, $bId];
        } else {
            $aId = (int)$enf['participante_a_id'];
            $bId = (int)$enf['participante_b_id'];
            if ($pA > $pB)  return [$aId, $bId, null, null];
            if ($pB > $pA)  return [$bId, $aId, null, null];
            return [$aId, $bId, null, null];
        }
    }
}
