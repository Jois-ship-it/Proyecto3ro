<?php
declare(strict_types=1);

class AdminController extends BaseController
{
    private UsuarioService     $usuarioService;
    private ParticipanteService $participanteService;
    private EquipoService      $equipoService;
    private AuditoriaService   $auditoriaService;

    /** Filas por página en las listas del panel (la auditoría, que es más densa, muestra más). */
    public const POR_PAGINA           = 15;
    public const POR_PAGINA_AUDITORIA = 50;

    /** @var array<string,string> estados por los que se filtran las listas, con su texto */
    private const ESTADOS_CUENTA = ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'bloqueada' => 'Bloqueada'];
    private const ESTADOS_PARTICIPANTE = [
        'activo' => 'Activo', 'inactivo' => 'Inactivo', 'suspendido' => 'Suspendido',
        'pendiente' => 'Pendiente', 'rechazado' => 'Rechazado',
    ];
    private const ESTADOS_EQUIPO = ['activo' => 'Activo', 'inactivo' => 'Inactivo'];
    private ModuloService      $moduloService;
    private PermisoService     $permisoService;

    public function __construct()
    {
        $this->usuarioService      = new UsuarioService();
        $this->participanteService = new ParticipanteService();
        $this->equipoService       = new EquipoService();
        $this->auditoriaService    = new AuditoriaService();
        $this->moduloService       = new ModuloService();
        $this->permisoService      = new PermisoService();
    }

    // ─── Dashboard ───────────────────────────────────────────

    public function dashboard(): void
    {
        $this->requireAdmin();
        // Los contadores salen de la vista v_estadisticas_sistema, en una sola consulta.
        $stats = (new EstadisticasModel())->get();
        $this->render('admin/dashboard', [
            'pageTitle'    => 'Panel Admin',
            'totalTorneos' => (int)$stats['torneos_total'],
            'totalParticipantes' => (int)$stats['participantes_total'],
            'totalEquipos' => (int)$stats['equipos_total'],
            'actividad'    => $this->auditoriaService->getRecientes(10),
        ], 'admin');
    }

    // ─── Cuentas por rol: organizadores y administradores ────
    //
    // Los dos apartados son la misma pantalla con otro rol: lista, alta, edición, activar o
    // desactivar, bloquear y desbloquear. Lo único que cambia está en contextoUsuarios().

    /** Lo que cambia entre el apartado de organizadores y el de administradores. */
    private function contextoUsuarios(string $rol): array
    {
        return match ($rol) {
            'administrador' => [
                'rol'         => 'administrador',
                'singular'    => 'administrador',
                'plural'      => 'Administradores',
                'rolEtiqueta' => 'Administrador',
                'ruta'        => '/admin/administradores',
                'descripcion' => 'Usuarios con acceso completo al sistema: gestionan cuentas, permisos, módulos y todos los torneos.',
                'aviso'       => 'Las cuentas creadas aquí tienen rol <strong>Administrador</strong>: acceso completo al sistema.',
            ],
            default => [
                'rol'         => 'organizador',
                'singular'    => 'organizador',
                'plural'      => 'Organizadores',
                'rolEtiqueta' => 'Organizador',
                'ruta'        => '/admin/organizadores',
                'descripcion' => 'Usuarios con permiso para gestionar torneos asignados.',
                'aviso'       => 'Las cuentas creadas aquí tienen rol <strong>Organizador</strong>.',
            ],
        };
    }

    private function listaDeUsuarios(string $rol): void
    {
        $this->requireAdmin();
        $ctx     = $this->contextoUsuarios($rol);
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'estado' => array_keys(self::ESTADOS_CUENTA),
        ]);
        $pag = Paginador::de($this->usuarioService->contarPorRol($rol, $filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/usuarios', [
            'pageTitle'          => $ctx['plural'],
            'ctx'                => $ctx,
            'usuarios'           => $this->usuarioService->buscarPorRol($rol, $filtros, $pag->porPagina, $pag->desplazamiento),
            'unicoAdminActivoId' => $this->usuarioService->unicoAdministradorActivoId(),
            'filtros'            => $filtros,
            'pag'                => $pag,
            'estados'            => self::ESTADOS_CUENTA,
        ], 'admin');
    }

    private function formularioDeUsuario(string $rol, string $id): void
    {
        $this->requireAdmin();
        $ctx     = $this->contextoUsuarios($rol);
        $usuario = $id ? $this->usuarioService->getById((int)$id) : null;
        // Cada apartado solo gestiona las cuentas de su rol
        if ($usuario && $usuario['rol_nombre'] !== $rol) {
            $this->flash('error', 'Este apartado solo gestiona ' . strtolower($ctx['plural']) . '.');
            $this->redirect($ctx['ruta']);
        }
        $this->render('admin/usuario_form', [
            'pageTitle' => ($usuario ? 'Editar ' : 'Nuevo ') . $ctx['singular'],
            'ctx'       => $ctx,
            'usuario'   => $usuario,
            'csrf'      => Csrf::generate(),
        ], 'admin');
    }

    private function guardarUsuario(string $rol, string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        $ctx = $this->contextoUsuarios($rol);
        try {
            // Antes el apartado de organizadores ponía rol «organizador» sin mirar qué cuenta se
            // editaba: por esa ruta se podía degradar a un administrador. Cada apartado solo toca
            // las cuentas de su rol.
            if ($id && ($this->usuarioService->getById((int)$id)['rol_nombre'] ?? null) !== $rol) {
                throw new ErrorNegocio('Este apartado solo gestiona ' . strtolower($ctx['plural']) . '.');
            }
            // El rol lo fija el apartado, no el formulario
            $datos = [
                'rol_id'   => $this->usuarioService->getRolId($rol),
                'nombre'   => $this->postStr('nombre'),
                'email'    => $this->postStr('email'),
                'password' => $this->post('password', ''),
                'estado'   => $this->postStr('estado', 'activo'),
            ];
            if ($id) {
                $this->usuarioService->editar((int)$id, $datos);
                $this->flash('success', ucfirst($ctx['singular']) . ' actualizado correctamente.');
            } else {
                $this->usuarioService->crear($datos);
                $this->flash('success', ucfirst($ctx['singular']) . ' creado correctamente.');
            }
            $this->redirect($ctx['ruta']);
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
            $this->redirect($id ? "{$ctx['ruta']}/editar/{$id}" : "{$ctx['ruta']}/crear");
        }
    }

    private function activarODesactivarUsuario(string $rol, string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        $ctx = $this->contextoUsuarios($rol);
        try {
            // Igual que al guardar: cada apartado solo toca las cuentas de su rol
            $destino = $this->usuarioService->getById((int)$id);
            if ($destino && $destino['rol_nombre'] !== $rol) {
                throw new ErrorNegocio('Este apartado solo gestiona ' . strtolower($ctx['plural']) . '.');
            }
            $nuevo = $this->usuarioService->toggleActivo((int)$id);
            $this->flash('success', ucfirst($ctx['singular']) . ($nuevo === 'activo' ? ' reactivado.' : ' desactivado.'));
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect($ctx['ruta']);
    }

    // ─── Organizadores ───────────────────────────────────────

    public function usuarios(): void                     { $this->listaDeUsuarios('organizador'); }
    public function usuarioForm(string $id = ''): void   { $this->formularioDeUsuario('organizador', $id); }
    public function usuarioGuardar(string $id = ''): void { $this->guardarUsuario('organizador', $id); }
    public function usuarioEliminar(string $id): void    { $this->activarODesactivarUsuario('organizador', $id); }

    // ─── Administradores ─────────────────────────────────────

    public function administradores(): void                    { $this->listaDeUsuarios('administrador'); }
    public function administradorForm(string $id = ''): void   { $this->formularioDeUsuario('administrador', $id); }
    public function administradorGuardar(string $id = ''): void { $this->guardarUsuario('administrador', $id); }
    public function administradorEliminar(string $id): void    { $this->activarODesactivarUsuario('administrador', $id); }

    // ─── Bloqueo y desbloqueo de cuentas (organizadores, administradores y participantes) ────
    public function usuarioDesbloquear(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->usuarioService->desbloquear((int)$id);
            $this->flash('success', 'Cuenta desbloqueada correctamente.');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        // `return` viene del formulario, o sea del pedido: mismo criterio que
        // con el Referer, solo se acepta un destino del propio sitio.
        $return = Url::interna($this->postStr('return', ''), '/admin/organizadores');
        $this->redirect($return);
    }

    public function usuarioBloquear(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->usuarioService->bloquear((int)$id);
            $this->flash('success', 'Cuenta bloqueada correctamente.');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        // `return` viene del formulario, o sea del pedido: mismo criterio que
        // con el Referer, solo se acepta un destino del propio sitio.
        $return = Url::interna($this->postStr('return', ''), '/admin/organizadores');
        $this->redirect($return);
    }

    // ─── Registros pendientes de participantes ────────────────

    public function registros(): void
    {
        $this->requireAdmin();
        $registros = new RegistroService();
        $filtros   = Filtros::desdeQuery($_GET, ['q' => 'texto']);
        $pag       = Paginador::de($registros->contarPendientesFiltrados($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/registros', [
            'pageTitle'  => 'Registros pendientes',
            'pendientes' => $registros->buscarPendientes($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'    => $filtros,
            'pag'        => $pag,
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
            $this->flash('error', $this->mensajeDe($e));
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
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/registros');
    }

    // ─── Participantes ────────────────────────────────────────

    public function participantes(): void
    {
        $this->requireAdmin();
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'estado' => array_keys(self::ESTADOS_PARTICIPANTE),
        ]);
        $pag = Paginador::de($this->participanteService->contar($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/participantes', [
            'pageTitle'     => 'Participantes',
            'participantes' => $this->participanteService->buscar($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'       => $filtros,
            'pag'           => $pag,
            'estados'       => self::ESTADOS_PARTICIPANTE,
        ], 'admin');
    }

    /**
     * Editar un participante existente.
     *
     * No hay alta manual: los participantes se registran por sí mismos desde la
     * página pública y el administrador aprueba la solicitud en «Registros». El
     * $id, entonces, es obligatorio — sin él no hay nada que editar.
     */
    public function participanteEditar(string $id): void
    {
        $this->requireAdmin();
        $participante = $this->participanteService->getById((int)$id);
        if (!$participante) {
            $this->flash('error', 'Participante no encontrado.');
            $this->redirect('/admin/participantes');
        }
        $this->render('admin/participante_form', [
            'pageTitle'    => 'Editar participante',
            'participante' => $participante,
            'equipos'      => (new EquipoModel())->findAllConConteo(),
            'csrf'         => Csrf::generate(),
        ], 'admin');
    }

    public function participanteGuardar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->participanteService->editar((int)$id, [
                'nombre'    => $this->postStr('nombre'),
                'documento' => $this->postStr('documento'),
                'nick'      => $this->postStr('nick'),
                'email'     => $this->postStr('email'),
                'telefono'  => $this->postStr('telefono'),
                'estado'    => $this->postStr('estado', 'activo'),
            ]);
            $this->flash('success', 'Participante actualizado.');
            $this->redirect('/admin/participantes');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
            $this->redirect("/admin/participantes/editar/{$id}");
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
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/participantes');
    }

    // ─── Equipos ─────────────────────────────────────────────

    public function equipos(): void
    {
        $this->requireAdmin();
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'estado' => array_keys(self::ESTADOS_EQUIPO),
        ]);
        $pag = Paginador::de($this->equipoService->contar($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/equipos', [
            'pageTitle' => 'Equipos',
            'equipos'   => $this->equipoService->buscar($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'   => $filtros,
            'pag'       => $pag,
            'estados'   => self::ESTADOS_EQUIPO,
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
            'extraJs'     => ['combobox.js'],
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
            $this->flash('error', $this->mensajeDe($e));
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
            $this->flash('error', $this->mensajeDe($e));
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
            $this->flash('error', $this->mensajeDe($e));
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
        $acciones = $this->auditoriaService->acciones();
        $filtros  = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'accion' => $acciones,
            'desde'  => 'fecha',
            'hasta'  => 'fecha',
        ]);
        $pag = Paginador::de($this->auditoriaService->contar($filtros), $this->get('page', 1), self::POR_PAGINA_AUDITORIA);

        $this->render('admin/auditoria', [
            'pageTitle' => 'Auditoría',
            'registros' => $this->auditoriaService->buscar($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'   => $filtros,
            'pag'       => $pag,
            'acciones'  => array_combine($acciones, $acciones),
        ], 'admin');
    }

    // ─── Módulos ─────────────────────────────────────────────

    public function modulos(): void
    {
        $this->requireAdmin();
        $this->render('admin/modulos', [
            'pageTitle' => 'Módulos',
            'modulos'   => $this->moduloService->getTodos(),
        ], 'admin');
    }

    public function moduloToggle(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $r = $this->moduloService->toggle((int)$id);
            $accion = $r['nuevo'] === 'activo' ? 'activado' : 'desactivado';
            $this->flash('success', "Módulo «{$r['modulo']['nombre']}» {$accion}.");
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/modulos');
    }

    // ─── Permisos por rol ─────────────────────────────

    public function permisos(): void
    {
        $this->requireAdmin();
        $datos = $this->permisoService->matrizCompleta();
        $this->render('admin/permisos', [
            'pageTitle'    => 'Permisos',
            'roles'        => $datos['roles'],
            'modulos'      => $datos['modulos'],
            'permisos'     => $datos['permisos'],
            'rolAdminId'   => $datos['rol_admin_id'],
            'acciones'     => PermisoService::ACCIONES,
        ], 'admin');
    }

    public function permisosGuardar(): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            // El formulario manda permisos[rol_id][slug][accion] = 1 solo para
            // las casillas tildadas; las que no vienen se toman como negadas.
            $marcados = $_POST['permisos'] ?? [];
            $filas = $this->permisoService->guardarMatriz(is_array($marcados) ? $marcados : []);
            $this->flash('success', "Permisos actualizados: {$filas} combinacion(es) rol/modulo con acceso.");
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/permisos');
    }
}
