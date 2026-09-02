<?php
declare(strict_types=1);

class PublicoController extends BaseController
{
    /** Perfil público de un equipo. */
    public function equipo(string $id): void
    {
        $equipo = (new EquipoModel())->findByIdConParticipantes((int)$id);
        if (!$equipo) {
            http_response_code(404);
            $this->render('shared/404', [], 'public');
            return;
        }
        $this->render('public/equipo_perfil', [
            'pageTitle' => $equipo['nombre'],
            'equipo'    => $equipo,
            'stats'     => (new StatsService())->equipo((int)$id),
        ], 'public');
    }

    /** Perfil público de un participante (solo cuentas activas). */
    public function jugador(string $id): void
    {
        $participante = (new ParticipanteModel())->findByIdConEquipos((int)$id);
        if (!$participante || !in_array($participante['estado'], ['activo','inactivo','suspendido'], true)) {
            http_response_code(404);
            $this->render('shared/404', [], 'public');
            return;
        }
        $this->render('public/participante_perfil', [
            'pageTitle'    => $participante['nombre'],
            'participante' => $participante,
            'stats'        => (new StatsService())->participante((int)$id),
        ], 'public');
    }

    public function home(): void
    {
        $torneoModel = new TorneoModel();
        $torneos     = $torneoModel->findPublicos();

        // Torneo destacado: el más reciente en_curso
        $destacado = null;
        foreach ($torneos as $t) {
            if ($t['estado'] === 'en_curso') { $destacado = $t; break; }
        }
        if (!$destacado && !empty($torneos)) $destacado = $torneos[0];

        $this->render('public/home', [
            'pageTitle' => 'Inicio',
            'torneos'   => $torneos,
            'destacado' => $destacado,
        ], 'public');
    }

    public function torneos(): void
    {
        $torneoModel = new TorneoModel();
        $tipoModel   = new TipoTorneoModel();
        $filtroTipo  = $this->get('tipo', '');
        $filtroEstado = $this->get('estado', '');

        $torneos = $torneoModel->findPublicos();

        // Filtros simples
        if ($filtroTipo) {
            $torneos = array_filter($torneos, fn($t) => $t['tipo_slug'] === $filtroTipo);
        }
        if ($filtroEstado) {
            $torneos = array_filter($torneos, fn($t) => $t['estado'] === $filtroEstado);
        }

        $this->render('public/torneos', [
            'pageTitle'    => 'Torneos',
            'torneos'      => array_values($torneos),
            'tipos'        => $tipoModel->findAll(),
            'filtroTipo'   => $filtroTipo,
            'filtroEstado' => $filtroEstado,
        ], 'public');
    }

    public function detalle(string $id): void
    {
        $torneoModel = new TorneoModel();
        $torneo      = $torneoModel->findByIdCompleto((int)$id);

        if (!$torneo || !$torneo['publico']) {
            http_response_code(404);
            $this->render('shared/404', [], 'public');
            return;
        }

        $tipo             = (new TipoTorneoModel())->findById((int)$torneo['tipo_torneo_id']);
        $inscritos        = (new InscripcionModel())->getByTorneo((int)$id);
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

        $tabla = [];
        if (in_array($tipo['slug'], ['liga', 'suizo'], true)) {
            $tabla = (new TablaPosicionesService())->getByTorneo((int)$id);
        }

        $this->render('public/torneo_detalle', [
            'pageTitle'        => $torneo['nombre'],
            'torneo'           => $torneo,
            'tipo'             => $tipo,
            'inscritos'        => $inscritos,
            'rondasConPartidos'=> $rondasConPartidos,
            'tabla'            => $tabla,
        ], 'public');
    }
}
