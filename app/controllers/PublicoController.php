<?php
declare(strict_types=1);

class PublicoController extends BaseController
{
    /**
     * Si el administrador cerró los perfiles (Configuración → Perfiles públicos), solo los ve quien
     * inició sesión. Se comprueba ANTES de buscar el perfil, así un visitante no puede averiguar qué
     * identificadores existen.
     */
    private function exigirPerfilVisible(): void
    {
        if (!(new ConfiguracionSistemaService())->perfilesPublicos() && !Auth::isLoggedIn()) {
            Session::flash('warning', 'Los perfiles solo se ven con la sesión iniciada.');
            throw new SesionRequerida('Los perfiles están cerrados a quien no inició sesión.');
        }
    }

    /** ¿Se pueden enlazar los perfiles desde las demás páginas para quien está mirando? */
    private function perfilesVisibles(): bool
    {
        return (new ConfiguracionSistemaService())->perfilesPublicos() || Auth::isLoggedIn();
    }

    /** Perfil público de un equipo. */
    public function equipo(string $id): void
    {
        $this->exigirPerfilVisible();
        $equipo = (new EquipoModel())->findPublicoConParticipantes((int)$id);
        if (!$equipo) {
            http_response_code(404);
            $this->render('shared/404', ['pageTitle' => 'Página no encontrada'], 'public');
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
        $this->exigirPerfilVisible();
        // v_participantes_publicos ya deja afuera a los pendientes y rechazados, y no
        // trae documento, email ni teléfono.
        $participante = (new ParticipanteModel())->findPublicoConEquipos((int)$id);
        if (!$participante) {
            http_response_code(404);
            $this->render('shared/404', ['pageTitle' => 'Página no encontrada'], 'public');
            return;
        }
        $this->render('public/participante_perfil', [
            'pageTitle'    => $participante['nombre'],
            'participante' => $participante,
            'stats'        => (new StatsService())->participante((int)$id),
        ], 'public');
    }

    /** Política de Privacidad. Pública y sin sesión: se lee ANTES de registrarse. */
    public function privacidad(): void
    {
        $this->render('public/privacidad', ['pageTitle' => 'Política de Privacidad'], 'public');
    }

    /** Términos y Condiciones. Públicos y sin sesión, igual que la política. */
    public function terminos(): void
    {
        $this->render('public/terminos', ['pageTitle' => 'Términos y Condiciones'], 'public');
    }

    public function home(): void
    {
        // Torneo destacado: el más reciente en curso o, si no hay, el más reciente. Una sola
        // fila (antes se traían todos los torneos públicos para quedarse con uno).
        $this->render('public/home', [
            'pageTitle' => 'Inicio',
            'destacado' => (new TorneoModel())->destacadoPublico(),
        ], 'public');
    }

    /** Torneos por página en la lista pública (tres columnas de tarjetas: múltiplo de 3). */
    public const POR_PAGINA = 12;

    /**
     * Lista pública de torneos, con búsqueda por nombre, filtros de formato y estado, y
     * paginación. El filtrado y el recorte de la página los hace la consulta (LIMIT/COUNT).
     */
    public function torneos(): void
    {
        $torneoModel = new TorneoModel();
        $tipos       = array_column((new TipoTorneoModel())->findAll(), 'nombre', 'slug');
        $filtros     = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'tipo'   => array_keys($tipos),
            'estado' => array_keys(self::ETIQUETAS_ESTADO_PUBLICO),
        ]);

        $pag = Paginador::de($torneoModel->contarPublicos($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('public/torneos', [
            'pageTitle' => 'Torneos',
            'torneos'   => $torneoModel->buscarPublicos($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'   => $filtros,
            'pag'       => $pag,
            'tipos'     => $tipos,
            'estados'   => self::ETIQUETAS_ESTADO_PUBLICO,
            'extraJs'   => ['formato.js', 'api.js', 'torneos-en-vivo.js'],   // la búsqueda en vivo: formato.js y api.js tienen que ir antes
        ], 'public');
    }

    /** @var array<string,string> los estados que se pueden filtrar en la lista pública (no hay borradores ni cancelados), con su texto */
    private const ETIQUETAS_ESTADO_PUBLICO = [
        'inscripcion' => 'Inscripción',
        'en_curso'    => 'En curso',
        'finalizado'  => 'Finalizado',
    ];

    /** Calendario público de torneos */
    public function calendario(): void
    {
        $torneos = (new TorneoModel())->findPublicos();
        $this->render('public/torneos_calendario', [
            'pageTitle' => 'Calendario de torneos',
            'torneos'   => $torneos,
            'extraJs'   => ['formato.js', 'calendar.js'],
        ], 'public');
    }

    public function detalle(string $id): void
    {
        $torneoModel = new TorneoModel();
        $torneo      = $torneoModel->findByIdCompleto((int)$id);

        if (!$torneo || !$torneoModel->esPublico((int)$id)) {
            http_response_code(404);
            $this->render('shared/404', ['pageTitle' => 'Página no encontrada'], 'public');
            return;
        }

        $tipo             = (new TipoTorneoModel())->findById((int)$torneo['tipo_torneo_id']);
        $inscritos        = (new InscripcionModel())->getByTorneoPublico((int)$id);
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
            'perfilesVisibles' => $this->perfilesVisibles(),
            'extraJs'          => ['formato.js', 'proximos-partidos.js'],
            'rondasConPartidos'=> $rondasConPartidos,
            'tabla'            => $tabla,
            // Datos del evento: sede, contacto, cierre de inscripcion y
            // observaciones (tabla configuraciones_torneo).
            'configuracion'    => (new ConfiguracionTorneoService())->getPorTorneo((int)$id),
            'clavesConfig'     => ConfiguracionTorneoService::CLAVES,
        ], 'public');
    }
}
