<?php
declare(strict_types=1);

class AdminController extends BaseController
{
    private UsuarioService     $usuarioService;
    private ParticipanteService $participanteService;
    private EquipoService      $equipoService;
    private AuditoriaService   $auditoriaService;
    private ModuloModel        $moduloModel;

    public function __construct()
    {
        $this->usuarioService      = new UsuarioService();
        $this->participanteService = new ParticipanteService();
        $this->equipoService       = new EquipoService();
        $this->auditoriaService    = new AuditoriaService();
        $this->moduloModel         = new ModuloModel();
    }

    // ─── Dashboard ───────────────────────────────────────────

    public function dashboard(): void
    {
        $this->requireAdmin();
        $torneoModel = new TorneoModel();
        $this->render('admin/dashboard', [
            'pageTitle'    => 'Panel Admin',
            'totalTorneos' => $torneoModel->count(),
            'totalParticipantes' => (new ParticipanteModel())->count(),
            'totalEquipos' => (new EquipoModel())->count(),
            'actividad'    => $this->auditoriaService->getRecientes(10),
        ], 'admin');
    }

    // ─── Usuarios ────────────────────────────────────────────

    // ─── Organizadores (apartado exclusivo de usuarios rol organizador) ──

    public function usuarios(): void
    {
        $this->requireAdmin();
        $this->render('admin/usuarios', [
            'pageTitle' => 'Organizadores',
            'usuarios'  => $this->usuarioService->getOrganizadores(),
        ], 'admin');
    }

    public function usuarioForm(string $id = ''): void
    {
        $this->requireAdmin();
        $usuario = $id ? $this->usuarioService->getById((int)$id) : null;
        // Este apartado solo gestiona organizadores
        if ($usuario && $usuario['rol_nombre'] !== 'organizador') {
            $this->flash('error', 'Este apartado solo gestiona organizadores.');
            $this->redirect('/admin/usuarios');
        }
        $this->render('admin/usuario_form', [
            'pageTitle' => $usuario ? 'Editar organizador' : 'Nuevo organizador',
            'usuario'   => $usuario,
            'csrf'      => Csrf::generate(),
        ], 'admin');
    }

    public function usuarioGuardar(string $id = ''): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            // El rol se fuerza a 'organizador' (apartado exclusivo)
            $datos = [
                'rol_id'   => $this->usuarioService->getRolIdOrganizador(),
                'nombre'   => $this->postStr('nombre'),
                'email'    => $this->postStr('email'),
                'password' => $this->post('password', ''),
                'estado'   => $this->postStr('estado', 'activo'),
            ];
            if ($id) {
                $this->usuarioService->editar((int)$id, $datos);
                $this->flash('success', 'Organizador actualizado correctamente.');
            } else {
                $this->usuarioService->crear($datos);
                $this->flash('success', 'Organizador creado correctamente.');
            }
            $this->redirect('/admin/usuarios');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($id ? "/admin/usuarios/editar/{$id}" : '/admin/usuarios/crear');
        }
    }

    public function usuarioEliminar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $nuevo = $this->usuarioService->toggleActivo((int)$id);
            $this->flash('success', $nuevo === 'activo' ? 'Organizador reactivado.' : 'Organizador desactivado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/usuarios');
    }

    // ─── Registros pendientes de participantes ────────────────

    public function registros(): void
    {
        $this->requireAdmin();
        $this->render('admin/registros', [
            'pageTitle'  => 'Registros pendientes',
            'pendientes' => (new RegistroService())->getPendientes(),
            'csrf'       => Csrf::generate(),
        ], 'admin');
    }

    public function registroAprobar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            (new RegistroService())->aprobar((int)$id, (int)Auth::id());
            $this->flash('success', 'Registro aprobado. El participante ya puede iniciar sesión.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/registros');
    }

    public function registroRechazar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            (new RegistroService())->rechazar((int)$id, (int)Auth::id());
            $this->flash('success', 'Registro rechazado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/registros');
    }

    // ─── Participantes ────────────────────────────────────────

    public function participantes(): void
    {
        $this->requireAdmin();
        $this->render('admin/participantes', [
            'pageTitle'     => 'Participantes',
            'participantes' => $this->participanteService->getAll(),
        ], 'admin');
    }

    public function participanteForm(string $id = ''): void
    {
        $this->requireAdmin();
        // Los participantes se registran por cuenta propia: no hay alta manual.
        if (!$id) {
            $this->flash('info', 'Los participantes se registran por sí mismos desde la página pública. Aprobá las solicitudes en “Registros”.');
            $this->redirect('/admin/registros');
        }
        $participante = $this->participanteService->getById((int)$id);
        $this->render('admin/participante_form', [
            'pageTitle'    => $participante ? 'Editar participante' : 'Nuevo participante',
            'participante' => $participante,
            'equipos'      => (new EquipoModel())->findAllConConteo(),
            'csrf'         => Csrf::generate(),
        ], 'admin');
    }

    public function participanteGuardar(string $id = ''): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        // Alta manual deshabilitada: solo se editan participantes existentes.
        if (!$id) {
            $this->flash('error', 'El alta manual de participantes está deshabilitada. Se registran por sí mismos.');
            $this->redirect('/admin/registros');
        }
        try {
            $datos = [
                'nombre'    => $this->postStr('nombre'),
                'documento' => $this->postStr('documento'),
                'nick'      => $this->postStr('nick'),
                'email'     => $this->postStr('email'),
                'telefono'  => $this->postStr('telefono'),
                'estado'    => $this->postStr('estado', 'activo'),
            ];
            if ($id) {
                $this->participanteService->editar((int)$id, $datos);
                $this->flash('success', 'Participante actualizado.');
            } else {
                $this->participanteService->crear($datos);
                $this->flash('success', 'Participante creado.');
            }
            $this->redirect('/admin/participantes');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($id ? "/admin/participantes/editar/{$id}" : '/admin/participantes/crear');
        }
    }

    public function participanteEliminar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $nuevo = $this->participanteService->toggleActivo((int)$id);
            $this->flash('success', $nuevo === 'activo' ? 'Participante reactivado.' : 'Participante desactivado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/participantes');
    }

    // ─── Equipos ─────────────────────────────────────────────

    public function equipos(): void
    {
        $this->requireAdmin();
        $this->render('admin/equipos', [
            'pageTitle' => 'Equipos',
            'equipos'   => $this->equipoService->getAll(),
        ], 'admin');
    }

    public function equipoForm(string $id = ''): void
    {
        $this->requireAdmin();
        $equipo = $id ? $this->equipoService->getById((int)$id) : null;
        $this->render('admin/equipo_form', [
            'pageTitle'   => $equipo ? 'Editar equipo' : 'Nuevo equipo',
            'equipo'      => $equipo,
            'participantes' => (new ParticipanteModel())->findAll('nombre', 'ASC'),
            'csrf'        => Csrf::generate(),
        ], 'admin');
    }

    public function equipoGuardar(string $id = ''): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $datos = [
                'nombre'      => $this->postStr('nombre'),
                'categoria'   => $this->postStr('categoria'),
                'disciplina'  => $this->postStr('disciplina'),
                'descripcion' => $this->postStr('descripcion'),
                'estado'      => $this->postStr('estado', 'activo'),
            ];
            // Logo del equipo (opcional)
            $nuevoLogo = Upload::image($_FILES['logo'] ?? [], 'logos');
            if ($nuevoLogo !== null) {
                if ($id) {
                    $actual = $this->equipoService->getById((int)$id);
                    Upload::delete('logos', $actual['logo'] ?? null);
                }
                $datos['logo'] = $nuevoLogo;
            }
            if ($id) {
                $this->equipoService->editar((int)$id, $datos);
                $this->flash('success', 'Equipo actualizado.');
            } else {
                $this->equipoService->crear($datos);
                $this->flash('success', 'Equipo creado.');
            }
            $this->redirect('/admin/equipos');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($id ? "/admin/equipos/editar/{$id}" : '/admin/equipos/crear');
        }
    }

    public function equipoEliminar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $nuevo = $this->equipoService->toggleActivo((int)$id);
            $this->flash('success', $nuevo === 'activo' ? 'Equipo reactivado.' : 'Equipo desactivado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/equipos');
    }

    public function equipoAgregarParticipante(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->equipoService->agregarParticipante((int)$id, $this->postInt('participante_id'), $this->postStr('rol_en_equipo', 'jugador'));
            $this->flash('success', 'Participante agregado al equipo.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/admin/equipos/editar/{$id}");
    }

    public function equipoQuitarParticipante(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        $this->equipoService->quitarParticipante((int)$id, $this->postInt('participante_id'));
        $this->flash('success', 'Participante removido del equipo.');
        $this->redirect("/admin/equipos/editar/{$id}");
    }

    // ─── Auditoría ────────────────────────────────────────────

    public function auditoria(): void
    {
        $this->requireAdmin();
        $page   = max(1, (int)($this->get('page', 1)));
        $limit  = 50;
        $offset = ($page - 1) * $limit;
        $this->render('admin/auditoria', [
            'pageTitle' => 'Auditoría',
            'registros' => $this->auditoriaService->getRecientes($limit, $offset),
            'total'     => $this->auditoriaService->countTotal(),
            'page'      => $page,
            'limit'     => $limit,
        ], 'admin');
    }

    // ─── Módulos ─────────────────────────────────────────────

    public function modulos(): void
    {
        $this->requireAdmin();
        $this->render('admin/modulos', [
            'pageTitle' => 'Módulos',
            'modulos'   => $this->moduloModel->findAll(),
        ], 'admin');
    }

    public function moduloToggle(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        $nuevoEstado = $this->moduloModel->toggleEstado((int)$id);
        $this->flash('success', "Módulo " . ($nuevoEstado === 'activo' ? 'activado' : 'desactivado') . '.');
        $this->redirect('/admin/modulos');
    }
}
