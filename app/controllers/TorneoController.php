<?php
declare(strict_types=1);

class TorneoController extends BaseController
{
    private TorneoService      $torneoService;
    private InscripcionService $inscripcionService;
    private TipoTorneoModel    $tipoModel;
    private ConfiguracionTorneoService $configService;

    public function __construct()
    {
        $this->torneoService      = new TorneoService();
        $this->inscripcionService = new InscripcionService();
        $this->tipoModel          = new TipoTorneoModel();
        $this->configService      = new ConfiguracionTorneoService();
    }

    public function listadoAdmin(): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'ver');
        // Un organizador solo ve SUS torneos desde este listado; el admin, todos
        // (misma regla que OrganizadorController::misTorneos()).
        $torneos = Auth::isAdmin()
            ? $this->torneoService->getAll()
            : $this->torneoService->getByOrganizador((int)Auth::id());
        $this->render('admin/torneos', [
            'pageTitle' => 'Torneos',
            'torneos'   => $torneos,
        ], 'admin');
    }

    public function formulario(string $id = ''): void
    {
        $this->requireOrganizador();
        // Editar: solo el dueño (o el admin). Crear: solo el admin — según la letra
        // del proyecto, "crear y configurar torneos" es función del administrador;
        // el organizador únicamente "configura torneos asignados".
        if ($id) {
            $this->requirePermiso('torneos', 'editar', '/admin/torneos');
            $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        } elseif (!Auth::isAdmin()) {
            $this->flash('error', 'Solo un administrador puede crear torneos nuevos.');
            $this->redirect('/admin/torneos');
        }
        $torneo = $id ? $this->torneoService->getById((int)$id) : null;

        // Solo usuarios que pueden organizar (organizador o administrador)
        $organizadores = array_values(array_filter(
            (new UsuarioModel())->findAllConRol(),
            fn($u) => in_array($u['rol_nombre'], ['organizador', 'administrador'], true)
                      && $u['estado'] === 'activo'
        ));

        $this->render('admin/torneo_form', [
            'pageTitle'        => $torneo ? 'Editar torneo' : 'Nuevo torneo',
            'torneo'           => $torneo,
            // Solo formatos con módulo habilitado; si se está editando, se conserva
            // el formato actual del torneo aunque su módulo esté apagado.
            'tipos'            => $this->tipoModel->findDisponibles(
                                      $torneo ? (int)$torneo['tipo_torneo_id'] : null
                                  ),
            'organizadores'    => $organizadores,
            'organizadorActual'=> $torneo ? ($torneo['organizador_id'] ?? null) : null,
            // Datos del evento (tabla configuraciones_torneo). En un torneo nuevo
            // vienen las claves vacias, para que la vista no tenga que preguntar.
            'configuracion'    => $torneo
                                    ? $this->configService->getPorTorneo((int)$torneo['id'])
                                    : $this->configService->vacio(),
            'clavesConfig'     => ConfiguracionTorneoService::CLAVES,
            'csrf'             => Csrf::generate(),
        ], 'admin');
    }

    public function guardar(string $id = ''): void
    {
        $this->requireOrganizador();
        $torneoActual = $id ? $this->torneoService->getById((int)$id) : null;
        if ($id) {
            $this->requirePermiso('torneos', 'editar', '/admin/torneos');
            $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        } elseif (!Auth::isAdmin()) {
            $this->flash('error', 'Solo un administrador puede crear torneos nuevos.');
            $this->redirect('/admin/torneos');
        }
        $this->checkCsrf();
        try {
            $datos = [
                'nombre'                   => $this->postStr('nombre'),
                'descripcion'              => $this->postStr('descripcion'),
                'tipo_torneo_id'           => $this->postInt('tipo_torneo_id'),
                'modalidad'                => $this->postStr('modalidad', 'individual'),
                'min_integrantes_equipo'   => $this->postInt('min_integrantes_equipo'),
                // El organizador_id solo puede definirse/reasignarse por un administrador.
                // Si edita el propio organizador (ya validado como dueño), se conserva el actual.
                'organizador_id'           => Auth::isAdmin()
                    ? $this->postInt('organizador_id')
                    : (int)($torneoActual['organizador_id'] ?? 0),
                'estado'                   => $this->postStr('estado', 'borrador'),
                'fecha_inicio'             => $this->postStr('fecha_inicio'),
                'fecha_fin'                => $this->postStr('fecha_fin'),
                'publico'                  => $this->post('publico'),
                'permite_empates'          => $this->post('permite_empates'),
                'puntos_victoria'          => $this->postInt('puntos_victoria', 3),
                'puntos_empate'            => $this->postInt('puntos_empate', 1),
                'puntos_derrota'           => $this->postInt('puntos_derrota', 0),
                'usa_puntos_favor'         => $this->post('usa_puntos_favor'),
                'requiere_desempate_final' => $this->post('requiere_desempate_final'),
                'rondas_suizo'             => $this->post('rondas_suizo'),
                'bye_suizo'                => $this->postStr('bye_suizo', 'sin_puntos'),
                'puntos_bye_suizo'         => $this->postFloat('puntos_bye_suizo', 0),
                'nombre_puntos'            => $this->postStr('nombre_puntos', 'puntos'),
                'creado_por'               => Auth::id(),
            ];

            // Datos del evento: llegan del mismo formulario, en su propio array.
            // Se validan ANTES de tocar el torneo: el formulario es uno solo, asi
            // que un contacto mal escrito no puede dejar el torneo guardado y los
            // datos del evento sin guardar.
            $configEnviada = is_array($this->post('config')) ? $this->post('config') : [];
            $this->configService->validar($configEnviada, $datos);

            if ($id) {
                $this->torneoService->editar((int)$id, $datos);
                $this->configService->guardar((int)$id, $configEnviada, $datos);
                $this->flash('success', 'Torneo actualizado correctamente.');
            } else {
                // El organizador se asocia dentro del servicio (clave organizador_id)
                $nuevoId = $this->torneoService->crear($datos);
                $this->configService->guardar($nuevoId, $configEnviada, $datos);
                $this->flash('success', 'Torneo creado correctamente.');
            }
            $this->redirect('/admin/torneos');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($id ? "/admin/torneos/editar/{$id}" : '/admin/torneos/crear');
        }
    }

    public function gestion(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'ver');
        $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        $torneo = $this->torneoService->getById((int)$id);
        if (!$torneo) {
            $this->flash('error', 'Torneo no encontrado.');
            $this->redirect('/admin/torneos');
        }

        $tipo        = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
        $inscritos   = $this->inscripcionService->getByTorneo((int)$id);
        $rondaModel  = new RondaModel();
        $rondas      = $rondaModel->getByTorneo((int)$id);
        $enfModel    = new EnfrentamientoModel();

        $rondasConPartidos = [];
        foreach ($rondas as $ronda) {
            $rondasConPartidos[] = [
                'ronda'    => $ronda,
                'partidos' => $enfModel->getByRonda((int)$ronda['id']),
            ];
        }

        // Rondas de desempate (no cuentan como rondas suizas regulares).
        $rondasDesempate    = array_filter($rondas, fn($r) => str_starts_with((string)$r['nombre'], 'Desempate'));
        $rondasRegulares    = count($rondas) - count($rondasDesempate);
        $desempatePendiente = !empty($rondasDesempate) && $torneo['estado'] !== 'finalizado';

        $tabla = [];
        if (in_array($tipo['slug'], ['liga', 'suizo'], true)) {
            $tabla = (new TablaPosicionesService())->getByTorneo((int)$id);
        }

        $participantesDisponibles = [];
        $equiposDisponibles       = [];
        if (in_array($torneo['estado'], ['borrador', 'inscripcion'])) {
            if ($torneo['modalidad'] === 'individual') {
                $participantesDisponibles = (new ParticipanteModel())->findNoInscritos((int)$id);
            } else {
                $equiposDisponibles = (new EquipoModel())->findNoInscritos((int)$id);
            }
        }

        $this->render('admin/torneo_gestion', [
            'pageTitle'               => $torneo['nombre'],
            'torneo'                  => $torneo,
            'tipo'                    => $tipo,
            'inscritos'               => $inscritos,
            'rondasConPartidos'       => $rondasConPartidos,
            'tabla'                   => $tabla,
            'participantesDisponibles'=> $participantesDisponibles,
            'equiposDisponibles'      => $equiposDisponibles,
            'ultimaRondaCompleta'     => $rondaModel->ultimaRondaCompleta((int)$id),
            'totalRondasJugadas'      => $rondasRegulares,
            'desempatePendiente'      => $desempatePendiente,
            'csrf'                    => Csrf::generate(),
            'extraJs'                 => ['combobox.js'],
        ], 'admin');
    }

    public function generarCompetencia(string $id): void
    {
        $this->requireOrganizador();
        $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        $this->checkCsrf();
        $torneo = $this->torneoService->getById((int)$id);
        if (!$torneo) { $this->flash('error', 'Torneo no encontrado.'); $this->redirect('/admin/torneos'); }

        // Letra §5.2 «generar rondas o llaves»: el permiso se pide sobre el
        // modulo del formato concreto (ver OrganizadorController).
        $tipo = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
        if (!$tipo) {
            $this->flash('error', 'El torneo no tiene un formato valido asignado.');
            $this->redirect("/admin/torneos/{$id}");
        }
        $this->requirePermiso((string)$tipo['slug'], 'crear', "/admin/torneos/{$id}");

        try {
            match ($tipo['slug']) {
                'liga'               => (new LigaService())->generarFixture((int)$id),
                'eliminacion_directa'=> (new EliminacionDirectaService())->generarBracket((int)$id),
                'suizo'              => (new SistemaSuizoService())->generarPrimeraRonda((int)$id),
                default              => throw new RuntimeException("Formato desconocido: {$tipo['slug']}"),
            };
            $this->flash('success', 'Competencia generada correctamente.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/torneos/{$id}");
    }

    public function siguienteRondaSuizo(string $id): void
    {
        $this->requireOrganizador();
        $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        $this->checkCsrf();
        try {
            (new SistemaSuizoService())->generarSiguienteRonda((int)$id);
            $this->flash('success', 'Siguiente ronda generada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/torneos/{$id}");
    }

    // ─── Cerrar / reabrir rondas ─────────────────────────────
    // Facultad del organizador según el §5.2 de la letra ("publicar o cerrar
    // rondas"). Comparten implementación entre el panel de admin y el de
    // organizador, igual que la carga de resultados: la ruta cambia, la regla no.

    public function cerrarRonda(string $id): void
    {
        $this->accionSobreRonda((int)$id, 'cerrar');
    }

    public function reabrirRonda(string $id): void
    {
        $this->accionSobreRonda((int)$id, 'reabrir');
    }

    /** Tronco común de cerrar/reabrir: propiedad del torneo, CSRF y vuelta al panel. */
    private function accionSobreRonda(int $rondaId, string $accion): void
    {
        $this->requireOrganizador();
        // Letra §5.2: «publicar o cerrar rondas».
        $this->requirePermiso('torneos', 'editar', $this->volverATorneos());

        $rondaService = new RondaService();
        $ronda        = $rondaService->getById($rondaId);
        if (!$ronda) {
            $this->flash('error', 'Ronda no encontrada.');
            $this->redirect($this->volverATorneos());
        }

        $torneoId = (int) $ronda['torneo_id'];
        // El organizador solo puede tocar rondas de SUS torneos; el admin, todas.
        $this->requireTorneoOwnership($torneoId, $this->volverATorneos());
        $this->checkCsrf();

        try {
            if ($accion === 'cerrar') {
                $rondaService->cerrar($rondaId, Auth::id());
                $this->flash('success', "Ronda «{$ronda['nombre']}» cerrada.");
            } else {
                $rondaService->reabrir($rondaId, Auth::id());
                $this->flash('success', "Ronda «{$ronda['nombre']}» reabierta.");
            }
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->volverAlTorneo($torneoId));
    }

    /** Panel al que corresponde volver según desde dónde se entró. */
    private function panelBase(): string
    {
        return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/organizador')
            ? '/organizador'
            : '/admin';
    }

    private function volverATorneos(): string
    {
        return $this->panelBase() . '/torneos';
    }

    private function volverAlTorneo(int $torneoId): string
    {
        return $this->panelBase() . '/torneos/' . $torneoId;
    }

    public function inscribir(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'editar', "/admin/torneos/{$id}");
        $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        $this->checkCsrf();
        try {
            $participanteId = $this->postInt('participante_id');
            $equipoId       = $this->postInt('equipo_id');
            if ($participanteId) {
                $this->inscripcionService->inscribirParticipante((int)$id, $participanteId);
                $this->flash('success', 'Participante inscrito.');
            } elseif ($equipoId) {
                $this->inscripcionService->inscribirEquipo((int)$id, $equipoId);
                $this->flash('success', 'Equipo inscrito.');
            }
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/torneos/{$id}");
    }

    public function desinscribir(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'editar', "/admin/torneos/{$id}");
        $this->requireTorneoOwnership((int)$id, '/admin/torneos');
        $this->checkCsrf();
        try {
            $participanteId = $this->postInt('participante_id') ?: null;
            $equipoId       = $this->postInt('equipo_id') ?: null;
            $this->inscripcionService->desinscribir((int)$id, $participanteId, $equipoId);
            $this->flash('success', 'Inscripción retirada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/torneos/{$id}");
    }


    public function eliminar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->torneoService->eliminar((int)$id);
            $this->flash('success', 'Torneo cancelado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/torneos');
    }
}
