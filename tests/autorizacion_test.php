<?php
declare(strict_types=1);

/**
 * Test de autorización: quién puede entrar a qué, recorriendo la tabla de rutas completa.
 *
 * Qué pasaba antes:
 *   - Un 403 (o «iniciá sesión») hacía `exit` en medio del controlador, así que ninguna prueba
 *     podía atravesar una compuerta: solo se leía el código. Ahora las compuertas lanzan
 *     AccesoDenegado / SesionRequerida y se pueden ejecutar de verdad.
 *   - S1: un organizador con el permiso `resultados/editar` (que trae por defecto) podía mandar
 *     un POST a /admin/resultados/corregir y APLICAR una corrección sin aprobación. La ruta solo
 *     pedía «rol organizador + permiso».
 *   - S2: un organizador podía SOLICITAR correcciones sobre los partidos de cualquier torneo: se
 *     comprobaban el rol y el permiso, pero no de quién era el torneo.
 *
 * Qué se comprueba:
 *   1. Sin sesión, toda ruta protegida pide iniciar sesión.
 *   2. Un participante no entra a ninguna ruta de administración ni de organizador.
 *   3. Un organizador no entra a las acciones que son solo del administrador.
 *   4. Todo POST exige el token CSRF.
 *   5. S1 y S2, en el controlador y también en el servicio.
 *   6. Las compuertas por separado (rol, permiso, token).
 *
 * Los controladores se ejecutan de verdad, con una subclase que reemplaza únicamente redirect()
 * (que haría header+exit) y render() (que imprimiría la vista).
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/autorizacion_test.php
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

class AutorizacionTest extends TestCase
{
    /** @var array<int, array{metodo:string, ruta:string, controlador:string, accion:string, parametros:int}> */
    private array $rutas = [];

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_POST = [];
        $this->rutas = $this->leerRutas();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_REFERER']);
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ──────────────────────────────────────────────────────────────

    /** La tabla de rutas, tal como está escrita en config/routes.php. */
    private function leerRutas(): array
    {
        $fuente = (string) file_get_contents(BASE_PATH . '/config/routes.php');
        preg_match_all('/\$router->(get|post)\(\s*\'([^\']*)\'\s*,\s*\'(\w+)\'\s*,\s*\'(\w+)\'\s*\)/', $fuente, $m, PREG_SET_ORDER);

        return array_map(fn(array $r) => [
            'metodo'      => strtoupper($r[1]),
            'ruta'        => $r[2],
            'controlador' => $r[3],
            'accion'      => $r[4],
            'parametros'  => substr_count($r[2], '{'),
        ], $m);
    }

    /** Un controlador con redirect() y render() neutralizados; todo lo demás es el código real. */
    private function controlador(string $clase): BaseController
    {
        $obs = "Observable{$clase}";
        if (!class_exists($obs, false)) {
            eval("final class {$obs} extends {$clase} {
                protected function render(string \$view, array \$data = [], string \$layout = 'public'): void {}
                protected function redirect(string \$url): never { throw new RedirigidoA(\$url); }
            }");
        }
        return new $obs();
    }

    /**
     * Ejecuta la acción de una ruta y devuelve lo que lanzó (o null si terminó sin lanzar nada).
     * Los parámetros de la ruta valen '1' salvo que se pase otro valor en $id.
     */
    private function correrAccion(array $ruta, string $id = '1'): ?Throwable
    {
        try {
            $this->controlador($ruta['controlador'])->{$ruta['accion']}(...array_fill(0, $ruta['parametros'], $id));
            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** Las rutas cuyo camino empieza con alguno de los prefijos. */
    private function rutasBajo(string ...$prefijos): array
    {
        return array_values(array_filter($this->rutas, function (array $r) use ($prefijos) {
            foreach ($prefijos as $p) {
                if ($r['ruta'] === $p || str_starts_with($r['ruta'], $p . '/')) return true;
            }
            return false;
        }));
    }

    private function nombre(array $r): string
    {
        return "{$r['metodo']} /{$r['ruta']} → {$r['controlador']}::{$r['accion']}";
    }

    private function conToken(): void
    {
        $_POST = ['csrf_token' => Csrf::generate()];
    }

    /** Un partido de liga ya jugado (2-1), de un torneo cuyo organizador es $organizadorId. */
    private function partidoJugado(int $organizadorId = Fixtures::ORGANIZADOR): array
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4, ['organizador_id' => $organizadorId]);
        $enfId = Fixtures::partidosPendientes($torneoId)[0];
        (new ResultadoService())->cargar($enfId, 2.0, 1.0, Fixtures::ADMIN);
        return [$torneoId, $enfId];
    }

    private function resultadoDe(int $enfId): array
    {
        return $this->db->query("SELECT puntos_a, puntos_b, estado FROM resultados WHERE enfrentamiento_id = {$enfId}")->fetch(PDO::FETCH_ASSOC);
    }

    private function cantidadDeSolicitudes(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM solicitudes_correccion')->fetchColumn();
    }

    // ─── 1 a 4. Recorrido de todas las rutas ────────────────────────────────

    public function test_la_tabla_de_rutas_se_leyo_completa(): void
    {
        // Control: si el lector no encontrara las rutas, los recorridos de abajo pasarían en vacío.
        $this->assertGreaterThan(60, count($this->rutas));
        $this->assertGreaterThan(40, count($this->rutasBajo('admin')));
        $this->assertGreaterThan(8, count($this->rutasBajo('organizador')));
        $this->assertGreaterThan(3, count($this->rutasBajo('participante')));
    }

    public function test_sin_sesion_toda_ruta_protegida_pide_iniciar_sesion(): void
    {
        Fixtures::cerrarSesion();
        $malas = [];

        foreach ($this->rutasBajo('admin', 'organizador', 'participante') as $r) {
            $lanzado = $this->correrAccion($r);
            if (!($lanzado instanceof SesionRequerida)) {
                $malas[] = $this->nombre($r) . ' → ' . ($lanzado ? get_class($lanzado) : 'terminó sin lanzar nada');
            }
        }
        $this->assertSame([], $malas, "rutas que se pueden usar sin iniciar sesión:\n        - " . implode("\n        - ", $malas));
    }

    public function test_un_participante_no_entra_a_ninguna_ruta_de_administracion_ni_de_organizador(): void
    {
        Fixtures::loguearComo(Fixtures::usuario('Persona Participante', 'participante.auth@test.local', 'Clave-Segura-9876!', 3));
        $malas = [];

        foreach ($this->rutasBajo('admin', 'organizador') as $r) {
            $this->conToken();   // con token válido: lo único que puede frenarlo es el rol
            $lanzado = $this->correrAccion($r);
            if (!($lanzado instanceof AccesoDenegado)) {
                $malas[] = $this->nombre($r) . ' → ' . ($lanzado ? get_class($lanzado) . ': ' . $lanzado->getMessage() : 'terminó sin lanzar nada');
            }
        }
        $this->assertSame([], $malas, "rutas a las que entra un participante:\n        - " . implode("\n        - ", $malas));
    }

    public function test_un_organizador_no_entra_a_las_acciones_solo_del_administrador(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $soloAdmin = array_values(array_filter($this->rutas, fn(array $r) =>
            $r['controlador'] === 'AdminController'
            || ($r['controlador'] === 'CorreccionController' && in_array($r['accion'], ['index', 'aprobar', 'rechazar'], true))
            || ($r['controlador'] === 'TorneoController' && $r['accion'] === 'eliminar')
            || ($r['controlador'] === 'ResultadoController' && $r['accion'] === 'corregir')
        ));
        $this->assertGreaterThan(30, count($soloAdmin), 'control: se encontraron las acciones de administración');

        $malas = [];
        foreach ($soloAdmin as $r) {
            $this->conToken();
            $lanzado = $this->correrAccion($r);
            if (!($lanzado instanceof AccesoDenegado)) {
                $malas[] = $this->nombre($r) . ' → ' . ($lanzado ? get_class($lanzado) . ': ' . $lanzado->getMessage() : 'terminó sin lanzar nada');
            }
        }
        $this->assertSame([], $malas, "acciones de administración a las que entra un organizador:\n        - " . implode("\n        - ", $malas));
    }

    public function test_todo_post_exige_el_token_csrf(): void
    {
        $participante = Fixtures::usuario('Persona Participante', 'participante.csrf@test.local', 'Clave-Segura-9876!', 3);

        // Las acciones sobre una ronda la buscan antes de mirar el token (si no existe, avisan y
        // vuelven): para que el token sea lo único que las frene, la ronda tiene que existir.
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId = (string) $this->db->query("SELECT id FROM rondas WHERE torneo_id = {$torneoId} ORDER BY numero LIMIT 1")->fetchColumn();
        $this->assertTrue($rondaId !== '', 'control: hay una ronda real para probar');

        $malas = [];
        $revisadas = 0;

        foreach ($this->rutas as $r) {
            if ($r['metodo'] !== 'POST') continue;

            // El administrador atraviesa las compuertas de rol y de propiedad de admin/ y organizador/;
            // las del participante y las públicas se prueban con quien corresponde.
            if (str_starts_with($r['ruta'], 'participante')) Fixtures::loguearComo($participante);
            elseif (in_array($r['ruta'], ['login', 'registro'], true)) Fixtures::cerrarSesion();
            else Fixtures::loguearComo(Fixtures::ADMIN);

            $_POST = [];     // sin token
            $lanzado = $this->correrAccion($r, str_contains($r['ruta'], 'rondas/{id}') ? $rondaId : '1');
            $revisadas++;
            if (!($lanzado instanceof AccesoDenegado) || !str_contains($lanzado->getMessage(), 'formulario venció')) {
                $malas[] = $this->nombre($r) . ' → ' . ($lanzado ? get_class($lanzado) . ': ' . $lanzado->getMessage() : 'se ejecutó sin token');
            }
        }

        $this->assertGreaterThan(30, $revisadas, 'control: se recorrieron los POST');
        $this->assertSame([], $malas, "POST que no exigen el token CSRF:\n        - " . implode("\n        - ", $malas));
    }

    // ─── 5. S1: corregir es del administrador ───────────────────────────────

    public function test_un_organizador_con_el_permiso_no_puede_aplicar_una_correccion_directa(): void
    {
        [$torneoId, $enfId] = $this->partidoJugado();
        $original = $this->resultadoDe($enfId);

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertTrue((new PermisoService())->puedeUsuarioActual('resultados', 'editar'),
            'control: el organizador SÍ tiene el permiso resultados/editar (es lo que lo hacía posible)');

        $_POST = [
            'csrf_token' => Csrf::generate(), 'enfrentamiento_id' => (string) $enfId, 'torneo_id' => (string) $torneoId,
            'puntos_a' => '0', 'puntos_b' => '5', 'motivo_correccion' => 'Intento de corregir sin aprobación',
        ];
        $lanzado = $this->correrAccion(['controlador' => 'ResultadoController', 'accion' => 'corregir', 'parametros' => 0]);

        $this->assertTrue($lanzado instanceof AccesoDenegado, 'la ruta de corrección directa es solo del administrador');
        $this->assertSame($original, $this->resultadoDe($enfId), 'y el resultado no se tocó');
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'corregir_resultado'")->fetchColumn());
    }

    public function test_el_administrador_si_corrige_directamente(): void
    {
        [$torneoId, $enfId] = $this->partidoJugado();
        $this->conToken();
        $_POST += [
            'enfrentamiento_id' => (string) $enfId, 'torneo_id' => (string) $torneoId,
            'puntos_a' => '0', 'puntos_b' => '5', 'motivo_correccion' => 'Se cargó al revés',
        ];

        $lanzado = $this->correrAccion(['controlador' => 'ResultadoController', 'accion' => 'corregir', 'parametros' => 0]);

        $this->assertTrue($lanzado instanceof RedirigidoA, 'termina redirigiendo a la gestión del torneo');
        $this->assertSame('corregido', $this->resultadoDe($enfId)['estado']);
        $this->assertSame(5.0, (float) $this->resultadoDe($enfId)['puntos_b']);
    }

    public function test_el_servicio_tampoco_aplica_una_correccion_si_quien_la_pide_no_es_administrador(): void
    {
        [, $enfId] = $this->partidoJugado();
        $original = $this->resultadoDe($enfId);
        $participante = Fixtures::usuario('Persona Participante', 'participante.corr@test.local', 'Clave-Segura-9876!', 3);

        foreach ([Fixtures::ORGANIZADOR => 'el organizador del torneo', $participante => 'un participante', 999999 => 'una cuenta inexistente'] as $quien => $descripcion) {
            $e = $this->assertThrows(
                fn() => (new ResultadoService())->corregir($enfId, 0.0, 5.0, 'Motivo de prueba largo', (int) $quien),
                'Solo un administrador', "{$descripcion} no puede aplicar una corrección");
            $this->assertTrue($e instanceof ErrorNegocio);
        }
        $this->assertSame($original, $this->resultadoDe($enfId));

        (new ResultadoService())->corregir($enfId, 0.0, 5.0, 'Motivo de prueba largo', Fixtures::ADMIN);
        $this->assertSame('corregido', $this->resultadoDe($enfId)['estado'], 'con un administrador, sí');
    }

    // ─── 5. S2: solicitar, solo en SUS torneos ──────────────────────────────

    private function solicitarPorControlador(int $enfId, int $torneoId): ?Throwable
    {
        $_POST = [
            'csrf_token' => Csrf::generate(), 'enfrentamiento_id' => (string) $enfId, 'torneo_id' => (string) $torneoId,
            'puntos_a' => '0', 'puntos_b' => '5', 'motivo_correccion' => 'Se anotó al revés en la planilla',
        ];
        return $this->correrAccion(['controlador' => 'CorreccionController', 'accion' => 'solicitar', 'parametros' => 0]);
    }

    public function test_el_organizador_del_torneo_puede_solicitar_la_correccion(): void
    {
        [$torneoId, $enfId] = $this->partidoJugado(Fixtures::ORGANIZADOR);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $lanzado = $this->solicitarPorControlador($enfId, $torneoId);

        $this->assertTrue($lanzado instanceof RedirigidoA);
        $this->assertSame(1, $this->cantidadDeSolicitudes());
        $this->assertSame(Fixtures::ORGANIZADOR, (int) $this->db->query('SELECT solicitado_por FROM solicitudes_correccion')->fetchColumn());
    }

    public function test_un_organizador_no_puede_solicitar_correcciones_en_el_torneo_de_otro(): void
    {
        [$torneoId, $enfId] = $this->partidoJugado(Fixtures::ORGANIZADOR);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR2);       // otro organizador, con el mismo permiso

        $lanzado = $this->solicitarPorControlador($enfId, $torneoId);

        $this->assertTrue($lanzado instanceof RedirigidoA, 'lo manda de vuelta a sus torneos');
        $this->assertSame('/organizador/torneos', $lanzado->url);
        $this->assertSame('No tenés acceso a este torneo.', Session::getFlash('error'));
        $this->assertSame(0, $this->cantidadDeSolicitudes(), 'y no se registró nada');
    }

    public function test_el_administrador_puede_solicitar_en_cualquier_torneo(): void
    {
        [$torneoId, $enfId] = $this->partidoJugado(Fixtures::ORGANIZADOR);

        $lanzado = $this->solicitarPorControlador($enfId, $torneoId);

        $this->assertTrue($lanzado instanceof RedirigidoA);
        $this->assertSame(1, $this->cantidadDeSolicitudes());
    }

    public function test_el_servicio_tambien_exige_ser_el_organizador_del_torneo(): void
    {
        [, $enfId] = $this->partidoJugado(Fixtures::ORGANIZADOR);
        $participante = Fixtures::usuario('Persona Participante', 'participante.sol@test.local', 'Clave-Segura-9876!', 3);

        foreach ([Fixtures::ORGANIZADOR2 => 'un organizador ajeno', $participante => 'un participante', 999999 => 'una cuenta inexistente'] as $quien => $descripcion) {
            $e = $this->assertThrows(
                fn() => (new CorreccionService())->solicitar($enfId, 0.0, 5.0, 'Motivo de prueba largo', (int) $quien),
                'Solo el organizador del torneo', "{$descripcion} no puede pedir la corrección");
            $this->assertTrue($e instanceof ErrorNegocio);
        }
        $this->assertSame(0, $this->cantidadDeSolicitudes());

        (new CorreccionService())->solicitar($enfId, 0.0, 5.0, 'Motivo de prueba largo', Fixtures::ORGANIZADOR);
        $this->assertSame(1, $this->cantidadDeSolicitudes(), 'el organizador del torneo, sí');
    }

    public function test_el_flujo_solicitar_y_aprobar_sigue_funcionando_de_punta_a_punta(): void
    {
        [, $enfId] = $this->partidoJugado(Fixtures::ORGANIZADOR);
        (new CorreccionService())->solicitar($enfId, 0.0, 5.0, 'Motivo de prueba largo', Fixtures::ORGANIZADOR);
        $solicitudId = (int) $this->db->query('SELECT id FROM solicitudes_correccion')->fetchColumn();

        (new CorreccionService())->aprobar($solicitudId, Fixtures::ADMIN);

        $this->assertSame('corregido', $this->resultadoDe($enfId)['estado']);
        $this->assertSame('aprobada', $this->db->query("SELECT estado FROM solicitudes_correccion WHERE id = {$solicitudId}")->fetchColumn());
    }

    // ─── 6. Las compuertas por separado ─────────────────────────────────────

    public function test_requireRole_deja_pasar_al_rol_correcto_y_lanza_con_otro(): void
    {
        $this->assertDoesNotThrow(fn() => Auth::requireRole('administrador'));
        $this->assertDoesNotThrow(fn() => Auth::requireOrganizador(), 'el administrador también es organizador');

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertDoesNotThrow(fn() => Auth::requireOrganizador());
        $e = $this->assertThrows(fn() => Auth::requireAdmin(), 'no tiene acceso');
        $this->assertTrue($e instanceof AccesoDenegado);
    }

    public function test_requireLogin_sin_sesion_lanza_y_deja_el_aviso(): void
    {
        Fixtures::cerrarSesion();

        $e = $this->assertThrows(fn() => Auth::requireLogin(), 'iniciar sesión');
        $this->assertTrue($e instanceof SesionRequerida);
        $this->assertStringContainsString('iniciar sesión', (string) Session::getFlash('error'), 'el aviso que verá en /login');
    }

    public function test_el_token_csrf_se_valida_y_un_token_raro_no_rompe_nada(): void
    {
        $token = Csrf::generate();

        $this->assertDoesNotThrow(function () use ($token) { $_POST = ['csrf_token' => $token]; Csrf::checkOrFail(); });
        foreach ([[], ['csrf_token' => ''], ['csrf_token' => 'otro'], ['csrf_token' => ['arreglo']], ['csrf_token' => null]] as $posteo) {
            $_POST = $posteo;
            $e = $this->assertThrows(fn() => Csrf::checkOrFail(), 'formulario venció');
            $this->assertTrue($e instanceof AccesoDenegado);
        }
    }

    public function test_requirePermiso_sin_destino_lanza_y_con_destino_redirige(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $controlador = new class extends BaseController {
            public function sinDestino(): void  { $this->requirePermiso('torneos', 'crear'); }
            public function conDestino(): void  { $this->requirePermiso('torneos', 'crear', '/organizador'); }
            protected function redirect(string $url): never { throw new RedirigidoA($url); }
        };
        $this->assertFalse((new PermisoService())->puedeUsuarioActual('torneos', 'crear'), 'control: un organizador no crea torneos');

        $e = $this->assertThrows(fn() => $controlador->sinDestino(), 'torneos');
        $this->assertTrue($e instanceof AccesoDenegado);

        $e = $this->assertThrows(fn() => $controlador->conDestino(), 'redirect');
        $this->assertTrue($e instanceof RedirigidoA);
        $this->assertSame('/organizador', $e->url);
    }

    public function test_una_compuerta_no_puede_quedar_tragada_por_un_catch_de_runtimeexception(): void
    {
        // Los controladores atrapan RuntimeException alrededor de los servicios. Si AccesoDenegado o
        // SesionRequerida lo fueran, un require*() dentro de un try quedaría convertido en un aviso.
        $this->assertFalse(is_subclass_of(AccesoDenegado::class, RuntimeException::class));
        $this->assertFalse(is_subclass_of(SesionRequerida::class, RuntimeException::class));
    }
}

TestCase::correrSiEsScript(AutorizacionTest::class);
