<?php
declare(strict_types=1);

/**
 * Test del apartado «Administradores» (G5) y de su gemelo «Organizadores».
 *
 * Qué pasaba antes:
 *   - No existía una pantalla para administrar cuentas de administrador: solo se podían crear
 *     con SQL o con el script de instalación. La letra pide gestionar usuarios y roles desde
 *     la aplicación.
 *   - Peor: el apartado de organizadores fijaba el rol «organizador» sin mirar qué cuenta se
 *     estaba editando. Abriendo /admin/organizadores/editar/1 se podía DEGRADAR a un
 *     administrador a organizador, y por esa ruta nada lo impedía.
 *
 * Qué hace ahora:
 *   - Los dos apartados son la misma pantalla parametrizada por rol (AdminController::
 *     contextoUsuarios). Cada uno lista, crea, edita, activa/desactiva, bloquea y desbloquea
 *     solo las cuentas de SU rol; el rol lo fija el apartado, no el formulario.
 *   - La lista no ofrece desactivar ni bloquear la cuenta propia ni la del único administrador
 *     activo (el servicio igual lo impide), y una cuenta bloqueada solo se puede desbloquear.
 *
 * Los controladores se ejecutan de verdad; la subclase solo reemplaza redirect() y render()
 * (que además devuelve el HTML de la vista, sin el layout, para poder mirarlo).
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/administradores_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

/** Se lanza en lugar del header()+exit de BaseController::redirect(). */
final class RedirigidoA extends Exception
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("redirect: {$url}");
    }
}

final class AdminControllerObservable extends AdminController
{
    public static string $vista = '';
    public static array  $datos = [];
    public static string $html  = '';

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        self::$vista = $view;
        self::$datos = $data;
        ob_start();
        try {
            parent::render($view, $data, '');      // sin layout: solo la vista
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never
    {
        throw new RedirigidoA($url);
    }
}

class AdministradoresTest extends TestCase
{
    private const CLAVE = 'Clave-Segura-9876!';

    private int $otroAdmin = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_POST = [];
        $this->otroAdmin = Fixtures::usuario('Segunda Administradora', 'admin2@test.local', self::CLAVE, 1);
        AdminControllerObservable::$vista = '';
        AdminControllerObservable::$datos = [];
        AdminControllerObservable::$html  = '';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ──────────────────────────────────────────────────────────────

    /** Llama a una acción del controlador. Devuelve la redirección que hizo, o null si renderizó. */
    private function llamar(string $accion, array $args = [], ?array $post = null): ?RedirigidoA
    {
        $_POST = $post === null ? [] : ($post + ['csrf_token' => Csrf::generate()]);
        try {
            (new AdminControllerObservable())->$accion(...$args);
            return null;
        } catch (RedirigidoA $r) {
            return $r;
        }
    }

    private function usuario(int $id): array
    {
        $st = $this->db->prepare('SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function bloquearEnLaBase(int $id, ?string $hasta): void
    {
        $st = $this->db->prepare("UPDATE usuarios SET estado = 'bloqueada', bloqueada_hasta = ? WHERE id = ?");
        $st->execute([$hasta, $id]);
    }

    /** Las filas <tr> de la tabla renderizada, por id de cuenta. @return array<int,string> */
    private function filasPorId(string $html): array
    {
        preg_match_all('#<tr>\s*<td>(\d+)</td>(.*?)</tr>#s', $html, $m, PREG_SET_ORDER);
        $filas = [];
        foreach ($m as $f) $filas[(int) $f[1]] = $f[0];
        return $filas;
    }

    private function datosDeAlta(array $extra = []): array
    {
        return $extra + ['nombre' => 'Nueva Persona', 'email' => 'nueva@test.local', 'password' => self::CLAVE, 'estado' => 'activo'];
    }

    // ─── Listas ─────────────────────────────────────────────────────────────

    public function test_la_lista_de_administradores_trae_solo_administradores(): void
    {
        $this->assertTrue($this->llamar('administradores') === null);

        $this->assertSame('admin/usuarios', AdminControllerObservable::$vista);
        $this->assertSame('/admin/administradores', AdminControllerObservable::$datos['ctx']['ruta']);
        $roles = array_unique(array_column(AdminControllerObservable::$datos['usuarios'], 'rol_nombre'));
        $this->assertSame(['administrador'], array_values($roles));

        $html = AdminControllerObservable::$html;
        $this->assertStringContainsString('<h1>Administradores</h1>', $html);
        $this->assertStringContainsString('+ Nuevo administrador', $html);
        $this->assertStringContainsString('admin2@test.local', $html);
        $this->assertStringNotContainsString('organizador', strtolower(strip_tags($html)), 'ni una cuenta de otro rol, ni el nombre del otro apartado');
    }

    public function test_la_lista_de_organizadores_no_mezcla_administradores(): void
    {
        $this->assertTrue($this->llamar('usuarios') === null);

        $roles = array_unique(array_column(AdminControllerObservable::$datos['usuarios'], 'rol_nombre'));
        $this->assertSame(['organizador'], array_values($roles));
        $html = AdminControllerObservable::$html;
        $this->assertStringContainsString('<h1>Organizadores</h1>', $html);
        $this->assertStringNotContainsString('admin2@test.local', $html);
        $this->assertStringContainsString('href="/admin/organizadores/crear"', $html);
    }

    public function test_en_la_lista_la_cuenta_propia_se_marca_y_no_ofrece_desactivarse_ni_bloquearse(): void
    {
        $this->llamar('administradores');
        $filas = $this->filasPorId(AdminControllerObservable::$html);

        $propia = $filas[Fixtures::ADMIN];
        $this->assertStringContainsString('Vos', $propia);
        $this->assertStringContainsString('Es tu cuenta', $propia);
        $this->assertStringNotContainsString('<form', $propia, 'ni Desactivar ni Bloquear sobre uno mismo');
        $this->assertStringContainsString('/admin/administradores/editar/' . Fixtures::ADMIN, $propia, 'pero sí puede editarse');

        $ajena = $filas[$this->otroAdmin];
        $this->assertStringNotContainsString('Vos', $ajena);
        $this->assertStringContainsString('/admin/administradores/eliminar/' . $this->otroAdmin, $ajena);
        $this->assertStringContainsString('/admin/administradores/bloquear/' . $this->otroAdmin, $ajena);
        $this->assertStringContainsString('Desactivar', $ajena);
    }

    public function test_el_unico_administrador_activo_no_ofrece_desactivarse_ni_bloquearse(): void
    {
        // Quien mira la pantalla es otra cuenta con acceso de administración: en el sistema real
        // no hay quien pueda abrirla sin ser administrador, así que se arma el caso con la vista.
        Fixtures::loguearComo($this->otroAdmin);
        $usuarios = [$this->usuario(Fixtures::ADMIN)];
        $ctx = ['singular' => 'administrador', 'plural' => 'Administradores', 'ruta' => '/admin/administradores', 'descripcion' => 'x'];

        $html = $this->renderVista('admin/usuarios', ['ctx' => $ctx, 'usuarios' => $usuarios, 'unicoAdminActivoId' => Fixtures::ADMIN]);
        $this->assertStringContainsString('Único administrador activo', $html);
        // (la barra de búsqueda también es un <form>, pero de GET: lo que no debe haber es un POST)
        $this->assertStringNotContainsString('method="POST"', $html);

        $html = $this->renderVista('admin/usuarios', ['ctx' => $ctx, 'usuarios' => $usuarios, 'unicoAdminActivoId' => 0]);
        $this->assertStringNotContainsString('Único administrador activo', $html);
        $this->assertStringContainsString('/admin/administradores/eliminar/' . Fixtures::ADMIN, $html, 'con más de uno activo, sí se ofrece');
    }

    private function renderVista(string $vista, array $datos): string
    {
        // La lista lleva barra de búsqueda y paginación: sin filtros y con todo en una página.
        $datos += [
            'filtros' => ['q' => '', 'estado' => ''],
            'pag'     => Paginador::de(count($datos['usuarios'] ?? []), 1, 15),
            'estados' => ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'bloqueada' => 'Bloqueada'],
        ];
        ob_start();
        try { View::render($vista, $datos, ''); } finally { $html = (string) ob_get_clean(); }
        return $html;
    }

    public function test_una_cuenta_bloqueada_muestra_hasta_cuando_y_solo_se_desbloquea(): void
    {
        $this->bloquearEnLaBase($this->otroAdmin, date('Y-m-d') . ' 14:35:00');
        $this->llamar('administradores');
        $fila = $this->filasPorId(AdminControllerObservable::$html)[$this->otroAdmin];

        $this->assertStringContainsString('Hasta las 14:35', $fila);
        $this->assertStringContainsString('/admin/administradores/desbloquear/' . $this->otroAdmin, $fila);
        $this->assertStringContainsString('name="return" value="/admin/administradores"', $fila);
        $this->assertStringNotContainsString('/eliminar/', $fila, 'no se puede «activar» una cuenta bloqueada sin desbloquearla');
        $this->assertStringNotContainsString('/bloquear/', $fila);

        $this->bloquearEnLaBase($this->otroAdmin, null);
        $this->llamar('administradores');
        $this->assertStringContainsString('Hasta que se desbloquee', $this->filasPorId(AdminControllerObservable::$html)[$this->otroAdmin]);
    }

    public function test_el_servicio_dice_cual_es_el_unico_administrador_activo(): void
    {
        $svc = new UsuarioService();
        $this->assertSame(0, $svc->unicoAdministradorActivoId(), 'hay dos activos');

        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = {$this->otroAdmin}");
        $this->assertSame(Fixtures::ADMIN, $svc->unicoAdministradorActivoId());

        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = " . Fixtures::ADMIN);
        $this->assertSame(0, $svc->unicoAdministradorActivoId(), 'ninguno activo');
    }

    // ─── Formularios ────────────────────────────────────────────────────────

    public function test_el_formulario_de_alta_fija_el_rol_del_apartado(): void
    {
        $this->assertTrue($this->llamar('administradorForm') === null);

        $html = AdminControllerObservable::$html;
        $this->assertStringContainsString('<h1>Nuevo administrador</h1>', $html);
        $this->assertStringContainsString('value="Administrador" disabled', $html, 'el rol se muestra pero no se elige');
        $this->assertStringNotContainsString('name="rol_id"', $html);
        $this->assertStringContainsString('action="/admin/administradores/crear"', $html);
        $this->assertStringContainsString('Crear administrador', $html);
        $this->assertStringContainsString('name="password" required', $html);
    }

    public function test_el_formulario_de_edicion_carga_la_cuenta_y_la_contrasena_es_opcional(): void
    {
        $this->llamar('administradorForm', [(string) $this->otroAdmin]);

        $html = AdminControllerObservable::$html;
        $this->assertStringContainsString('<h1>Editar administrador</h1>', $html);
        $this->assertStringContainsString('value="admin2@test.local"', $html);
        $this->assertStringContainsString('action="/admin/administradores/editar/' . $this->otroAdmin . '"', $html);
        $this->assertStringContainsString('dejar en blanco para no cambiar', $html);
        $this->assertStringNotContainsString('name="password" required', $html);
        $this->assertStringContainsString('Guardar cambios', $html);
    }

    public function test_el_formulario_de_una_cuenta_bloqueada_no_deja_cambiar_el_estado(): void
    {
        $this->bloquearEnLaBase($this->otroAdmin, null);
        $this->llamar('administradorForm', [(string) $this->otroAdmin]);

        $html = AdminControllerObservable::$html;
        $this->assertStringContainsString('<input type="hidden" name="estado" value="bloqueada">', $html);
        $this->assertStringNotContainsString('<select name="estado">', $html, 'el único select de estado está deshabilitado');
    }

    public function test_cada_apartado_rechaza_abrir_el_formulario_de_cuentas_de_otro_rol(): void
    {
        $r = $this->llamar('usuarioForm', [(string) Fixtures::ADMIN]);       // un administrador, en Organizadores
        $this->assertTrue($r instanceof RedirigidoA);
        $this->assertSame('/admin/organizadores', $r->url);
        $this->assertSame('Este apartado solo gestiona organizadores.', Session::getFlash('error'));

        $r = $this->llamar('administradorForm', [(string) Fixtures::ORGANIZADOR]);   // un organizador, en Administradores
        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Este apartado solo gestiona administradores.', Session::getFlash('error'));
    }

    // ─── Alta y edición ─────────────────────────────────────────────────────

    public function test_se_crea_un_administrador_con_la_contrasena_cifrada(): void
    {
        $r = $this->llamar('administradorGuardar', [], $this->datosDeAlta());

        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Administrador creado correctamente.', Session::getFlash('success'));

        $st = $this->db->prepare("SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.email = 'nueva@test.local'");
        $st->execute();
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('administrador', $u['rol_nombre']);
        $this->assertSame('activo', $u['estado']);
        $this->assertTrue(password_verify(self::CLAVE, $u['password_hash']), 'la contraseña quedó cifrada');
        $this->assertNotSame(self::CLAVE, $u['password_hash']);
    }

    public function test_el_rol_lo_fija_el_apartado_no_el_formulario(): void
    {
        // Un POST armado a mano con otro rol_id no cambia nada: el rol sale del apartado.
        $this->llamar('administradorGuardar', [], $this->datosDeAlta(['rol_id' => '2', 'email' => 'a@test.local']));
        $this->llamar('usuarioGuardar', [], $this->datosDeAlta(['rol_id' => '1', 'email' => 'b@test.local']));

        $this->assertSame('administrador', $this->rolDe('a@test.local'));
        $this->assertSame('organizador', $this->rolDe('b@test.local'));
    }

    private function rolDe(string $email): string
    {
        $st = $this->db->prepare('SELECT r.nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.email = ?');
        $st->execute([$email]);
        return (string) $st->fetchColumn();
    }

    public function test_un_alta_invalida_vuelve_al_formulario_con_el_motivo(): void
    {
        $r = $this->llamar('administradorGuardar', [], $this->datosDeAlta(['password' => 'floja']));
        $this->assertSame('/admin/administradores/crear', $r->url);
        $this->assertTrue((string) Session::getFlash('error') !== '');
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE email = 'nueva@test.local'")->fetchColumn());

        $r = $this->llamar('administradorGuardar', [], $this->datosDeAlta(['email' => 'admin2@test.local']));
        $this->assertStringContainsString('ya está registrado', (string) Session::getFlash('error'));
    }

    public function test_se_edita_un_administrador_y_la_contrasena_en_blanco_no_cambia(): void
    {
        $antes = $this->usuario($this->otroAdmin)['password_hash'];

        $r = $this->llamar('administradorGuardar', [(string) $this->otroAdmin], [
            'nombre' => 'Nombre Nuevo', 'email' => 'admin2@test.local', 'password' => '', 'estado' => 'activo',
        ]);

        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Administrador actualizado correctamente.', Session::getFlash('success'));
        $u = $this->usuario($this->otroAdmin);
        $this->assertSame('Nombre Nuevo', $u['nombre']);
        $this->assertSame('administrador', $u['rol_nombre']);
        $this->assertSame($antes, $u['password_hash']);
    }

    public function test_el_hueco_de_antes_no_se_puede_degradar_a_un_administrador_desde_organizadores(): void
    {
        // Antes: POST /admin/organizadores/editar/{id de un administrador} lo dejaba como organizador.
        $r = $this->llamar('usuarioGuardar', [(string) $this->otroAdmin], [
            'nombre' => 'Degradada', 'email' => 'admin2@test.local', 'password' => '', 'estado' => 'activo',
        ]);

        $this->assertSame('/admin/organizadores/editar/' . $this->otroAdmin, $r->url);
        $this->assertSame('Este apartado solo gestiona organizadores.', Session::getFlash('error'));
        $u = $this->usuario($this->otroAdmin);
        $this->assertSame('administrador', $u['rol_nombre'], 'sigue siendo administrador');
        $this->assertSame('Segunda Administradora', $u['nombre'], 'y no se tocó ningún dato');
    }

    public function test_tampoco_se_asciende_a_un_organizador_desde_administradores(): void
    {
        $r = $this->llamar('administradorGuardar', [(string) Fixtures::ORGANIZADOR], [
            'nombre' => 'Ascendido', 'email' => $this->usuario(Fixtures::ORGANIZADOR)['email'], 'password' => '', 'estado' => 'activo',
        ]);

        $this->assertSame('/admin/administradores/editar/' . Fixtures::ORGANIZADOR, $r->url);
        $this->assertSame('organizador', $this->usuario(Fixtures::ORGANIZADOR)['rol_nombre']);
    }

    public function test_editar_una_cuenta_que_no_existe_avisa(): void
    {
        $r = $this->llamar('administradorGuardar', ['999999'], $this->datosDeAlta());
        $this->assertSame('/admin/administradores/editar/999999', $r->url);
        $this->assertTrue((string) Session::getFlash('error') !== '');
    }

    // ─── Protecciones ───────────────────────────────────────────────────────

    public function test_un_administrador_no_puede_desactivarse_ni_degradarse_a_si_mismo_editando(): void
    {
        $propio = $this->usuario(Fixtures::ADMIN);
        $r = $this->llamar('administradorGuardar', [(string) Fixtures::ADMIN], [
            'nombre' => $propio['nombre'], 'email' => $propio['email'], 'password' => '', 'estado' => 'inactivo',
        ]);

        $this->assertSame('/admin/administradores/editar/' . Fixtures::ADMIN, $r->url);
        $this->assertStringContainsString('No podés quitarte el rol de administrador ni desactivar tu propia cuenta', (string) Session::getFlash('error'));
        $this->assertSame('activo', $this->usuario(Fixtures::ADMIN)['estado']);
    }

    public function test_se_desactiva_y_reactiva_otro_administrador(): void
    {
        $r = $this->llamar('administradorEliminar', [(string) $this->otroAdmin], []);
        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Administrador desactivado.', Session::getFlash('success'));
        $this->assertSame('inactivo', $this->usuario($this->otroAdmin)['estado']);

        $this->llamar('administradorEliminar', [(string) $this->otroAdmin], []);
        $this->assertSame('Administrador reactivado.', Session::getFlash('success'));
        $this->assertSame('activo', $this->usuario($this->otroAdmin)['estado']);
    }

    public function test_no_se_desactiva_la_propia_cuenta(): void
    {
        $this->llamar('administradorEliminar', [(string) Fixtures::ADMIN], []);

        $this->assertSame('No podés cambiar el estado de tu propia cuenta.', Session::getFlash('error'));
        $this->assertSame('activo', $this->usuario(Fixtures::ADMIN)['estado']);
    }

    public function test_el_sistema_no_se_queda_sin_administradores_activos(): void
    {
        $svc = new UsuarioService();
        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = {$this->otroAdmin}");   // queda uno solo
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);     // lo pide otra cuenta, no la suya

        $this->assertThrows(fn() => $svc->toggleActivo(Fixtures::ADMIN), 'único administrador activo', 'desactivarlo');
        $this->assertThrows(fn() => $svc->bloquear(Fixtures::ADMIN), 'único administrador activo', 'bloquearlo');
        $this->assertSame('activo', $this->usuario(Fixtures::ADMIN)['estado']);
    }

    public function test_cada_apartado_solo_activa_o_desactiva_cuentas_de_su_rol(): void
    {
        $r = $this->llamar('administradorEliminar', [(string) Fixtures::ORGANIZADOR], []);     // un organizador, en Administradores
        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Este apartado solo gestiona administradores.', Session::getFlash('error'));
        $this->assertSame('activo', $this->usuario(Fixtures::ORGANIZADOR)['estado'], 'no se tocó');

        $this->llamar('usuarioEliminar', [(string) $this->otroAdmin], []);                      // un administrador, en Organizadores
        $this->assertSame('Este apartado solo gestiona organizadores.', Session::getFlash('error'));
        $this->assertSame('activo', $this->usuario($this->otroAdmin)['estado']);

        // y en su propio apartado, el organizador sí se desactiva, con el mensaje de siempre
        $this->llamar('usuarioEliminar', [(string) Fixtures::ORGANIZADOR], []);
        $this->assertSame('Organizador desactivado.', Session::getFlash('success'));
        $this->assertSame('inactivo', $this->usuario(Fixtures::ORGANIZADOR)['estado']);
    }

    // ─── Bloqueo desde el apartado ──────────────────────────────────────────

    public function test_se_bloquea_y_desbloquea_a_un_administrador_y_se_vuelve_a_su_apartado(): void
    {
        $r = $this->llamar('usuarioBloquear', [(string) $this->otroAdmin], ['return' => '/admin/administradores']);
        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('Cuenta bloqueada correctamente.', Session::getFlash('success'));
        $this->assertSame('bloqueada', $this->usuario($this->otroAdmin)['estado']);

        $r = $this->llamar('usuarioDesbloquear', [(string) $this->otroAdmin], ['return' => '/admin/administradores']);
        $this->assertSame('/admin/administradores', $r->url);
        $this->assertSame('activo', $this->usuario($this->otroAdmin)['estado']);
    }

    public function test_el_destino_de_vuelta_sigue_siendo_solo_del_propio_sitio(): void
    {
        $r = $this->llamar('usuarioBloquear', [(string) $this->otroAdmin], ['return' => 'https://ajeno.example/admin/administradores']);
        $this->assertSame('/admin/organizadores', $r->url, 'un destino externo se descarta');
    }

    public function test_no_se_bloquea_la_propia_cuenta(): void
    {
        $this->llamar('usuarioBloquear', [(string) Fixtures::ADMIN], ['return' => '/admin/administradores']);

        $this->assertSame('No podés bloquear tu propia cuenta.', Session::getFlash('error'));
        $this->assertSame('activo', $this->usuario(Fixtures::ADMIN)['estado']);
    }

    // ─── Rutas ──────────────────────────────────────────────────────────────

    public function test_las_rutas_del_apartado_existen_y_apuntan_a_las_acciones_de_administradores(): void
    {
        $rutas = (string) file_get_contents(BASE_PATH . '/config/routes.php');
        foreach ([
            "get('admin/administradores',"                 => 'administradores',
            "get('admin/administradores/crear',"           => 'administradorForm',
            "post('admin/administradores/crear',"          => 'administradorGuardar',
            "get('admin/administradores/editar/{id}',"     => 'administradorForm',
            "post('admin/administradores/editar/{id}',"    => 'administradorGuardar',
            "post('admin/administradores/eliminar/{id}',"  => 'administradorEliminar',
            "post('admin/administradores/desbloquear/{id}'," => 'usuarioDesbloquear',
            "post('admin/administradores/bloquear/{id}',"  => 'usuarioBloquear',
        ] as $registro => $accion) {
            $this->assertTrue(preg_match('#\$router->' . preg_quote($registro, '#') . "\s*'AdminController',\s*'" . $accion . "'\)#", $rutas) === 1,
                "falta la ruta {$registro} → {$accion}");
        }
        $this->assertStringContainsString('href="/admin/administradores"', (string) file_get_contents(APP_PATH . '/views/partials/sidebar.php'), 'y el menú lateral la ofrece');
    }
}

TestCase::correrSiEsScript(AdministradoresTest::class);
