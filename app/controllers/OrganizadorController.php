<?php
declare(strict_types=1);

class OrganizadorController extends BaseController
{
    private TorneoService      $torneoService;
    private InscripcionService $inscripcionService;

    public function __construct()
    {
        $this->torneoService      = new TorneoService();
        $this->inscripcionService = new InscripcionService();
    }

    public function dashboard(): void
    {
        $this->requireOrganizador();
        // Letra §5.2: el organizador trabaja sobre los torneos que administra.
        $this->requirePermiso('torneos', 'ver');
        $torneos = $this->torneoService->getByOrganizador((int)Auth::id());
        // Si es admin, ver todos
        if (Auth::isAdmin()) {
            $torneos = $this->torneoService->getAll();
        }
        $this->render('organizador/dashboard', [
            'pageTitle' => 'Panel Organizador',
            'torneos'   => $torneos,
        ], 'admin');
    }

    public function misTorneos(): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'ver');
        $torneos = Auth::isAdmin()
            ? $this->torneoService->getAll()
            : $this->torneoService->getByOrganizador((int)Auth::id());

        $this->render('organizador/mis_torneos', [
            'pageTitle' => 'Mis torneos',
            'torneos'   => $torneos,
        ], 'admin');
    }

    public function gestion(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'ver');
        $this->requireTorneoOwnership((int)$id, '/organizador/torneos');
        $torneo = $this->torneoService->getById((int)$id);
        if (!$torneo) { $this->flash('error', 'Torneo no encontrado.'); $this->redirect('/organizador/torneos'); }

        $tipo             = (new TipoTorneoModel())->findById((int)$torneo['tipo_torneo_id']);
        $inscritos        = $this->inscripcionService->getByTorneo((int)$id);
        $rondaModel       = new RondaModel();
        $rondas           = $rondaModel->getByTorneo((int)$id);
        $enfModel         = new EnfrentamientoModel();

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

        $this->render('organizador/torneo_gestion', [
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
            // El panel de inscripciones dibuja el selector de participantes como
            // combobox; sin este script el <input> visible no tiene `name` y el
            // hidden viaja vacío, así que el formulario se manda en blanco.
            'extraJs'                 => ['combobox.js'],
        ], 'admin');
    }

    public function generarCompetencia(string $id): void
    {
        $this->requireOrganizador();
        $this->requireTorneoOwnership((int)$id, '/organizador/torneos');
        $this->checkCsrf();
        try {
            $tipo = (new TipoTorneoModel())->findById((int)(new TorneoModel())->findById((int)$id)['tipo_torneo_id']);
            // Letra §5.2 «generar rondas o llaves»: el permiso se pide sobre el
            // modulo del formato concreto, no sobre «torneos» en general, para
            // que el administrador pueda habilitar a un organizador en liga y
            // no en suizo, por ejemplo.
            $this->requirePermiso((string)$tipo['slug'], 'crear', "/organizador/torneos/{$id}");
            match ($tipo['slug']) {
                'liga'               => (new LigaService())->generarFixture((int)$id),
                'eliminacion_directa'=> (new EliminacionDirectaService())->generarBracket((int)$id),
                'suizo'              => (new SistemaSuizoService())->generarPrimeraRonda((int)$id),
                default              => throw new RuntimeException("Formato desconocido."),
            };
            $this->flash('success', 'Competencia generada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/organizador/torneos/{$id}");
    }

    public function siguienteRondaSuizo(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('suizo', 'crear', "/organizador/torneos/{$id}");
        $this->requireTorneoOwnership((int)$id, '/organizador/torneos');
        $this->checkCsrf();
        try {
            (new SistemaSuizoService())->generarSiguienteRonda((int)$id);
            $this->flash('success', 'Siguiente ronda generada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/organizador/torneos/{$id}");
    }

    public function inscribir(string $id): void
    {
        $this->requireOrganizador();
        // Letra §5.2 «inscribir participantes». Es una accion sobre el torneo,
        // no sobre el padron de participantes: gestionar participantes es del
        // administrador (§5.1).
        $this->requirePermiso('torneos', 'editar', "/organizador/torneos/{$id}");
        $this->requireTorneoOwnership((int)$id, '/organizador/torneos');
        $this->checkCsrf();
        try {
            $participanteId = $this->postInt('participante_id');
            $equipoId       = $this->postInt('equipo_id');
            // El éxito se anuncia dentro de cada rama: si no vino ningún id no se
            // inscribió a nadie, y decir «realizada» ahí es mentir.
            if ($participanteId) {
                $this->inscripcionService->inscribirParticipante((int)$id, $participanteId);
                $this->flash('success', 'Participante inscrito.');
            } elseif ($equipoId) {
                $this->inscripcionService->inscribirEquipo((int)$id, $equipoId);
                $this->flash('success', 'Equipo inscrito.');
            } else {
                $this->flash('error', 'Elegí a quién inscribir antes de confirmar.');
            }
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/organizador/torneos/{$id}");
    }

    public function desinscribir(string $id): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'editar', "/organizador/torneos/{$id}");
        $this->requireTorneoOwnership((int)$id, '/organizador/torneos');
        $this->checkCsrf();
        try {
            $this->inscripcionService->desinscribir((int)$id,
                ($this->postInt('participante_id') ?: null),
                ($this->postInt('equipo_id') ?: null)
            );
            $this->flash('success', 'Inscripción retirada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/organizador/torneos/{$id}");
    }
}
