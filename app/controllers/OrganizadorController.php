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

    /**
     * Un organizador solo puede gestionar SUS torneos; el administrador, todos.
     * Tras transferir el torneo a otro organizador, el anterior pierde el acceso.
     */
    private function requireOwnership(int $torneoId): void
    {
        if (Auth::isAdmin()) return;
        $ownerId = (new TorneoModel())->getOrganizadorId($torneoId);
        if ($ownerId !== (int)Auth::id()) {
            $this->flash('error', 'No tenés acceso a este torneo.');
            $this->redirect('/organizador/torneos');
        }
    }

    public function dashboard(): void
    {
        $this->requireOrganizador();
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
        $this->requireOwnership((int)$id);
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
        ], 'admin');
    }

    public function generarCompetencia(string $id): void
    {
        $this->requireOrganizador();
        $this->requireOwnership((int)$id);
        $this->checkCsrf();
        try {
            $tipo = (new TipoTorneoModel())->findById((int)(new TorneoModel())->findById((int)$id)['tipo_torneo_id']);
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
        $this->requireOwnership((int)$id);
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
        $this->requireOwnership((int)$id);
        $this->checkCsrf();
        try {
            $participanteId = $this->postInt('participante_id');
            $equipoId       = $this->postInt('equipo_id');
            if ($participanteId) $this->inscripcionService->inscribirParticipante((int)$id, $participanteId);
            elseif ($equipoId)   $this->inscripcionService->inscribirEquipo((int)$id, $equipoId);
            $this->flash('success', 'Inscripción realizada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/organizador/torneos/{$id}");
    }

    public function desinscribir(string $id): void
    {
        $this->requireOrganizador();
        $this->requireOwnership((int)$id);
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
