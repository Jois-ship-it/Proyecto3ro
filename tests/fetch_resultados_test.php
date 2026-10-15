<?php
declare(strict_types=1);

/**
 * Test de la carga de resultados por fetch, con el token CSRF en una cabecera (parte G del plan).
 *
 * Qué pasaba antes: cargar, corregir, solicitar una corrección o programar un partido era siempre un
 * formulario común: POST, flash y redirección. Si el servidor rechazaba el marcador, la página se
 * recargaba y el motivo aparecía arriba, DETRÁS del diálogo, y se perdía lo que la persona había
 * escrito. No había un solo fetch en el sitio.
 *
 * Qué pasa ahora: las mismas acciones atienden dos maneras de pedir.
 *   - El formulario común, como siempre (flash y redirección): no se perdió nada.
 *   - Un fetch (cabecera X-Requested-With), que recibe JSON: {ok:true,datos:{mensaje,aviso}} o
 *     {ok:false,error:{codigo,mensaje}}, y trae el token en X-CSRF-Token.
 * Una compuerta que falla (sesión, rol, permiso, propiedad del torneo, token) responde 401/403 en JSON
 * y no redirige a una página: un fetch que sigue una redirección recibe HTML y se rompe.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/fetch_resultados_test.php
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

final class RespondioJson extends Exception
{
    public function __construct(public readonly array $cuerpo, public readonly int $estado)
    {
        parent::__construct("json {$estado}");
    }
}

trait SalidasObservables
{
    protected function redirect(string $url): never { throw new RedirigidoA($url); }
    protected function responderJson(array $cuerpo, int $estado = 200): never { throw new RespondioJson($cuerpo, $estado); }
}

final class ResultadoFetch   extends ResultadoController   { use SalidasObservables; }
final class CorreccionFetch  extends CorreccionController  { use SalidasObservables; }

class FetchResultadosTest extends TestCase
{
    private const MOTIVO = 'Error de planilla en el acta del partido';

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->comoFetch();
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->comoFormulario();
        $_POST = [];
        $this->db->exec("UPDATE permisos SET puede_crear = 1, puede_editar = 1 WHERE rol_id = 2 AND modulo_slug = 'resultados'");
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    /** El pedido lo hace un fetch: espera JSON y trae el token en la cabecera. */
    private function comoFetch(?string $token = null): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT']           = 'application/json';
        $_SERVER['HTTP_X_CSRF_TOKEN']     = $token ?? Csrf::generate();
    }

    /** El pedido lo hace un formulario común: sin cabeceras de fetch (el token va en el campo). */
    private function comoFormulario(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_REFERER']);
    }

    /** Un torneo de liga con el organizador indicado, listo para jugar. @return array{0:int,1:int[]} */
    private function liga(int $organizador = Fixtures::ORGANIZADOR, array $op = []): array
    {
        return Fixtures::torneoArrancado('liga', 3, $op + ['organizador_id' => $organizador]);
    }

    /** @return array{0:int,1:array} [estado, cuerpo] de lo que respondió el fetch */
    private function respuesta(callable $accion): array
    {
        try {
            $accion();
        } catch (RespondioJson $r) {
            return [$r->estado, $r->cuerpo];
        } catch (Throwable $e) {
            // Una compuerta que falla lanza: lo arma ManejadorErrores (esto es lo que ve el cliente).
            $x = ManejadorErrores::responder($e, false, ManejadorErrores::esPeticionApi());
            return [$x['estado'], json_decode($x['cuerpo'], true) ?? ['no_json' => $x['cuerpo']]];
        }
        $this->fallar('La acción terminó sin responder');
    }

    private function cargarPost(int $enf, string $a, string $b, int $torneo): void
    {
        $_POST = ['enfrentamiento_id' => (string) $enf, 'puntos_a' => $a, 'puntos_b' => $b, 'torneo_id' => (string) $torneo];
    }

    private function enfrentamiento(int $id): array
    {
        return $this->db->query("SELECT * FROM enfrentamientos WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    // ═══ Cargar ═══════════════════════════════════════════════════════════

    public function test_cargar_por_fetch_responde_json_y_deja_el_aviso_para_la_pagina_que_se_recarga(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);

        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());

        $this->assertSame(200, $estado);
        $this->assertSame(true, $r['ok']);
        $this->assertSame('Resultado cargado correctamente.', $r['datos']['mensaje']);
        $this->assertSame(null, $r['datos']['aviso']);
        $this->assertSame('finalizado', $this->enfrentamiento($enf)['estado'], 'se guardó');
        $this->assertSame('Resultado cargado correctamente.', Session::getFlash('success'), 'y el aviso queda para la página recargada');
    }

    public function test_un_rechazo_de_negocio_es_un_422_con_el_motivo_y_sin_flash(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '-3', '1', $tid);                       // puntos negativos

        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());

        $this->assertSame(422, $estado);
        $this->assertSame(false, $r['ok']);
        $this->assertSame('datos_invalidos', $r['error']['codigo']);
        $this->assertSame('Los puntos no pueden ser negativos.', $r['error']['mensaje']);
        $this->assertNotSame('finalizado', $this->enfrentamiento($enf)['estado'], 'no se guardó');
        $this->assertSame(null, Session::getFlash('error'), 'el motivo viaja en el JSON: no hace falta un flash que aparezca después');
    }

    public function test_un_empate_donde_no_vale_se_rechaza_con_su_motivo(): void
    {
        [$tid] = $this->liga();                                          // liga sin empates
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '1', '1', $tid);
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(422, $estado);
        $this->assertStringContainsString('no permite empates', $r['error']['mensaje']);
    }

    public function test_un_error_tecnico_es_un_500_con_codigo_de_referencia_y_sin_el_detalle(): void
    {
        $this->cargarPost(999999, '1', '0', 1);                          // partido que no existe: ErrorNegocio
        [$estado] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(422, $estado, 'que el partido no exista es un rechazo de negocio, no un fallo');

        // Un fallo técnico: el servicio lanza algo que no es ErrorNegocio.
        $falla = new class extends ResultadoController {
            use SalidasObservables;
            public function probar(): void
            {
                $this->terminarAccion(new RuntimeException('SQLSTATE[HY000]: detalle interno que no debe verse'), 'ok', '/x');
            }
        };
        [$estado, $r] = $this->respuesta(fn() => $falla->probar());
        $this->assertSame(500, $estado);
        $this->assertSame('error_interno', $r['error']['codigo']);
        $this->assertStringNotContainsString('SQLSTATE', $r['error']['mensaje']);
        $this->assertStringContainsString('código', $r['error']['mensaje'], 'trae el código de referencia');
    }

    // ═══ El token CSRF ════════════════════════════════════════════════════

    public function test_el_token_en_la_cabecera_basta_y_no_hace_falta_el_campo(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);
        $this->assertFalse(isset($_POST['csrf_token']), 'el pedido no trae el campo del formulario');
        [$estado] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(200, $estado);
    }

    public function test_un_token_equivocado_o_ausente_es_un_403_en_json(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);

        foreach (['token-inventado', str_repeat('0', 64)] as $malo) {
            $this->comoFetch($malo);
            [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
            $this->assertSame(403, $estado, 'token ' . $malo);
            $this->assertSame('sin_permiso', $r['error']['codigo']);
        }
        $this->comoFetch();
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);                            // ni cabecera ni campo
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(403, $estado);
        $this->assertSame('sin_permiso', $r['error']['codigo']);
        $this->assertNotSame('finalizado', $this->enfrentamiento($enf)['estado'], 'nada se guardó');
    }

    public function test_si_la_cabecera_viene_es_la_que_vale_aunque_el_campo_sea_correcto(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);
        $_POST['csrf_token'] = Csrf::generate();                         // el campo, correcto
        $this->comoFetch('token-equivocado');                            // la cabecera, no
        [$estado] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(403, $estado, 'una cabecera equivocada no se «salva» con un campo correcto');
    }

    public function test_el_formulario_comun_sigue_funcionando_con_el_token_en_el_campo(): void
    {
        $this->comoFormulario();
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);
        $_POST['csrf_token'] = Csrf::generate();
        $_SERVER['HTTP_REFERER'] = "/admin/torneos/{$tid}";

        $e = null;
        try { (new ResultadoFetch())->cargar(); } catch (RedirigidoA $r) { $e = $r; }
        $this->assertNotNull($e, 'sin fetch, redirige como siempre');
        $this->assertSame("/admin/torneos/{$tid}", $e->url);
        $this->assertSame('Resultado cargado correctamente.', Session::getFlash('success'));
        $this->assertSame('finalizado', $this->enfrentamiento($enf)['estado']);
    }

    public function test_el_formulario_comun_con_un_error_redirige_con_flash(): void
    {
        $this->comoFormulario();
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '-1', '1', $tid);
        $_POST['csrf_token'] = Csrf::generate();
        $e = null;
        try { (new ResultadoFetch())->cargar(); } catch (RedirigidoA $r) { $e = $r; }
        $this->assertNotNull($e);
        $this->assertSame('Los puntos no pueden ser negativos.', Session::getFlash('error'));
    }

    public function test_el_token_del_pedido_se_toma_de_la_cabecera_o_del_campo(): void
    {
        $this->assertSame('abc', Csrf::tokenDelPedido(['HTTP_X_CSRF_TOKEN' => 'abc'], ['csrf_token' => 'zzz']), 'la cabecera manda');
        $this->assertSame('zzz', Csrf::tokenDelPedido([], ['csrf_token' => 'zzz']), 'sin cabecera, el campo');
        $this->assertSame('zzz', Csrf::tokenDelPedido(['HTTP_X_CSRF_TOKEN' => ''], ['csrf_token' => 'zzz']), 'una cabecera vacía no cuenta');
        $this->assertSame('', Csrf::tokenDelPedido([], []));
        $this->assertSame('', Csrf::tokenDelPedido([], ['csrf_token' => ['a']]), 'un arreglo no es un token');
        $this->assertSame('', Csrf::tokenDelPedido(['HTTP_X_CSRF_TOKEN' => ['a']], []));
    }

    // ═══ Las compuertas responden JSON, no redirigen ══════════════════════

    public function test_sin_sesion_es_un_401_en_json(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);
        Fixtures::cerrarSesion();
        $this->comoFetch(str_repeat('a', 64));
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(401, $estado, 'sin sesión, un fetch recibe 401 y no la página de login');
        $this->assertSame('no_autenticado', $r['error']['codigo']);
    }

    public function test_un_participante_es_un_403_en_json(): void
    {
        [$tid] = $this->liga();
        $this->cargarPost(Fixtures::partidosPendientes($tid)[0], '2', '1', $tid);
        Fixtures::loguearComo(4);
        $this->comoFetch();
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(403, $estado);
        $this->assertSame('sin_permiso', $r['error']['codigo']);
    }

    public function test_un_organizador_en_el_torneo_de_otro_es_un_403_y_no_una_redireccion(): void
    {
        [$tid] = $this->liga(Fixtures::ORGANIZADOR2);
        $enf = Fixtures::partidosPendientes($tid)[0];
        $this->cargarPost($enf, '2', '1', $tid);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFetch();
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(403, $estado);
        $this->assertSame('No tenés acceso a este torneo.', $r['error']['mensaje']);
        $this->assertNotSame('finalizado', $this->enfrentamiento($enf)['estado']);
        $this->assertSame(null, Session::getFlash('error'), 'sin flash: la respuesta ya lleva el motivo');
    }

    public function test_un_organizador_sin_el_permiso_de_cargar_es_un_403_y_no_una_redireccion(): void
    {
        [$tid] = $this->liga();
        $this->cargarPost(Fixtures::partidosPendientes($tid)[0], '2', '1', $tid);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFetch();
        $this->db->exec("UPDATE permisos SET puede_crear = 0 WHERE rol_id = 2 AND modulo_slug = 'resultados'");
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->cargar());
        $this->assertSame(403, $estado);
        $this->assertSame('sin_permiso', $r['error']['codigo']);
    }

    public function test_sin_fetch_esas_mismas_compuertas_siguen_redirigiendo_como_antes(): void
    {
        [$tid] = $this->liga(Fixtures::ORGANIZADOR2);
        $this->cargarPost(Fixtures::partidosPendientes($tid)[0], '2', '1', $tid);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFormulario();
        $_POST['csrf_token'] = Csrf::generate();

        $e = null;
        try { (new ResultadoFetch())->cargar(); } catch (RedirigidoA $r) { $e = $r; } catch (Throwable $t) { $e = $t; }
        $this->assertTrue($e instanceof RedirigidoA, 'el organizador ajeno es redirigido, no recibe un 403');
        $this->assertSame('No tenés acceso a este torneo.', Session::getFlash('error'));

        $this->db->exec("UPDATE permisos SET puede_crear = 0 WHERE rol_id = 2 AND modulo_slug = 'resultados'");
        $_POST['csrf_token'] = Csrf::generate();
        $e = null;
        try { (new ResultadoFetch())->cargar(); } catch (RedirigidoA $r) { $e = $r; } catch (Throwable $t) { $e = $t; }
        $this->assertTrue($e instanceof RedirigidoA, 'y sin permiso, también redirige con aviso');
        $this->assertStringContainsString('no tiene permiso', (string) Session::getFlash('error'));
    }

    // ═══ Corregir, solicitar y programar ══════════════════════════════════

    public function test_corregir_por_fetch_trae_el_aviso_si_el_campeon_cambia(): void
    {
        [$tid, $p] = $this->liga(Fixtures::ORGANIZADOR, ['permite_empates' => '1']);
        [$x, $y, $z] = $p;
        $jugar = function (int $a, int $b, float $pa, float $pb) use ($tid): int {
            $st = $this->db->prepare("SELECT * FROM enfrentamientos WHERE torneo_id = :t AND ((participante_a_id = :a AND participante_b_id = :b) OR (participante_a_id = :b2 AND participante_b_id = :a2))");
            $st->execute([':t' => $tid, ':a' => $a, ':b' => $b, ':b2' => $b, ':a2' => $a]);
            $e = $st->fetch(PDO::FETCH_ASSOC);
            [$ptsA, $ptsB] = (int) $e['participante_a_id'] === $a ? [$pa, $pb] : [$pb, $pa];
            (new ResultadoService())->cargar((int) $e['id'], $ptsA, $ptsB, Fixtures::ADMIN);
            return (int) $e['id'];
        };
        $partidoXY = $jugar($x, $y, 2, 1);
        $jugar($x, $z, 2, 0);
        $jugar($y, $z, 2, 0);                                           // X campeón con 6 puntos

        $e = $this->enfrentamiento($partidoXY);
        [$a, $b] = (int) $e['participante_a_id'] === $x ? ['0', '3'] : ['3', '0'];        // ahora gana Y
        $_POST = ['enfrentamiento_id' => (string) $partidoXY, 'puntos_a' => $a, 'puntos_b' => $b, 'motivo_correccion' => self::MOTIVO, 'torneo_id' => (string) $tid];

        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->corregir());

        $this->assertSame(200, $estado);
        $this->assertStringContainsString('corregido correctamente', $r['datos']['mensaje']);
        $this->assertStringContainsString('El campeón cambió', (string) $r['datos']['aviso'], 'el aviso viaja en el JSON');
        $this->assertStringContainsString('El campeón cambió', (string) Session::getFlash('warning'), 'y queda como flash para la página recargada');
    }

    public function test_corregir_por_fetch_sigue_siendo_solo_del_administrador(): void
    {
        [$tid] = $this->liga();
        Fixtures::jugarTodo($tid);
        $enf = (int) $this->db->query("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} LIMIT 1")->fetchColumn();
        $_POST = ['enfrentamiento_id' => (string) $enf, 'puntos_a' => '5', 'puntos_b' => '0', 'motivo_correccion' => self::MOTIVO, 'torneo_id' => (string) $tid];
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFetch();
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->corregir());
        $this->assertSame(403, $estado, 'el organizador no aplica una corrección directa, ni por fetch');
        $this->assertSame('sin_permiso', $r['error']['codigo']);
    }

    public function test_solicitar_una_correccion_por_fetch(): void
    {
        [$tid] = $this->liga();
        Fixtures::jugarTodo($tid);
        $enf = (int) $this->db->query("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} ORDER BY id LIMIT 1")->fetchColumn();
        $_POST = ['enfrentamiento_id' => (string) $enf, 'puntos_a' => '4', 'puntos_b' => '0', 'motivo_correccion' => self::MOTIVO, 'torneo_id' => (string) $tid];
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFetch();

        [$estado, $r] = $this->respuesta(fn() => (new CorreccionFetch())->solicitar());
        $this->assertSame(200, $estado);
        $this->assertStringContainsString('Solicitud de corrección enviada', $r['datos']['mensaje']);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM solicitudes_correccion WHERE estado = 'pendiente'")->fetchColumn());

        // Una segunda solicitud sobre el mismo partido: rechazo de negocio, en JSON.
        [$estado, $r] = $this->respuesta(fn() => (new CorreccionFetch())->solicitar());
        $this->assertSame(422, $estado);
        $this->assertStringContainsString('Ya existe una solicitud', $r['error']['mensaje']);
    }

    public function test_solicitar_en_el_torneo_de_otro_es_un_403_en_json(): void
    {
        [$tid] = $this->liga(Fixtures::ORGANIZADOR2);
        Fixtures::jugarTodo($tid);
        $enf = (int) $this->db->query("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} LIMIT 1")->fetchColumn();
        $_POST = ['enfrentamiento_id' => (string) $enf, 'puntos_a' => '4', 'puntos_b' => '0', 'motivo_correccion' => self::MOTIVO, 'torneo_id' => (string) $tid];
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->comoFetch();
        [$estado, $r] = $this->respuesta(fn() => (new CorreccionFetch())->solicitar());
        $this->assertSame(403, $estado);
        $this->assertSame('No tenés acceso a este torneo.', $r['error']['mensaje']);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM solicitudes_correccion")->fetchColumn());
    }

    public function test_programar_un_partido_por_fetch(): void
    {
        [$tid] = $this->liga();
        $enf = Fixtures::partidosPendientes($tid)[0];
        $hoy = date('Y-m-d');
        $_POST = ['enfrentamiento_id' => (string) $enf, 'fecha_programada' => $hoy . 'T18:30', 'torneo_id' => (string) $tid];
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->programar());
        $this->assertSame([200, true], [$estado, $r['ok']], json_encode($r));
        $this->assertSame('Partido programado correctamente.', $r['datos']['mensaje']);

        $_POST['fecha_programada'] = '2099-01-01T10:00';                 // fuera del rango del torneo
        [$estado, $r] = $this->respuesta(fn() => (new ResultadoFetch())->programar());
        $this->assertSame(422, $estado, 'un rechazo de negocio, en JSON');
        $this->assertSame(false, $r['ok']);
    }

    // ═══ El lado del navegador ════════════════════════════════════════════

    public function test_los_archivos_del_cliente_existen_y_hacen_lo_que_dicen(): void
    {
        $api = (string) file_get_contents(BASE_PATH . '/public/assets/js/api.js');
        $res = (string) file_get_contents(BASE_PATH . '/public/assets/js/resultados.js');
        $this->assertStringContainsString("'X-CSRF-Token'", $api, 'manda el token en la cabecera');
        $this->assertStringContainsString("'X-Requested-With'", $api, 'avisa que espera JSON');
        $this->assertStringContainsString('meta[name="csrf-token"]', $api, 'lo lee de la etiqueta de la página');
        $this->assertStringContainsString('class ApiError', $api);
        $this->assertStringContainsString("credentials: 'same-origin'", $api, 'solo con las cookies del propio sitio');
        $this->assertStringContainsString('form[data-api]', $res);
        $this->assertStringContainsString('e.estado === 401', $res, 'sin sesión, a /login');
        $this->assertStringContainsString('data-api-error', $res);
        $this->assertStringContainsString('if (typeof FlexApi', $res, 'sin api.js no hace nada: el formulario común sigue');
    }

    public function test_el_panel_publica_el_token_y_las_pantallas_de_gestion_cargan_los_scripts_en_orden(): void
    {
        $layout = (string) file_get_contents(APP_PATH . '/views/layouts/admin.php');
        $this->assertStringContainsString('<meta name="csrf-token" content="<?= View::e(Csrf::generate()) ?>">', $layout);

        foreach (['app/controllers/TorneoController.php', 'app/controllers/OrganizadorController.php'] as $c) {
            $this->assertStringContainsString("'combobox.js', 'api.js', 'resultados.js'", (string) file_get_contents(BASE_PATH . '/' . $c),
                "$c: api.js antes de resultados.js, que lo usa");
        }

        $esperados = ['app/views/admin/torneo_gestion.php' => 3, 'app/views/organizador/torneo_gestion.php' => 3];
        foreach ($esperados as $vista => $formularios) {
            $html = (string) file_get_contents(BASE_PATH . '/' . $vista);
            $this->assertSame($formularios, substr_count($html, ' data-api>'), "$vista: los formularios de resultados van por fetch");
            $this->assertSame($formularios, substr_count($html, 'data-api-error'), "$vista: cada uno con su lugar para el motivo");
        }
    }

    public function test_la_pagina_de_gestion_trae_el_token_las_etiquetas_y_los_scripts(): void
    {
        [$tid] = $this->liga();
        $this->comoFormulario();
        $c = new class extends TorneoController {
            public static string $html = '';
            protected function render(string $view, array $data = [], string $layout = 'public'): void
            {
                ob_start();
                try { parent::render($view, $data, 'admin'); } finally { self::$html = (string) ob_get_clean(); }
            }
        };
        $c->gestion((string) $tid);
        $html = $c::$html;
        $this->assertStringContainsString('<meta name="csrf-token" content="' . Csrf::generate() . '">', $html);
        $this->assertStringContainsString('/assets/js/api.js', $html);
        $this->assertStringContainsString('/assets/js/resultados.js', $html);
        $this->assertTrue(strpos($html, '/assets/js/api.js') < strpos($html, '/assets/js/resultados.js'), 'api.js primero');
        $this->assertStringContainsString('id="formCargar" data-api', $html);
        $this->assertStringContainsString('data-api-error', $html);
        $this->assertStringContainsString('csrf_token', $html, 'y el campo del formulario sigue, para cuando no hay JavaScript');
    }
}

TestCase::correrSiEsScript(FetchResultadosTest::class);
