<?php
declare(strict_types=1);

class ResultadoService
{
    /** Largo mínimo del motivo de una corrección: lo promete el formulario (minlength) y lo exige el servidor, venga la corrección de donde venga. */
    public const MOTIVO_CORRECCION_MINIMO = 10;

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
        if (!$enf) throw new ErrorNegocio('Enfrentamiento no encontrado.');

        if ($enf['estado'] === 'finalizado') {
            throw new ErrorNegocio('Este partido ya tiene resultado. Usá "Corregir resultado" para modificarlo.');
        }
        if (!in_array($enf['estado'], ['pendiente', 'en_curso'], true)) {
            throw new ErrorNegocio('No se puede cargar resultado para un partido en estado: ' . $enf['estado']);
        }

        // Una ronda cerrada (a mano por el organizador) no admite carga.
        $this->rondaService->assertAbiertaParaCarga($enfrentamientoId);

        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);

        if ($torneo['estado'] !== 'en_curso') {
            throw new ErrorNegocio('Solo se pueden cargar resultados en torneos en curso.');
        }

        if ($puntosA < 0 || $puntosB < 0) {
            throw new ErrorNegocio('Los puntos no pueden ser negativos.');
        }

        // Validar empates según configuración y tipo de ronda.
        $noEmpate = $this->motivoEmpateNoPermitido($enf, $torneo, $tipo, $puntosA, $puntosB);
        if ($noEmpate !== null) throw new ErrorNegocio($noEmpate);

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

        // Todo el flujo (persistir resultado + recalcular tabla + avanzar/finalizar +
        // auditar) se ejecuta dentro de una transacción para mantener la consistencia:
        // no queda un resultado cargado sin su rastro, ni un rastro de algo que no pasó.
        Database::transaccion(function () use (
            $resData, $enfrentamientoId, $esEquipos, $ganadorEquipo, $perdedorEquipo,
            $ganadorPart, $perdedorPart, $tipo, $torneo, $puntosA, $puntosB
        ) {
            $resId = $this->resModel->insert($resData);

            // Actualizar estado del enfrentamiento (en un empate, sin ganador ni perdedor)
            if ($esEquipos) {
                $this->enfModel->updateGanadorEquipo($enfrentamientoId, $ganadorEquipo, $perdedorEquipo);
            } else {
                $this->enfModel->updateGanadorParticipante($enfrentamientoId, $ganadorPart, $perdedorPart);
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

            $this->auditoria->log('cargar_resultado', 'resultados', $resId,
                "Resultado cargado — Enfrentamiento {$enfrentamientoId}: {$puntosA}-{$puntosB}");
        });
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
     * Aplicar una corrección es cosa del administrador: el organizador la PIDE y el administrador
     * la aprueba (CorreccionService::aprobar() llama a este método con el id del revisor). Se
     * comprueba acá, con el usuario que actúa, y no solo en el controlador: así ninguna ruta
     * nueva puede saltearse el flujo de aprobación.
     */
    private function asegurarQueEsAdministrador(int $usuarioId): void
    {
        $usuario = (new UsuarioModel())->findByIdConRol($usuarioId);
        if (!$usuario || $usuario['rol_nombre'] !== 'administrador') {
            throw new ErrorNegocio('Solo un administrador puede aplicar una corrección. Pedila con «Solicitar corrección» y un administrador la va a revisar.');
        }
    }

    /**
     * Corrige un resultado ya cargado.
     *
     * Si el torneo ya estaba finalizado, la corrección puede cambiar quién lo ganó: se vuelve a
     * decidir el campeón (ver `reevaluarCampeon()` de cada formato). Devuelve un aviso para quien
     * corrige cuando eso cambió algo (otro campeón, o dos empatados en la cima que reabren el
     * torneo con un desempate), o null si no cambió nada.
     */
    public function corregir(int $enfrentamientoId, float $puntosA, float $puntosB, string $motivo, int $usuarioId): ?string
    {
        $this->asegurarQueEsAdministrador($usuarioId);

        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new ErrorNegocio('Enfrentamiento no encontrado.');
        if ($enf['estado'] !== 'finalizado') {
            throw new ErrorNegocio('Solo se pueden corregir partidos finalizados.');
        }

        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
        $slug   = $tipo['slug'];

        $bloqueo = $this->motivoBloqueoCorreccion($enfrentamientoId);
        if ($bloqueo !== null) throw new ErrorNegocio($bloqueo);

        // Antes bastaba con que no estuviera vacío: el formulario del administrador pedía 10 caracteres pero el servidor aceptaba «x».
        if (mb_strlen(trim($motivo)) < self::MOTIVO_CORRECCION_MINIMO) {
            throw new ErrorNegocio('El motivo es obligatorio (mínimo ' . self::MOTIVO_CORRECCION_MINIMO . ' caracteres).');
        }
        if ($puntosA < 0 || $puntosB < 0) throw new ErrorNegocio('Los puntos no pueden ser negativos.');

        // Corregir no es una vía para saltear lo que cargar() no deja pasar: un empate donde no vale.
        $noEmpate = $this->motivoEmpateNoPermitido($enf, $torneo, $tipo, $puntosA, $puntosB);
        if ($noEmpate !== null) throw new ErrorNegocio($noEmpate);

        $resActual = $this->resModel->getByEnfrentamiento($enfrentamientoId);
        if (!$resActual) throw new ErrorNegocio('Resultado original no encontrado.');

        $esEquipos = $torneo['modalidad'] === 'equipos';
        [$ganadorPart, $perdedorPart, $ganadorEquipo, $perdedorEquipo] = $this->determinarGanador(
            $enf, $puntosA, $puntosB, $esEquipos
        );

        // Corrección + recálculo + (re)decisión del campeón + auditoría: todo o nada.
        return Database::transaccion(function () use (
            $resActual, $puntosA, $puntosB, $ganadorPart, $ganadorEquipo, $motivo, $usuarioId,
            $esEquipos, $enfrentamientoId, $perdedorEquipo, $perdedorPart, $slug, $torneo
        ) {
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

            // Actualizar ganador en el enfrentamiento (en un empate, sin ganador ni perdedor)
            if ($esEquipos) {
                $this->enfModel->updateGanadorEquipo($enfrentamientoId, $ganadorEquipo, $perdedorEquipo);
            } else {
                $this->enfModel->updateGanadorParticipante($enfrentamientoId, $ganadorPart, $perdedorPart);
            }

            // Recalcular según formato y, si el torneo ya tenía campeón, volver a decidirlo: antes
            // la corrección dejaba la tabla al día y el campeón como estaba, aunque ya no fuera el primero.
            $aviso = null;
            $finalizado = $torneo['estado'] === 'finalizado';
            if (in_array($slug, ['liga', 'suizo'], true)) {
                $this->tablaService->recalcular((int)$torneo['id']);
                if ($finalizado) {
                    $aviso = $slug === 'liga'
                        ? $this->ligaService->reevaluarCampeon((int)$torneo['id'])
                        : $this->suizoService->reevaluarCampeon((int)$torneo['id']);
                }
            } elseif ($slug === 'eliminacion_directa' && $finalizado) {
                $ganadorId = $esEquipos ? (int)$ganadorEquipo : (int)$ganadorPart;
                $aviso = $this->eliminacionService->reevaluarCampeon($enfrentamientoId, $ganadorId, $esEquipos);
            }

            $this->rondaService->sincronizarTorneo((int)$torneo['id']);

            $this->auditoria->log('corregir_resultado', 'resultados', (int)$resActual['id'],
                "Resultado corregido — Enfrentamiento {$enfrentamientoId}: {$puntosA}-{$puntosB} | Motivo: {$motivo}",
                ['puntos_a' => $resActual['puntos_a'], 'puntos_b' => $resActual['puntos_b']],
                ['puntos_a' => $puntosA, 'puntos_b' => $puntosB],
                $usuarioId
            );
            return $aviso;
        });
    }

    /**
     * Por qué un empate NO vale en este partido, o null si vale (o si el marcador no es un empate).
     *
     * Eliminación Directa nunca admite empates. En Liga y Suizo valen si el torneo los permite; un
     * partido de desempate los admite siempre (un nuevo empate dispara otro desempate). Es la regla
     * de cargar(), y ahora también la de corregir() y la de pedir una corrección.
     */
    private function motivoEmpateNoPermitido(array $enf, array $torneo, array $tipo, float $puntosA, float $puntosB): ?string
    {
        if ($puntosA !== $puntosB) return null;

        if ($tipo['slug'] === 'eliminacion_directa') {
            return 'Los empates no están permitidos en Eliminación Directa.';
        }
        $ronda       = (new RondaModel())->findById((int)$enf['ronda_id']);
        $esDesempate = $ronda && str_starts_with((string)($ronda['nombre'] ?? ''), 'Desempate');
        if (!$esDesempate && !$torneo['permite_empates']) {
            return 'Este torneo no permite empates. Cargá un resultado con un ganador.';
        }
        return null;
    }

    /**
     * Lo mismo, para quien solo tiene el id del partido (pedir una corrección): null si el marcador
     * pedido vale o si el partido no existe (que no exista lo avisa quien llama).
     */
    public function motivoMarcadorNoPermitido(int $enfrentamientoId, float $puntosA, float $puntosB): ?string
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) return null;
        $torneo = $this->torneoModel->findByIdCompleto((int)$enf['torneo_id']);
        $tipo   = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
        return $this->motivoEmpateNoPermitido($enf, $torneo, $tipo, $puntosA, $puntosB);
    }

    /**
     * Programa o reprograma la fecha/hora de un partido (no aplica a byes ni finalizados).
     */
    public function programar(int $enfrentamientoId, ?string $fechaProgramada, int $usuarioId): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new ErrorNegocio('Enfrentamiento no encontrado.');
        if ((int)$enf['es_bye'] === 1) throw new ErrorNegocio('No se puede programar un bye.');
        if (in_array($enf['estado'], ['finalizado', 'cancelado'], true)) {
            throw new ErrorNegocio('No se puede programar un partido finalizado o cancelado.');
        }

        $fecha = null;
        if (!empty($fechaProgramada)) {
            $ts = strtotime($fechaProgramada);
            if ($ts === false) throw new ErrorNegocio('La fecha programada no es válida.');
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
     * (inclusive, jornada completa 00:00–23:59). Lanza ErrorNegocio si no.
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
                throw new ErrorNegocio(
                    'Los torneos de una sola jornada solo permiten programar partidos en la fecha del torneo (' .
                    date('d/m/Y', $iniTs) . ').'
                );
            }
            throw new ErrorNegocio(
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
            // Un empate no tiene ganador ni perdedor. (Antes devolvía al lado A como ganador: un dato
            // falso que ninguna cuenta usaba, porque la tabla se calcula por los puntos, pero que el
            // reporte y la API habrían mostrado como victoria.)
            return [null, null, null, null];
        } else {
            $aId = (int)$enf['participante_a_id'];
            $bId = (int)$enf['participante_b_id'];
            if ($pA > $pB)  return [$aId, $bId, null, null];
            if ($pB > $pA)  return [$bId, $aId, null, null];
            return [null, null, null, null];
        }
    }
}
