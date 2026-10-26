<?php
declare(strict_types=1);

/**
 * Test de la Política de Privacidad y los Términos y Condiciones al registrarse.
 *
 * Quien se registra tiene que dar su consentimiento para el tratamiento de sus datos y para que se vean como dice la
 * política (nombre, nick, foto, equipos, resultados y estadísticas, para otros participantes, organizadores,
 * administradores y cualquier visitante). Lo que se prueba:
 *
 *   1. SERVIDOR: sin la casilla marcada no se crea ni la cuenta ni el perfil, aunque el formulario se envíe a mano.
 *      La casilla del navegador (y registro.js) es solo la primera barrera; la que manda es RegistroService.
 *   2. EVIDENCIA: el registro deja en la auditoría la versión de la política que se aceptó.
 *   3. PÁGINAS: /privacidad y /terminos existen, son públicas (se leen ANTES de tener cuenta) y dicen lo que el
 *      sistema hace de verdad (quién ve qué).
 *   4. FORMULARIO: el registro trae la casilla obligatoria y los enlaces; el login NO muestra nada de esto.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/politicas_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

final class RedirigidoA extends Exception {}

final class AuthObservable extends AuthController
{
    public static string $html = '';

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        ob_start();
        try {
            parent::render($view, $data, $layout);
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never { throw new RedirigidoA($url); }
}

final class PublicoObservable extends PublicoController
{
    public static string $html = '';

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        ob_start();
        try {
            parent::render($view, $data, $layout);
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }
}

class PoliticasTest extends TestCase
{
    private const CLAVE = 'Clave-Segura-123!';

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::cerrarSesion();
        $_POST = [];
        AuthObservable::$html = '';
        PublicoObservable::$html = '';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Fixtures::cerrarSesion();
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Persona Que Acepta', 'email' => 'acepta.pol' . Fixtures::DOMINIO_TEST, 'nick' => 'acepta',
            'password' => self::CLAVE, 'password_confirm' => self::CLAVE,
        ], $cambios);
    }

    private function cuentas(string $email): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        return (int) $st->fetchColumn();
    }

    private function perfiles(string $email): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM participantes WHERE email = ?');
        $st->execute([$email]);
        return (int) $st->fetchColumn();
    }

    // ─── 1. El servidor exige la aceptación ─────────────────────────────────

    public function test_sin_aceptar_no_se_crea_ni_la_cuenta_ni_el_perfil(): void
    {
        $d = $this->datos();
        foreach ([null, '', '0', 'no', 'false', 0, false, [], ['1']] as $valor) {
            $datos = $valor === null ? $d : $d + ['acepto_politicas' => $valor];
            $e = $this->assertThrows(fn() => (new RegistroService())->registrar($datos), Politicas::MENSAJE_FALTA_ACEPTAR);
            $this->assertTrue($e instanceof ErrorNegocio, 'es un error con mensaje para la persona, no un fallo técnico');
        }
        $this->assertSame(0, $this->cuentas($d['email']), 'ninguna cuenta');
        $this->assertSame(0, $this->perfiles($d['email']), 'ningún perfil');
    }

    public function test_aceptando_se_crea_la_cuenta_pendiente_con_su_perfil(): void
    {
        foreach (['1', 'on', true, 1] as $i => $valor) {
            $d = $this->datos(['email' => "acepta{$i}" . Fixtures::DOMINIO_TEST, 'acepto_politicas' => $valor]);
            $id = (new RegistroService())->registrar($d);
            $this->assertGreaterThan(0, $id);
            $this->assertSame(1, $this->cuentas($d['email']));
            $this->assertSame(1, $this->perfiles($d['email']));
        }
    }

    public function test_la_aceptacion_se_lee_con_estrictez(): void
    {
        $this->assertTrue(Politicas::aceptada('1'));
        $this->assertTrue(Politicas::aceptada('on'));
        $this->assertTrue(Politicas::aceptada(true));
        $this->assertFalse(Politicas::aceptada(''));
        $this->assertFalse(Politicas::aceptada('0'));
        $this->assertFalse(Politicas::aceptada(null));
        $this->assertFalse(Politicas::aceptada(false));
        $this->assertFalse(Politicas::aceptada(['1']));
        $this->assertFalse(Politicas::aceptada('quizás'));
    }

    public function test_el_controlador_rechaza_el_envio_sin_la_casilla_y_vuelve_al_formulario(): void
    {
        $d = $this->datos();
        $_POST = $d + ['csrf_token' => Csrf::generate()];     // el formulario enviado a mano, sin la casilla
        try {
            (new AuthObservable())->registro();
            $this->fallar('tenía que redirigir');
        } catch (RedirigidoA $r) {
            $this->assertSame('/registro', $r->getMessage(), 'vuelve al formulario');
        }
        $this->assertSame(Politicas::MENSAJE_FALTA_ACEPTAR, Session::getFlash('error'), 'y le dice por qué');
        $this->assertSame(0, $this->cuentas($d['email']));
    }

    public function test_el_controlador_crea_la_cuenta_cuando_se_acepta(): void
    {
        $d = $this->datos();
        $_POST = $d + ['acepto_politicas' => '1', 'csrf_token' => Csrf::generate()];
        try {
            (new AuthObservable())->registro();
            $this->fallar('tenía que redirigir');
        } catch (RedirigidoA $r) {
            $this->assertSame('/login', $r->getMessage(), 'queda pendiente de aprobación: va al login');
        }
        $this->assertSame(1, $this->cuentas($d['email']));
    }

    public function test_al_volver_con_un_error_se_conserva_lo_escrito_pero_la_casilla_no_queda_marcada(): void
    {
        // Lo ya escrito se conserva (menos las claves), pero el consentimiento se da de nuevo cada vez.
        $_POST = $this->datos(['email' => 'mal-escrito']) + ['acepto_politicas' => '1', 'csrf_token' => Csrf::generate()];
        try {
            (new AuthObservable())->registro();
            $this->fallar('tenía que redirigir');
        } catch (RedirigidoA $r) {
            $this->assertSame('/registro', $r->getMessage());
        }
        (new AuthObservable())->registroForm();
        $html = AuthObservable::$html;
        $this->assertStringContainsString('value="mal-escrito"', $html, 'el email escrito sigue ahí');
        $this->assertFalse((bool) preg_match('~id="acepto_politicas"[^>]*\schecked~', $html), 'pero la casilla vuelve sin marcar');
        $this->assertFalse(str_contains($html, self::CLAVE), 'y la clave no vuelve');
    }

    // ─── 2. La evidencia ────────────────────────────────────────────────────

    public function test_la_auditoria_guarda_la_version_que_se_acepto(): void
    {
        $d = $this->datos(['acepto_politicas' => '1']);
        $id = (new RegistroService())->registrar($d);

        $st = $this->db->prepare("SELECT descripcion FROM auditoria WHERE accion = 'registro_participante' AND registro_id = ?");
        $st->execute([$id]);
        $descripcion = (string) $st->fetchColumn();
        $this->assertStringContainsString('Aceptó la Política de Privacidad y los Términos y Condiciones, versión ' . Politicas::VERSION, $descripcion);
        $this->assertStringContainsString($d['email'], $descripcion, 'sigue diciendo quién se registró');
    }

    public function test_la_version_es_un_numero_y_la_fecha_un_dia_real(): void
    {
        $this->assertTrue((bool) preg_match('/^\d+\.\d+$/', Politicas::VERSION), 'versión «mayor.menor»: ' . Politicas::VERSION);
        $this->assertTrue((bool) preg_match('/^\d{1,2} de [a-z]+ de \d{4}$/', Politicas::VIGENTE_DESDE), 'fecha escrita: ' . Politicas::VIGENTE_DESDE);
    }

    // ─── 3. Las páginas ─────────────────────────────────────────────────────

    public function test_las_dos_paginas_son_rutas_publicas(): void
    {
        $router = new Router();
        require dirname(__DIR__) . '/config/routes.php';
        $prop = (new ReflectionObject($router))->getProperty('routes');
        $prop->setAccessible(true);
        $rutas = array_map(fn(array $r) => "{$r['method']} {$r['path']} → {$r['controller']}::{$r['action']}", (array) $prop->getValue($router));

        $this->assertTrue(in_array('GET privacidad → PublicoController::privacidad', $rutas, true), 'GET /privacidad');
        $this->assertTrue(in_array('GET terminos → PublicoController::terminos', $rutas, true), 'GET /terminos');
        // Son páginas para leer: ninguna escribe nada.
        $this->assertCount(0, array_filter($rutas, fn(string $r) => str_starts_with($r, 'POST ') && (str_contains($r, 'privacidad') || str_contains($r, 'terminos'))));
    }

    public function test_la_politica_se_lee_sin_iniciar_sesion_y_dice_lo_que_el_sistema_hace(): void
    {
        (new PublicoObservable())->privacidad();
        $html = PublicoObservable::$html;

        $this->assertStringContainsString('<h1>Política de Privacidad</h1>', $html);
        $this->assertStringContainsString('Versión ' . Politicas::VERSION, $html);
        $this->assertStringContainsString('Vigente desde el ' . Politicas::VIGENTE_DESDE, $html);
        // El consentimiento sobre quién ve qué, tal como lo pidió el proyecto.
        $this->assertStringContainsString('das tu consentimiento', $html);
        $this->assertStringContainsString('otros participantes, organizadores, administradores y cualquier persona que visite el sitio', $html);
        // Lo que NO se muestra, y a quién.
        $this->assertStringContainsString('Email, teléfono y documento', $html);
        $this->assertStringContainsString('bcrypt', $html);
        $this->assertStringContainsString('Ley N.º 18.331', $html);
        $this->assertStringContainsString('URCDP', $html);
        $this->assertStringContainsString('menor de 18', $html);
        // El derecho de supresión apunta a una pantalla que existe.
        $this->assertStringContainsString('Eliminar mis datos personales', $html);
        $this->assertStringContainsString('Eliminar mis datos personales', (string) file_get_contents(APP_PATH . '/views/participante/perfil.php'),
            'lo que la política promete existe en «Mi perfil»');
        // Navegación entre las dos páginas y vuelta al registro.
        $this->assertStringContainsString('href="/terminos"', $html);
        $this->assertStringContainsString('href="/registro"', $html);
    }

    public function test_los_terminos_se_leen_sin_iniciar_sesion_y_enlazan_la_politica(): void
    {
        (new PublicoObservable())->terminos();
        $html = PublicoObservable::$html;

        $this->assertStringContainsString('<h1>Términos y Condiciones</h1>', $html);
        $this->assertStringContainsString('Versión ' . Politicas::VERSION, $html);
        $this->assertStringContainsString('href="/privacidad"', $html);
        $this->assertStringContainsString('pendiente', $html, 'el registro queda pendiente hasta que un administrador lo apruebe');
        $this->assertStringContainsString('visibles para otros participantes, organizadores, administradores y cualquier visitante', $html);
        $this->assertStringContainsString('República Oriental del Uruguay', $html);
    }

    public function test_las_paginas_no_llevan_estilos_ni_scripts_en_linea(): void
    {
        foreach (['privacidad', 'terminos'] as $pagina) {
            (new PublicoObservable())->$pagina();
            $html = PublicoObservable::$html;
            $this->assertFalse((bool) preg_match('/\sstyle\s*=/i', $html), "{$pagina}: sin atributos style");
            $this->assertFalse((bool) preg_match('/\son[a-z]+\s*=/i', $html), "{$pagina}: sin manejadores onclick…");
            $this->assertFalse((bool) preg_match('~<script(?![^>]*\ssrc=)~i', $html), "{$pagina}: sin scripts en línea");
        }
    }

    public function test_el_pie_de_las_paginas_publicas_enlaza_las_dos_politicas(): void
    {
        (new PublicoObservable())->privacidad();
        $this->assertStringContainsString('class="footer-legal"', PublicoObservable::$html);
        $this->assertStringContainsString('<a href="/privacidad">Privacidad</a>', PublicoObservable::$html);
        $this->assertStringContainsString('<a href="/terminos">Términos</a>', PublicoObservable::$html);
    }

    // ─── 4. El formulario de registro (y no el de login) ────────────────────

    public function test_el_registro_trae_la_casilla_obligatoria_y_los_enlaces(): void
    {
        (new AuthObservable())->registroForm();
        $html = AuthObservable::$html;

        $this->assertTrue((bool) preg_match('~<input type="checkbox" id="acepto_politicas" name="acepto_politicas" value="1" required>~', $html), 'la casilla, obligatoria');
        $this->assertFalse((bool) preg_match('~id="acepto_politicas"[^>]*\schecked~', $html), 'y sin marcar de antemano');
        $this->assertStringContainsString('<label class="check-linea" for="acepto_politicas">', $html, 'con su etiqueta (se marca tocando el texto)');
        $this->assertStringContainsString('href="/privacidad" target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('href="/terminos" target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('doy mi consentimiento', $html);
        $this->assertStringContainsString('otros participantes, organizadores, administradores y cualquier visitante del sitio', $html);
        // Va DENTRO del formulario (si no, no se enviaría) y antes del botón de crear la cuenta.
        $this->assertTrue(strpos($html, 'name="acepto_politicas"') > strpos($html, '<form method="POST" action="/registro"'));
        $this->assertTrue(strpos($html, 'name="acepto_politicas"') < strpos($html, 'id="submitBtn"'));
        $this->assertTrue(strpos($html, 'name="acepto_politicas"') < strpos($html, '</form>'));
    }

    public function test_el_login_no_muestra_las_politicas(): void
    {
        (new AuthObservable())->loginForm();
        $html = AuthObservable::$html;
        $this->assertFalse(str_contains($html, 'acepto_politicas'), 'el login no pide aceptar nada');
        $this->assertFalse(str_contains($html, 'Privacidad y consentimiento'));
    }

    public function test_el_script_del_formulario_conoce_la_casilla(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/registro.js');
        $this->assertStringContainsString("getElementById('acepto_politicas')", $js);
        $this->assertStringContainsString("addEventListener('change', comprobar)", $js);
    }
}

TestCase::correrSiEsScript(PoliticasTest::class);
