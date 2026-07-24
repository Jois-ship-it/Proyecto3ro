<?php
declare(strict_types=1);

class TorneoController extends BaseController
{
    private TorneoService      $torneoService;
    private InscripcionService $inscripcionService;
    private TipoTorneoModel    $tipoModel;

    public function __construct()
    {
        $this->torneoService      = new TorneoService();
        $this->inscripcionService = new InscripcionService();
        $this->tipoModel          = new TipoTorneoModel();
    }

    public function listadoAdmin(): void
    {
        $this->requireOrganizador();
        $this->render('admin/torneos', [
            'pageTitle' => 'Torneos',
            'torneos'   => $this->torneoService->getAll(),
        ], 'admin');
    }

    public function formulario(string $id = ''): void
    {
        $this->requireOrganizador();
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
            'tipos'            => $this->tipoModel->findAll(),
            'organizadores'    => $organizadores,
            'organizadorActual'=> $torneo ? ($torneo['organizador_id'] ?? null) : null,
            'csrf'             => Csrf::generate(),
        ], 'admin');
    }

    public function guardar(string $id = ''): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();
        try {
            $datos = [
                'nombre'                   => $this->postStr('nombre'),
                'descripcion'              => $this->postStr('descripcion'),
                'tipo_torneo_id'           => $this->postInt('tipo_torneo_id'),
                'modalidad'                => $this->postStr('modalidad', 'individual'),
                'min_integrantes_equipo'   => $this->postInt('min_integrantes_equipo'),
                'organizador_id'           => $this->postInt('organizador_id'),
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

            if ($id) {
                $this->torneoService->editar((int)$id, $datos);
                $this->flash('success', 'Torneo actualizado correctamente.');
            } else {
                // El organizador se asocia dentro del servicio (clave organizador_id)
                $this->torneoService->crear($datos);
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
        ], 'admin');
    }

    public function generarCompetencia(string $id): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();
        $torneo = $this->torneoService->getById((int)$id);
        if (!$torneo) { $this->flash('error', 'Torneo no encontrado.'); $this->redirect('/admin/torneos'); }

        try {
            $tipo = $this->tipoModel->findById((int)$torneo['tipo_torneo_id']);
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
        $this->checkCsrf();
        try {
            (new SistemaSuizoService())->generarSiguienteRonda((int)$id);
            $this->flash('success', 'Siguiente ronda generada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/torneos/{$id}");
    }

    public function inscribir(string $id): void
    {
        $this->requireOrganizador();
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
