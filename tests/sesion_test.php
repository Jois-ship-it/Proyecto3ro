<?php
declare(strict_types=1);

/**
 * Test de la sesión: su vida útil y la revalidación de la cuenta contra la base.
 *
 * Qué pasaba antes:
 *   - La sesión nunca se comparaba con la base: el rol y el estado salían de lo que se guardó al
 *     iniciarla. Una cuenta bloqueada, desactivada, borrada o con el rol cambiado seguía operando
 *     con los permisos de antes hasta que la sesión expirara por su cuenta.
 *   - La sesión no vencía por inactividad y no se regeneraba nunca, aunque la documentación decía
 *     «cada 30 minutos». Y la documentación decía SameSite Strict mientras el código usaba Lax.
 *
 * Qué se comprueba:
 *   1. La política de vida (Session::evaluarVida), como función pura, sin sesiones reales.
 *   2. Que Auth revalide contra la base: rol y datos puestos al día, y sesión cerrada con un aviso
 *      cuando la cuenta ya no está activa (bloqueada, desactivada, pendiente, rechazada, borrada).
 *   3. Que lo anterior se consulte una sola vez por pedido.
 *   4. Que código y documentación digan lo mismo.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/sesion_test.php
 */

require __DIR__ . '/bootstrap.php';

// La vista de login necesita las constantes que normalmente define public/index.php.
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

class SesionTest extends TestCase
{
    private int $uid = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        $this->uid = Fixtures::usuario('Persona Sesion', 'sesion@test.local', 'Clave-Segura-9876!', 2);
        Fixtures::loguearComo($this->uid);
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
    }

    private function fuente(string $ruta): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $ruta);
    }

    /** Un nuevo «pedido»: la sesión sigue, pero se vuelve a consultar la cuenta. */
    private function nuevoPedido(): void
    {
        Auth::olvidarRevalidacion();
    }

    // ─── 1. Vida de la sesión ───────────────────────────────────────────────

    public function test_una_sesion_sin_usuario_no_vence_ni_se_regenera(): void
    {
        $this->assertSame('anonima', Session::evaluarVida([], 1_000_000));
        $this->assertSame('anonima', Session::evaluarVida(['csrf_token' => 'x', '_ultima_actividad' => 1], 9_999_999));
    }

    public function test_una_sesion_reciente_esta_vigente(): void
    {
        $ahora = 1_000_000;
        $this->assertSame('vigente', Session::evaluarVida(
            ['user_id' => 1, '_ultima_actividad' => $ahora - 30, '_last_regenerated' => $ahora - 30], $ahora));
    }

    public function test_pasado_el_tiempo_sin_actividad_la_sesion_vence(): void
    {
        $ahora = 1_000_000;
        $sesion = fn(int $inactiva) => ['user_id' => 1, '_ultima_actividad' => $ahora - $inactiva, '_last_regenerated' => $ahora - 10];

        $this->assertSame('vigente', Session::evaluarVida($sesion(Session::INACTIVIDAD_SEGUNDOS), $ahora), 'justo en el límite todavía vale');
        $this->assertSame('vencida', Session::evaluarVida($sesion(Session::INACTIVIDAD_SEGUNDOS + 1), $ahora));
    }

    public function test_cada_tanto_se_cambia_el_identificador_de_la_sesion(): void
    {
        $ahora = 1_000_000;
        $conRegeneracion = fn(int $hace) => ['user_id' => 1, '_ultima_actividad' => $ahora - 5, '_last_regenerated' => $ahora - $hace];

        $this->assertSame('vigente', Session::evaluarVida($conRegeneracion(Session::REGENERAR_CADA_SEGUNDOS), $ahora));
        $this->assertSame('regenerar', Session::evaluarVida($conRegeneracion(Session::REGENERAR_CADA_SEGUNDOS + 1), $ahora));
        $this->assertSame('regenerar', Session::evaluarVida(['user_id' => 1, '_ultima_actividad' => $ahora], $ahora),
            'una sesión que nunca se regeneró (anterior a esta regla) se regenera ya');
    }

    public function test_si_la_sesion_vencio_no_importa_que_tambien_toque_regenerarla(): void
    {
        $ahora = 1_000_000;
        $this->assertSame('vencida', Session::evaluarVida(
            ['user_id' => 1, '_ultima_actividad' => $ahora - Session::INACTIVIDAD_SEGUNDOS - 1, '_last_regenerated' => 0], $ahora));
    }

    // ─── 2. La cuenta manda ─────────────────────────────────────────────────

    public function test_una_cuenta_activa_mantiene_la_sesion(): void
    {
        $this->assertTrue(Auth::isLoggedIn());
        $this->assertSame($this->uid, Auth::id());
        $this->assertSame('organizador', Auth::rol());
    }

    public function test_el_rol_y_los_datos_los_decide_la_base_no_lo_que_se_guardo_al_entrar(): void
    {
        // Antes: el rol seguía siendo «organizador» en la sesión hasta que expirara.
        $this->db->exec("UPDATE usuarios SET rol_id = 1, nombre = 'Nombre Nuevo', email = 'nuevo.sesion@test.local' WHERE id = {$this->uid}");
        $this->nuevoPedido();

        $this->assertSame('administrador', Auth::rol(), 'el ascenso se nota en el próximo pedido');
        $this->assertTrue(Auth::isAdmin());
        $this->assertSame('Nombre Nuevo', Auth::user()['nombre']);
        $this->assertSame('nuevo.sesion@test.local', Auth::user()['email']);

        // Y al revés: un administrador degradado deja de serlo enseguida.
        $this->db->exec("UPDATE usuarios SET rol_id = 3 WHERE id = {$this->uid}");
        $this->nuevoPedido();
        $this->assertFalse(Auth::isAdmin());
        $this->assertFalse(Auth::isOrganizador());
        $this->assertTrue(Auth::isParticipante());
    }

    public function test_una_cuenta_que_deja_de_estar_activa_pierde_la_sesion_con_un_aviso(): void
    {
        foreach (['bloqueada', 'inactivo', 'suspendido', 'pendiente', 'rechazado'] as $estado) {
            Fixtures::loguearComo($this->uid);
            $this->assertTrue(Auth::isLoggedIn(), "control: con la cuenta activa se entra ({$estado})");

            $this->db->exec("UPDATE usuarios SET estado = '{$estado}' WHERE id = {$this->uid}");
            $this->nuevoPedido();

            $this->assertFalse(Auth::isLoggedIn(), "una cuenta «{$estado}» no sigue operando");
            $this->assertFalse(Session::has('user_id'), 'la sesión se cerró');
            $this->assertSame(null, Auth::id());
            $this->assertSame(null, Auth::user());
            $this->assertSame(null, Auth::rol());
            $this->assertTrue(Session::hasFlash('warning'), 'y la persona recibe el motivo en vez de una pantalla en blanco');
            Session::getFlash('warning');

            $this->db->exec("UPDATE usuarios SET estado = 'activo' WHERE id = {$this->uid}");
        }
    }

    public function test_una_cuenta_borrada_pierde_la_sesion(): void
    {
        $this->db->exec("DELETE FROM usuarios WHERE id = {$this->uid}");
        $this->nuevoPedido();

        $this->assertFalse(Auth::isLoggedIn());
        $this->assertStringContainsString('ya no existe', (string) Session::getFlash('warning'));
    }

    public function test_el_aviso_dice_si_fue_un_bloqueo(): void
    {
        $this->db->exec("UPDATE usuarios SET estado = 'bloqueada' WHERE id = {$this->uid}");
        $this->nuevoPedido();
        Auth::isLoggedIn();

        $aviso = (string) Session::getFlash('warning');
        $this->assertStringContainsString('bloqueada', $aviso);
        $this->assertStringContainsString('administrador', $aviso, 'le dice a quién recurrir');
    }

    public function test_quien_pierde_la_sesion_deja_de_figurar_como_autor_de_los_cambios(): void
    {
        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = {$this->uid}");
        $this->nuevoPedido();
        Auth::isLoggedIn();

        $this->assertSame(null, $this->db->query('SELECT @id_usuario_app')->fetchColumn(),
            'lo que se escriba después no se atribuye a una cuenta que ya no está activa');
    }

    public function test_la_pantalla_de_login_muestra_el_motivo_por_el_que_se_cerro_la_sesion(): void
    {
        // El aviso se guarda como «warning» (el «error» lo pisa requireLogin() con «Debés iniciar
        // sesión»), así que la pantalla de login tiene que dibujar los dos.
        ob_start();
        View::render('auth/login', [
            '_flash' => ['warning' => 'Tu cuenta fue bloqueada MOTIVO_VISIBLE', 'error' => 'Debés iniciar sesión ERROR_VISIBLE'],
        ], '');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('MOTIVO_VISIBLE', $html, 'el motivo se ve');
        $this->assertStringContainsString('ERROR_VISIBLE', $html, 'y el aviso de siempre también');
        $this->assertTrue(strpos($html, 'MOTIVO_VISIBLE') < strpos($html, 'ERROR_VISIBLE'), 'el motivo va primero');
    }

    public function test_cerrar_sesion_deja_una_sesion_nueva_y_vacia_donde_cabe_el_aviso_de_despedida(): void
    {
        // Antes se destruía la sesión y DESPUÉS se escribía el aviso en ella: el aviso se perdía y nadie veía
        // «Sesión cerrada correctamente». Y destruir + empezar otra mandaba dos cookies (una vencida y una nueva) en la misma respuesta.
        $_SESSION['otro_dato'] = 'x';
        $antes = session_id();

        Auth::logout();

        $this->assertSame(null, Session::get('user_id'), 'ya no hay nadie con la sesión iniciada');
        $this->assertFalse(isset($_SESSION['otro_dato']), 'ni nada de lo que había');
        Session::flash('success', 'Sesión cerrada correctamente.');
        $this->assertSame('Sesión cerrada correctamente.', Session::getFlash('success'), 'el aviso se guarda y se lee');
        if (!headers_sent()) {
            // Solo si se pueden mandar cookies: con la salida ya impresa (el runner propio) PHP no abre ni cambia sesiones en consola,
            // y otros tests de este archivo ya cerraron la que había.
            $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'la sesión sigue abierta, vacía: ahí viaja el aviso');
            $this->assertNotSame($antes, session_id(), 'otro identificador: lo de antes ya no sirve');
        }
    }

    public function test_el_cierre_de_sesion_no_usa_destroy_porque_perderia_el_aviso(): void
    {
        $auth = (string) file_get_contents(BASE_PATH . '/core/Auth.php');
        preg_match('/public static function logout\(\): void\s*\{(.*?)\n    \}/s', $auth, $m);
        $this->assertTrue(isset($m[1]) && str_contains($m[1], 'Session::reiniciar()'), 'Auth::logout() reinicia la sesión');
        $this->assertStringNotContainsString('Session::destroy()', $m[1] ?? '', 'y no la destruye: el aviso de despedida iría a una sesión muerta');
    }

    public function test_la_tabla_de_motivos_de_expulsion(): void
    {
        $this->assertSame(null, Auth::motivoDeExpulsion(['estado' => 'activo']));
        $this->assertStringContainsString('bloqueada', (string) Auth::motivoDeExpulsion(['estado' => 'bloqueada']));
        $this->assertStringContainsString('ya no existe', (string) Auth::motivoDeExpulsion(null));
        foreach (['inactivo', 'suspendido', 'pendiente', 'rechazado', 'algo_nuevo'] as $estado) {
            $this->assertStringContainsString('no está activa', (string) Auth::motivoDeExpulsion(['estado' => $estado]),
                "«{$estado}» no es una cuenta activa");
        }
    }

    // ─── 3. Una consulta por pedido ─────────────────────────────────────────

    public function test_la_cuenta_se_consulta_una_sola_vez_por_pedido(): void
    {
        $this->assertTrue(Auth::isLoggedIn());

        // Mitad de pedido: la base cambia, pero este pedido ya comprobó la cuenta.
        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = {$this->uid}");
        $this->assertTrue(Auth::isLoggedIn(), 'no se vuelve a consultar dentro del mismo pedido');
        $this->assertSame($this->uid, Auth::id());

        $this->nuevoPedido();
        $this->assertFalse(Auth::isLoggedIn(), 'el pedido siguiente sí lo ve');
    }

    public function test_sin_sesion_no_se_consulta_nada(): void
    {
        Fixtures::cerrarSesion();

        $this->assertFalse(Auth::isLoggedIn());
        $this->assertSame(null, Auth::id());
        $this->assertSame(null, Auth::rol());
    }

    // ─── 4. Código y documentación ──────────────────────────────────────────

    public function test_los_tiempos_son_coherentes_entre_si(): void
    {
        $this->assertGreaterThan(Session::REGENERAR_CADA_SEGUNDOS, Session::INACTIVIDAD_SEGUNDOS,
            'el identificador se cambia varias veces durante el tiempo que la sesión puede estar inactiva');

        // El archivo de sesión tiene que vivir al menos lo que dura la inactividad permitida: si PHP
        // lo borrara antes, la sesión se perdería sin que el vencimiento se enterara.
        preg_match("/session\\.gc_maxlifetime',\\s*'(\\d+)'/", $this->fuente('core/Session.php'), $m);
        $this->assertGreaterThan(Session::INACTIVIDAD_SEGUNDOS - 1, (int) ($m[1] ?? 0));
    }

    public function test_samesite_dice_lo_mismo_en_el_codigo_y_en_la_documentacion(): void
    {
        $this->assertStringContainsString("session.cookie_samesite', 'Lax'", $this->fuente('core/Session.php'));

        $seguridad = $this->fuente('docs/documentacion_seguridad.md');
        $this->assertStringContainsString('session.cookie_samesite = Lax', $seguridad);
        $this->assertStringNotContainsString('samesite = Strict', $seguridad,
            'la documentación decía Strict mientras el código usaba Lax');
    }

    public function test_la_documentacion_dice_lo_que_la_sesion_hace(): void
    {
        $minutos = Session::REGENERAR_CADA_SEGUNDOS / 60;
        $this->assertStringContainsString("cada {$minutos} minutos", $this->fuente('docs/documentacion_seguridad.md'));
        $this->assertStringContainsString("cada {$minutos} minutos", $this->fuente('docs/owasp.md'));
        $this->assertStringContainsString('inactividad', $this->fuente('docs/documentacion_seguridad.md'));
    }
}

TestCase::correrSiEsScript(SesionTest::class);
