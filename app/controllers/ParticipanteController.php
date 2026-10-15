<?php
declare(strict_types=1);

class ParticipanteController extends BaseController
{
    /** @var array<string,string> los estados del torneo por los que se filtra «Mis torneos», con su texto */
    private const ESTADOS_TORNEO = [
        'inscripcion' => 'Inscripción',
        'en_curso'    => 'En curso',
        'finalizado'  => 'Finalizado',
        'cancelado'   => 'Cancelado',
    ];

    public function dashboard(): void
    {
        $this->requireRole(['participante', 'administrador']);
        // Letra §5.3: el participante consulta sus torneos, calendario,
        // resultados y posicion. El perfil propio NO se filtra por modulo: son
        // sus datos, y la letra se los concede por rol.
        $this->requirePermiso('consulta_publica', 'ver');
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());

        $torneos = [];
        $stats   = null;
        $panel   = null;
        if ($participante) {
            $torneos = (new InscripcionModel())->getByParticipante((int)$participante['id']);
            $stats   = (new StatsService())->participante((int)$participante['id']);
            // Próximos partidos, últimos resultados y posición en cada torneo.
            $panel   = (new PanelParticipanteService())->panel((int)$participante['id']);
        }

        $this->render('participante/dashboard', [
            'pageTitle'    => 'Mi panel',
            'participante' => $participante,
            'torneos'      => $torneos,
            'stats'        => $stats,
            'panel'        => $panel,
        ], 'admin');
    }

    public function perfil(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        $stats   = $participante ? (new StatsService())->participante((int)$participante['id']) : null;
        $usuario = (new UsuarioModel())->findByIdConRol((int)Auth::id());

        $this->render('participante/perfil', [
            'pageTitle'    => 'Mi perfil',
            'participante' => $participante,
            'usuario'      => $usuario,
            'stats'        => $stats,
            'csrf'         => Csrf::generate(),
            'extraJs'      => ['modales.js'],
        ], 'admin');
    }

    public function guardarPerfil(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $this->checkCsrf();

        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        if (!$participante) {
            $this->flash('error', 'No se encontró tu perfil de participante.');
            $this->redirect('/participante/perfil');
        }

        try {
            $datos = [
                'nombre'   => $this->postStr('nombre'),
                'nick'     => $this->postStr('nick'),
                'email'    => $this->postStr('email'),
                'telefono' => $this->postStr('telefono'),
            ];
            // Foto de perfil (opcional)
            $nuevaFoto = Upload::image($_FILES['foto'] ?? [], 'avatars');
            if ($nuevaFoto !== null) {
                Upload::delete('avatars', $participante['foto'] ?? null);
                $datos['foto'] = $nuevaFoto;
            }
            (new ParticipanteService())->actualizarPerfil((int)$participante['id'], $datos);
            // Refrescar la sesión: la topbar/Auth usan estos valores cacheados del login
            Session::set('user_nombre', $datos['nombre']);
            if (!empty($datos['email'])) Session::set('user_email', strtolower($datos['email']));
            $this->flash('success', 'Perfil actualizado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/participante/perfil');
    }

    public function eliminarDatos(): void
    {
        $this->requireRole(['participante']);
        $this->checkCsrf();

        $usuarioId    = (int)Auth::id();
        $participante = (new ParticipanteModel())->findByUsuario($usuarioId);

        if (!$participante) {
            $this->flash('error', 'No se encontró tu perfil de participante.');
            $this->redirect('/participante/perfil');
        }

        try {
            (new ParticipanteService())->anonimizarDatos((int)$participante['id'], $usuarioId);
            Session::destroy();
            $this->redirect('/login?msg=datos_eliminados');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
            $this->redirect('/participante/perfil');
        }
    }

    public function misTorneos(): void
    {
        $this->requireRole(['participante', 'administrador']);
        // Ademas de consultar, la vista muestra resultados y posiciones.
        $this->requirePermiso('resultados', 'ver');
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        $inscripciones = new InscripcionModel();
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'estado' => array_keys(self::ESTADOS_TORNEO),
        ]);
        $pid = $participante ? (int)$participante['id'] : 0;     // sin perfil no hay inscripciones (id 0 no existe)
        $pag = Paginador::de($inscripciones->contarPorParticipante($pid, $filtros), $this->get('page', 1), TorneoController::POR_PAGINA);

        $this->render('participante/mis_torneos', [
            'pageTitle' => 'Mis torneos',
            'torneos'   => $inscripciones->buscarPorParticipante($pid, $filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'   => $filtros,
            'pag'       => $pag,
            'estados'   => self::ESTADOS_TORNEO,
        ], 'admin');
    }
}
