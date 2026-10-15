<?php
declare(strict_types=1);

/**
 * Test de las pantallas de la parte H: Configuración (admin) y «Mi panel» del participante.
 *
 * Qué pasaba antes: no existía la pantalla de configuración (los ajustes se cambiaban a mano en la
 * base) y «Mi panel» solo traía el perfil y la lista de torneos.
 *
 * Qué se comprueba:
 *   - Configuración: solo la ve y la usa el administrador (con token CSRF); guardar cambia lo que
 *     cambió y lo dice; sin cambios lo avisa; con un valor inválido no guarda NADA y vuelve a mostrar
 *     el formulario con lo que la persona había escrito y el motivo; un envío manipulado
 *     (arreglos, claves ajenas) no pasa; el interruptor se puede apagar y encender.
 *   - El menú lateral del administrador lleva a la pantalla, y el de otros roles no.
 *   - El pie muestra el correo de contacto como enlace solo si hay uno válido, escapado.
 *   - Mi panel: muestra próximos partidos, últimos resultados y posición con los datos del
 *     participante; los estados vacíos; y escapa los nombres de los rivales.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/configuracion_pantalla_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

final class RedirigidoA extends Exception
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("redirect: {$url}");
    }
}

/** Deja ver qué vista se pidió, con qué datos y el HTML que salió (con el layout del panel). */
trait ObservaLaVista
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
            parent::render($view, $data, $layout);
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never
    {
        throw new RedirigidoA($url);
    }

    public static function limpiar(): void
    {
        self::$vista = '';
        self::$datos = [];
        self::$html  = '';
    }
}

final class ConfiguracionObservable extends ConfiguracionController { use ObservaLaVista; }
final class ParticipanteObservable extends ParticipanteController { use ObservaLaVista; }
final class PublicoObservable extends PublicoController { use ObservaLaVista; }

class ConfiguracionPantallaTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        $this->restaurar();
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_POST = [];
        http_response_code(200);
        ConfiguracionObservable::limpiar();
        ParticipanteObservable::limpiar();
        PublicoObservable::limpiar();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        http_response_code(200);
        $this->restaurar();
        Fixtures::cerrarSesion();
    }

    // ── apoyo ──────────────────────────────────────────────────────────

    private function restaurar(): void
    {
        $st = $this->db->prepare(
            'INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = NULL'
        );
        foreach (ConfiguracionSistemaService::POR_DEFECTO as $clave => $valor) {
            $st->execute([$clave, $valor, ConfiguracionSistemaService::CAMPOS[$clave]['descripcion']]);
        }
        ConfiguracionSistemaService::olvidar();
    }

    private function valor(string $clave): string
    {
        $st = $this->db->prepare('SELECT valor FROM configuracion_sistema WHERE clave = ?');
        $st->execute([$clave]);
        return (string) $st->fetchColumn();
    }

    /** Los valores del formulario tal como los manda un navegador con todo sin tocar. */
    private function formulario(array $cambios = []): array
    {
        return $cambios + ConfiguracionSistemaService::POR_DEFECTO;
    }

    /** Manda el formulario. Devuelve la redirección, o null si volvió a mostrarse la pantalla. */
    private function enviar(array $post, string $clase = ConfiguracionObservable::class): ?RedirigidoA
    {
        $_POST = $post + ['csrf_token' => Csrf::generate()];
        try {
            (new $clase())->guardar();
            return null;
        } catch (RedirigidoA $r) {
            return $r;
        }
    }

    private function mostrar(): string
    {
        (new ConfiguracionObservable())->index();
        return ConfiguracionObservable::$html;
    }

    // ── quién entra ────────────────────────────────────────────────────

    public function test_solo_el_administrador_entra_a_la_pantalla(): void
    {
        $html = $this->mostrar();
        $this->assertSame('admin/configuracion', ConfiguracionObservable::$vista);
        $this->assertStringContainsString('<h1>Configuración</h1>', $html);

        foreach ([Fixtures::ORGANIZADOR, 4] as $otro) {
            Fixtures::loguearComo($otro);
            $this->assertThrows(fn() => (new ConfiguracionObservable())->index(), '', "usuario {$otro}: no entra");
        }
        Fixtures::cerrarSesion();
        $e = $this->assertThrows(fn() => (new ConfiguracionObservable())->index());
        $this->assertTrue($e instanceof SesionRequerida, 'sin sesión: a iniciar sesión');
    }

    public function test_solo_el_administrador_guarda(): void
    {
        foreach ([Fixtures::ORGANIZADOR, 4] as $otro) {
            Fixtures::loguearComo($otro);
            $this->assertThrows(fn() => $this->enviar($this->formulario(['sitio_nombre' => 'Intruso'])), '', "usuario {$otro}");
        }
        $this->assertSame('FlexArena', $this->valor('sitio_nombre'), 'no se guardó nada');
    }

    public function test_guardar_exige_el_token(): void
    {
        $_POST = $this->formulario(['sitio_nombre' => 'Sin token']);
        $this->assertThrows(fn() => (new ConfiguracionObservable())->guardar(), '', 'sin token');
        $_POST = $this->formulario(['sitio_nombre' => 'Token falso']) + ['csrf_token' => 'falso'];
        $this->assertThrows(fn() => (new ConfiguracionObservable())->guardar(), '', 'token equivocado');

        $this->assertSame('FlexArena', $this->valor('sitio_nombre'));
    }

    // ── la pantalla ────────────────────────────────────────────────────

    public function test_la_pantalla_muestra_cada_ajuste_con_su_valor(): void
    {
        $this->db->exec("UPDATE configuracion_sistema SET valor = 'Liga del Sur' WHERE clave = 'sitio_nombre'");
        $this->db->exec("UPDATE configuracion_sistema SET valor = '8' WHERE clave = 'login_max_intentos'");
        ConfiguracionSistemaService::olvidar();

        $html = $this->mostrar();

        foreach (ConfiguracionSistemaService::CAMPOS as $clave => $campo) {
            $this->assertStringContainsString('name="' . $clave . '"', $html, "{$clave}: tiene su campo");
            $this->assertStringContainsString('for="cfg_' . $clave . '"', $html, "{$clave}: la etiqueta apunta al campo");
            $this->assertStringContainsString('id="ayuda_' . $clave . '"', $html, "{$clave}: tiene su ayuda asociada");
        }
        $this->assertTrue((bool) preg_match('~name="sitio_nombre"[^>]*value="Liga del Sur"~', $html), 'el nombre actual');
        $this->assertTrue((bool) preg_match('~name="login_max_intentos"[^>]*value="8"~', $html), 'y los intentos');
        $this->assertStringContainsString('action="/admin/configuracion"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html, 'con el token');
    }

    public function test_el_interruptor_refleja_el_ajuste_y_manda_un_cero_si_se_apaga(): void
    {
        $html = $this->mostrar();
        $this->assertTrue((bool) preg_match('~<input type="checkbox"[^>]*name="perfiles_publicos"[^>]*checked~', $html), 'abierto: marcado');
        $this->assertTrue((bool) preg_match('~<input type="hidden" name="perfiles_publicos" value="0">~', $html), 'con el cero de respaldo para cuando se desmarca');

        $this->db->exec("UPDATE configuracion_sistema SET valor = '0' WHERE clave = 'perfiles_publicos'");
        ConfiguracionSistemaService::olvidar();
        $html = $this->mostrar();
        $this->assertFalse((bool) preg_match('~<input type="checkbox"[^>]*name="perfiles_publicos"[^>]*checked~', $html), 'cerrado: sin marcar');
    }

    public function test_el_menu_del_administrador_lleva_a_la_pantalla(): void
    {
        $html = $this->mostrar();
        $this->assertStringContainsString('href="/admin/configuracion"', $html);
        $this->assertTrue((bool) preg_match('~href="/admin/configuracion"><span>Configuración</span>~', $html));

        // Un organizador en su panel no tiene ese enlace.
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        ob_start();
        View::render('partials/sidebar', ['_pendientes' => ['registros' => 0, 'correcciones' => 0], '_sitio' => ['nombre' => 'FlexArena', 'contacto' => '', 'perfiles_publicos' => true]], '');
        $menuOrganizador = (string) ob_get_clean();
        $this->assertStringNotContainsString('/admin/configuracion', $menuOrganizador);
    }

    // ── guardar ────────────────────────────────────────────────────────

    public function test_guardar_cambia_lo_que_cambio_y_lo_dice(): void
    {
        $r = $this->enviar($this->formulario(['sitio_nombre' => 'Liga del Sur', 'login_max_intentos' => '8']));

        $this->assertTrue($r instanceof RedirigidoA);
        $this->assertSame('/admin/configuracion', $r->url);
        $this->assertSame('Liga del Sur', $this->valor('sitio_nombre'));
        $this->assertSame('8', $this->valor('login_max_intentos'));
        $mensaje = (string) Session::getFlash('success');
        $this->assertStringContainsString('Configuración guardada', $mensaje);
        $this->assertStringContainsString('Nombre del sitio', $mensaje, 'dice qué cambió');
        $this->assertStringContainsString('Intentos fallidos antes de bloquear una cuenta', $mensaje);
        $this->assertStringNotContainsString('Minutos que dura el bloqueo', $mensaje, 'y no lo que quedó igual');
    }

    public function test_sin_cambios_lo_avisa(): void
    {
        $r = $this->enviar($this->formulario());

        $this->assertSame('/admin/configuracion', $r->url);
        $this->assertSame('No había cambios que guardar.', Session::getFlash('info'));
        $this->assertSame(null, Session::getFlash('success'));
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'actualizar_configuracion'")->fetchColumn());
    }

    public function test_con_un_valor_invalido_no_se_guarda_nada_y_vuelve_el_formulario_con_lo_escrito(): void
    {
        $r = $this->enviar($this->formulario(['sitio_nombre' => 'Mi Liga Escolar', 'login_max_intentos' => '50']));

        $this->assertSame(null, $r, 'no redirige: vuelve a mostrar la pantalla');
        $this->assertSame(422, http_response_code(), 'con el código de un formulario rechazado');
        $this->assertSame('FlexArena', $this->valor('sitio_nombre'), 'el nombre válido tampoco se guardó');
        $this->assertSame('5', $this->valor('login_max_intentos'));

        $html = ConfiguracionObservable::$html;
        $this->assertSame('admin/configuracion', ConfiguracionObservable::$vista);
        $this->assertStringContainsString('entre 1 y 20', $html, 'el motivo se ve en la pantalla');
        $this->assertTrue((bool) preg_match('~name="sitio_nombre"[^>]*value="Mi Liga Escolar"~', $html), 'no se pierde lo que escribió');
        $this->assertTrue((bool) preg_match('~name="login_max_intentos"[^>]*value="50"~', $html), 'ni el valor que no sirvió');
    }

    public function test_lo_escrito_se_escapa_al_volver_el_formulario(): void
    {
        $this->enviar($this->formulario(['sitio_nombre' => '"><script>alert(1)</script>', 'login_max_intentos' => '50']));

        $html = ConfiguracionObservable::$html;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function test_un_envio_manipulado_no_pasa(): void
    {
        // Un arreglo donde va un número.
        $this->enviar($this->formulario(['login_max_intentos' => ['1', '2']]));
        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('no es válido', ConfiguracionObservable::$html);
        // Si el arreglo llegara a la vista, PHP lo convertiría a «Array» con un aviso que, en desarrollo, se escribe DENTRO del value.
        $this->assertStringNotContainsString('Array to string conversion', ConfiguracionObservable::$html, 'el arreglo no llega a la vista');
        $this->assertTrue((bool) preg_match('~name="login_max_intentos"[^>]*\svalue=""~', ConfiguracionObservable::$html), 'el campo vuelve vacío, no con «Array»');
        $this->assertSame('5', $this->valor('login_max_intentos'));

        // Claves que no son ajustes: se ignoran, y no llegan a la base.
        http_response_code(200);
        $r = $this->enviar(['sitio_nombre' => 'FlexArena', 'inventado' => 'x', 'login_x' => '1', 'updated_by' => '99']);
        $this->assertTrue($r instanceof RedirigidoA);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM configuracion_sistema WHERE clave IN ('inventado','login_x')")->fetchColumn());
    }

    public function test_el_interruptor_se_apaga_y_se_enciende(): void
    {
        $this->enviar($this->formulario(['perfiles_publicos' => '0']));
        $this->assertSame('0', $this->valor('perfiles_publicos'));
        $this->assertFalse((new ConfiguracionSistemaService())->perfilesPublicos());

        $this->enviar($this->formulario(['perfiles_publicos' => '1']));
        $this->assertSame('1', $this->valor('perfiles_publicos'));
        $this->assertTrue((new ConfiguracionSistemaService())->perfilesPublicos());
    }

    public function test_un_envio_sin_el_interruptor_no_lo_toca(): void
    {
        $this->enviar($this->formulario(['perfiles_publicos' => '0']));
        $this->enviar(['sitio_nombre' => 'Liga del Sur']);          // sin la clave del interruptor

        $this->assertSame('0', $this->valor('perfiles_publicos'), 'una clave que falta no es un «apagar»');
        $this->assertSame('Liga del Sur', $this->valor('sitio_nombre'));
    }

    public function test_lo_guardado_se_ve_en_la_pantalla_siguiente(): void
    {
        $this->enviar($this->formulario(['sitio_nombre' => 'Liga del Sur']));

        $html = $this->mostrar();

        $this->assertStringContainsString('<title>Configuración · Liga del Sur</title>', $html);
        $this->assertTrue((bool) preg_match('~name="sitio_nombre"[^>]*value="Liga del Sur"~', $html));
    }

    // ── el pie ─────────────────────────────────────────────────────────

    private function pie(): string
    {
        (new PublicoObservable())->home();
        $html = PublicoObservable::$html;
        preg_match('~<footer class="footer">(.*?)</footer>~s', $html, $m);
        return $m[1] ?? '';
    }

    public function test_el_pie_muestra_el_contacto_solo_si_hay_uno(): void
    {
        $this->assertStringNotContainsString('Contacto', $this->pie(), 'sin correo cargado no aparece');

        (new ConfiguracionSistemaService())->guardar(['sitio_contacto' => 'liga@club.org'], Fixtures::ADMIN);

        $pie = $this->pie();
        $this->assertStringContainsString('Contacto: <a href="mailto:liga@club.org">liga@club.org</a>', $pie);
    }

    public function test_un_correo_mal_escrito_en_la_base_no_se_muestra(): void
    {
        $this->db->exec("UPDATE configuracion_sistema SET valor = 'no-es-un-correo' WHERE clave = 'sitio_contacto'");
        ConfiguracionSistemaService::olvidar();

        $this->assertStringNotContainsString('mailto', $this->pie());

        $this->db->exec("UPDATE configuracion_sistema SET valor = '\"><script>x</script>@a.com' WHERE clave = 'sitio_contacto'");
        ConfiguracionSistemaService::olvidar();
        $this->assertStringNotContainsString('<script>', $this->pie(), 'tampoco uno con HTML');
    }

    // ── los estilos que usan las vistas ────────────────────────────────

    public function test_las_clases_nuevas_existen_en_la_hoja_de_estilos(): void
    {
        $css = '';
        foreach (View::HOJAS as $hoja) $css .= (string) file_get_contents(BASE_PATH . "/public/assets/css/{$hoja}.css");
        foreach (['lista-filas', 'form-seccion', 'field-ancho', 'form-config'] as $clase) {
            // El nombre completo: «.form-configx» no es «.form-config».
            $this->assertTrue((bool) preg_match('~\.' . preg_quote($clase, '~') . '(?![\w-])~', $css), "la clase {$clase} está definida");
        }
        $this->assertStringContainsString('.footer a', $css, 'el enlace del pie');
    }
}

/**
 * «Mi panel» del participante: la pantalla con los datos del servicio.
 */
class MiPanelTest extends TestCase
{
    private int $usuarioId = 0;
    private int $participanteId = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        $_POST = [];
        ParticipanteObservable::limpiar();
        // Una cuenta de participante con su perfil.
        $this->usuarioId = Fixtures::usuario('Valentina Panel', 'valentina.panel@test.local', 'Clave-Segura-9876!', 3);
        $this->participanteId = (new ParticipanteModel())->insert([
            'usuario_id' => $this->usuarioId, 'nombre' => 'Valentina Panel', 'nick' => 'vale', 'estado' => 'activo',
        ]);
        Fixtures::loguearComo($this->usuarioId);
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
    }

    private function panel(): string
    {
        (new ParticipanteObservable())->dashboard();
        return ParticipanteObservable::$html;
    }

    /** Una liga de cuatro donde ella es la primera inscrita. @return array{0:int,1:int[]} */
    private function ligaConElla(): array
    {
        $otros = Fixtures::participantes(3, 'Rival');
        $tid = Fixtures::torneo('liga', ['nombre' => 'Liga del Panel']);
        Fixtures::inscribir($tid, array_merge([$this->participanteId], $otros));
        (new LigaService())->generarFixture($tid);
        return [$tid, $otros];
    }

    public function test_sin_actividad_muestra_los_estados_vacios(): void
    {
        $html = $this->panel();

        $this->assertSame('participante/dashboard', ParticipanteObservable::$vista);
        $this->assertStringContainsString('Próximos partidos', $html);
        $this->assertStringContainsString('No tenés partidos pendientes.', $html);
        $this->assertStringContainsString('Últimos resultados', $html);
        $this->assertStringContainsString('Todavía no jugaste ningún partido.', $html);
        $this->assertStringContainsString('Mi posición', $html);
        $this->assertStringContainsString('Todavía no estás en ningún torneo en curso o finalizado.', $html);
        $this->assertStringContainsString('Valentina Panel', $html, 'el perfil sigue en el panel');
        $this->assertStringContainsString('Mis torneos', $html);
    }

    public function test_el_panel_recibe_los_datos_del_servicio(): void
    {
        [$tid] = $this->ligaConElla();

        $this->panel();

        $panel = ParticipanteObservable::$datos['panel'];
        $this->assertSame(['proximos', 'recientes', 'competencias'], array_keys($panel));
        $this->assertCount(3, $panel['proximos']);
        $this->assertSame($tid, $panel['competencias'][0]['torneo_id']);
    }

    public function test_muestra_los_proximos_partidos_con_su_rival_y_su_ronda(): void
    {
        [$tid, $otros] = $this->ligaConElla();

        $html = $this->panel();

        $this->assertStringNotContainsString('No tenés partidos pendientes.', $html);
        foreach (['Fecha 1', 'Fecha 2', 'Fecha 3'] as $ronda) {
            $this->assertStringContainsString($ronda, $html);
        }
        $this->assertStringContainsString('Liga del Panel', $html);
        $this->assertTrue((bool) preg_match('~vs Rival \d~', $html), 'contra un rival');
        $this->assertStringContainsString('href="/torneo/' . $tid . '"', $html, 'cada fila lleva al torneo');
        $this->assertStringContainsString('Sin fecha', $html);
    }

    public function test_un_partido_con_fecha_la_muestra(): void
    {
        [$tid] = $this->ligaConElla();
        $st = $this->db->prepare("SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
                                  WHERE e.torneo_id = ? AND (e.participante_a_id = ? OR e.participante_b_id = ?) AND r.numero = 1");
        $st->execute([$tid, $this->participanteId, $this->participanteId]);
        (new EnfrentamientoModel())->programar((int) $st->fetchColumn(), '2030-05-01 18:30:00');

        $html = $this->panel();

        $this->assertStringContainsString('01/05/2030 18:30', $html);
    }

    public function test_muestra_los_resultados_y_la_posicion(): void
    {
        [$tid] = $this->ligaConElla();
        // Gana su primer partido 3-1.
        $st = $this->db->prepare("SELECT e.* FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
                                  WHERE e.torneo_id = ? AND (e.participante_a_id = ? OR e.participante_b_id = ?) ORDER BY r.numero LIMIT 1");
        $st->execute([$tid, $this->participanteId, $this->participanteId]);
        $partido = $st->fetch(PDO::FETCH_ASSOC);
        $esA = (int) $partido['participante_a_id'] === $this->participanteId;
        (new ResultadoService())->cargar((int) $partido['id'], $esA ? 3.0 : 1.0, $esA ? 1.0 : 3.0, Fixtures::ADMIN);

        $html = $this->panel();

        $this->assertStringContainsString('Victoria 3 — 1', $html);
        $this->assertStringNotContainsString('Todavía no jugaste ningún partido.', $html);
        $this->assertTrue((bool) preg_match('~\d\.º de 4~', $html), 'su lugar en la tabla');
        $this->assertStringContainsString('3 pts · 1 PJ', $html);
    }

    public function test_un_empate_y_una_derrota_se_pintan_con_su_marca(): void
    {
        $otros = Fixtures::participantes(3, 'Rival');
        $tid = Fixtures::torneo('liga', ['nombre' => 'Liga con empates', 'permite_empates' => '1']);
        Fixtures::inscribir($tid, array_merge([$this->participanteId], $otros));
        (new LigaService())->generarFixture($tid);
        $st = $this->db->prepare("SELECT e.* FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
                                  WHERE e.torneo_id = ? AND (e.participante_a_id = ? OR e.participante_b_id = ?) ORDER BY r.numero");
        $st->execute([$tid, $this->participanteId, $this->participanteId]);
        [$primero, $segundo] = $st->fetchAll(PDO::FETCH_ASSOC);
        $servicio = new ResultadoService();
        $servicio->cargar((int) $primero['id'], 2.0, 2.0, Fixtures::ADMIN);                       // empate 2-2
        $esA = (int) $segundo['participante_a_id'] === $this->participanteId;
        $servicio->cargar((int) $segundo['id'], $esA ? 0.0 : 1.0, $esA ? 1.0 : 0.0, Fixtures::ADMIN);   // ella pierde 0-1

        $html = $this->panel();

        $this->assertTrue((bool) preg_match('~<span class="chip warning">Empate 2 — 2</span>~', $html), 'el empate, en amarillo');
        $this->assertTrue((bool) preg_match('~<span class="chip danger">Derrota 0 — 1</span>~', $html), 'la derrota, en rojo');
    }

    public function test_en_una_llave_dice_donde_va(): void
    {
        $otros = Fixtures::participantes(3, 'Rival');
        $tid = Fixtures::torneo('eliminacion_directa', ['nombre' => 'Copa del Panel']);
        Fixtures::inscribir($tid, array_merge([$this->participanteId], $otros));
        (new EliminacionDirectaService())->generarBracket($tid);

        $this->assertStringContainsString('Sigue en carrera', $this->panel());

        Fixtures::jugarTodo($tid);
        $html = $this->panel();
        $this->assertTrue(str_contains($html, 'Campeón') || str_contains($html, 'Eliminado en'), 'terminada la copa: campeón o dónde quedó afuera');
    }

    public function test_los_nombres_de_los_rivales_se_escapan(): void
    {
        $this->ligaConElla();
        $this->db->exec("UPDATE participantes SET nombre = '<img src=x onerror=alert(1)>' WHERE nombre = 'Rival 1'");

        $html = $this->panel();

        $this->assertStringNotContainsString('<img src=x', $html, 'un nombre con HTML no inyecta nada');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_una_cuenta_sin_perfil_de_participante_ve_el_aviso_y_no_falla(): void
    {
        $this->db->exec("UPDATE participantes SET usuario_id = NULL WHERE id = {$this->participanteId}");

        $html = $this->panel();

        $this->assertStringContainsString('No tenés un perfil de participante vinculado a tu cuenta.', $html);
        $this->assertSame(null, ParticipanteObservable::$datos['panel']);
        $this->assertStringNotContainsString('Próximos partidos', $html);
    }

    public function test_el_administrador_tambien_puede_abrirlo(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);

        $html = $this->panel();

        $this->assertStringContainsString('<h1>Mi panel</h1>', $html);
        $this->assertStringContainsString('No tenés un perfil de participante', $html, 'el admin no tiene perfil: se le avisa');
    }

    public function test_un_organizador_no_entra_al_panel_del_participante(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $this->assertThrows(fn() => (new ParticipanteObservable())->dashboard());
    }
}

TestCase::correrSiEsScript(ConfiguracionPantallaTest::class, MiPanelTest::class);
