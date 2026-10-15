<?php
declare(strict_types=1);

/**
 * Test de que las vistas no consultan la base (M1 del análisis de la letra).
 *
 * Qué pasaba antes: 7 vistas creaban modelos o servicios y pedían datos por su cuenta. Las más
 * costosas eran las de partidos, que hacían UNA consulta por cada partido dibujado (un torneo de
 * 32 partidos, 32 consultas solo para pintar los marcadores), y el menú lateral, que contaba los
 * registros y las correcciones pendientes en cada pantalla del panel. Además, una vista que
 * consulta es una vista que no se puede probar sin base de datos y que esconde cuánto trabajo hace
 * la página.
 *
 * Qué se comprueba:
 *   - Que ningún archivo de app/views/ cree un modelo, un servicio o un controlador, ni llame a
 *     Database o PDO. Este test es la regla: una vista nueva que consulte lo hace fallar.
 *   - View::resultadoDe(): el resultado de un partido sale de la propia fila del partido.
 *   - EnfrentamientoModel::getByRonda() trae ese resultado (y la marca de «corregido»).
 *   - BaseController::pendientesDelMenu(): los números del menú los calcula el controlador, solo
 *     para el administrador y solo en el layout del panel.
 *   - Que el menú dibuje esos números sin consultar nada.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/vistas_sin_modelos_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

/** Deja ver el cálculo del menú, que es protegido. */
final class ControladorDeMenu extends BaseController
{
    public function menu(string $layout): array
    {
        return $this->pendientesDelMenu($layout);
    }
}

class VistasSinModelosTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
    }

    /** @return array<string,string> ruta relativa => contenido, de cada archivo de app/views */
    private function vistas(): array
    {
        $dir = APP_PATH . '/views';
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $out[str_replace('\\', '/', substr($archivo->getPathname(), strlen($dir) + 1))] = (string) file_get_contents($archivo->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    /** El código de una vista sin los comentarios (los comentarios pueden nombrar clases). */
    private function sinComentarios(string $codigo): string
    {
        $limpio = '';
        foreach (token_get_all($codigo) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $limpio .= is_array($t) ? $t[1] : $t;
        }
        return $limpio;
    }

    // ── la regla ───────────────────────────────────────────────────────

    public function test_ninguna_vista_crea_modelos_servicios_ni_controladores(): void
    {
        $vistas = $this->vistas();
        $this->assertGreaterThan(40, count($vistas), 'se están revisando las vistas de verdad');

        $infractoras = [];
        foreach ($vistas as $ruta => $codigo) {
            $codigo = $this->sinComentarios($codigo);
            if (preg_match('/\bnew\s+\\\\?\w*(Model|Service|Controller)\b/', $codigo, $m)) {
                $infractoras[] = "{$ruta}: {$m[0]}";
            }
        }
        $this->assertSame([], $infractoras, "las vistas no crean modelos ni servicios:\n  " . implode("\n  ", $infractoras));
    }

    public function test_ninguna_vista_llama_a_la_base_ni_a_clases_de_datos(): void
    {
        $infractoras = [];
        foreach ($this->vistas() as $ruta => $codigo) {
            $codigo = $this->sinComentarios($codigo);
            if (preg_match('/\b(Database|PDO)\b|\b\w+(Model|Service)::/', $codigo, $m)) {
                $infractoras[] = "{$ruta}: {$m[0]}";
            }
        }
        $this->assertSame([], $infractoras, "las vistas no llaman a la base ni a un modelo/servicio:\n  " . implode("\n  ", $infractoras));
    }

    public function test_las_vistas_que_se_revisaron_son_las_que_estan_en_el_disco(): void
    {
        // Si alguien mueve las vistas de lugar, la revisión de arriba se quedaría sin mirar nada.
        $vistas = $this->vistas();
        $this->assertTrue(isset($vistas['partials/match_list.php']));
        $this->assertTrue(isset($vistas['partials/eliminacion_bracket.php']));
        $this->assertTrue(isset($vistas['partials/sidebar.php']));
        $this->assertTrue(isset($vistas['layouts/admin.php']));
    }

    // ── resultadoDe ────────────────────────────────────────────────────

    public function test_el_resultado_sale_de_la_fila_del_partido(): void
    {
        $this->assertSame(null, View::resultadoDe(['id' => 1, 'puntos_a' => null, 'puntos_b' => null]), 'sin jugar: no hay resultado');
        $this->assertSame(null, View::resultadoDe(['id' => 1]), 'una fila sin las columnas tampoco');

        $this->assertSame(
            ['puntos_a' => '2.00', 'puntos_b' => '1.00', 'corregido' => false],
            View::resultadoDe(['puntos_a' => '2.00', 'puntos_b' => '1.00', 'resultado_corregido' => 0])
        );
        $this->assertSame(true, View::resultadoDe(['puntos_a' => '0.00', 'puntos_b' => '3.00', 'resultado_corregido' => '1'])['corregido']);
        $this->assertSame(
            ['puntos_a' => '0.00', 'puntos_b' => '0.00', 'corregido' => false],
            View::resultadoDe(['puntos_a' => '0.00', 'puntos_b' => '0.00']),
            'un 0 — 0 es un resultado, no «sin resultado»'
        );
    }

    public function test_los_partidos_de_una_ronda_traen_su_resultado(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4, ['permite_empates' => '1']);
        $ronda = (new RondaModel())->getByTorneo($tid)[0];
        $partidos = (new EnfrentamientoModel())->getByRonda((int) $ronda['id']);
        $this->assertGreaterThan(0, count($partidos));
        foreach ($partidos as $partido) {
            $this->assertSame(null, View::resultadoDe($partido), 'ninguno se jugó todavía');
        }

        $primero = $partidos[0];
        (new ResultadoService())->cargar((int) $primero['id'], 3.0, 1.0, Fixtures::ADMIN);

        $partidos = (new EnfrentamientoModel())->getByRonda((int) $ronda['id']);
        $this->assertCount(count($partidos), array_unique(array_column($partidos, 'id')), 'cada partido aparece una sola vez');
        $jugado = array_values(array_filter($partidos, fn($x) => (int) $x['id'] === (int) $primero['id']))[0];
        $res = View::resultadoDe($jugado);
        $this->assertSame([3.0, 1.0, false], [(float) $res['puntos_a'], (float) $res['puntos_b'], $res['corregido']]);

        (new ResultadoService())->corregir((int) $primero['id'], 2.0, 1.0, 'Error de planilla en el acta', Fixtures::ADMIN);
        $partidos = (new EnfrentamientoModel())->getByRonda((int) $ronda['id']);
        $jugado = array_values(array_filter($partidos, fn($x) => (int) $x['id'] === (int) $primero['id']))[0];
        $res = View::resultadoDe($jugado);
        $this->assertSame([2.0, 1.0, true], [(float) $res['puntos_a'], (float) $res['puntos_b'], $res['corregido']], 'y la corrección se nota');
    }

    public function test_la_lista_de_partidos_se_dibuja_con_los_datos_que_recibe(): void
    {
        $torneo   = ['id' => 1, 'estado' => 'en_curso'];
        $partidos = [
            ['id' => 11, 'orden' => 1, 'es_bye' => 0, 'estado' => 'finalizado', 'fecha_programada' => null,
             'participante_a_nombre' => 'Ana', 'participante_b_nombre' => 'Beto', 'nick_a' => null, 'nick_b' => null,
             'equipo_a_nombre' => null, 'equipo_b_nombre' => null,
             'puntos_a' => '3.00', 'puntos_b' => '1.00', 'resultado_corregido' => 1],
            ['id' => 12, 'orden' => 2, 'es_bye' => 0, 'estado' => 'pendiente', 'fecha_programada' => null,
             'participante_a_nombre' => 'Cami', 'participante_b_nombre' => 'Dani', 'nick_a' => null, 'nick_b' => null,
             'equipo_a_nombre' => null, 'equipo_b_nombre' => null,
             'puntos_a' => null, 'puntos_b' => null, 'resultado_corregido' => null],
        ];

        ob_start();
        View::render('partials/match_list', ['partidos' => $partidos, 'torneo' => $torneo, 'puedeEditar' => false, 'csrf' => 'x'], '');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('3 — 1', $html, 'el marcador del partido jugado');
        $this->assertStringContainsString('Corregido', $html, 'con la marca de corregido');
        $this->assertStringContainsString('vs', $html, 'el partido pendiente muestra «vs»');
        $this->assertStringContainsString('Cami', $html);
    }

    // ── el menú ────────────────────────────────────────────────────────

    public function test_el_menu_cuenta_los_pendientes_solo_para_el_administrador_en_el_panel(): void
    {
        $c = new ControladorDeMenu();
        $vacio = ['registros' => 0, 'correcciones' => 0];

        $this->assertSame($vacio, $c->menu('admin'), 'sin sesión no se consulta nada');

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertSame($vacio, $c->menu('admin'), 'un organizador no ve contadores de administrador');

        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->assertSame($vacio, $c->menu('public'), 'en una página pública no hace falta');
        $this->assertSame($vacio, $c->menu(''), 'tampoco sin layout');

        $antes = $c->menu('admin');
        $this->assertSame(['registros', 'correcciones'], array_keys($antes));
        $this->assertSame((new RegistroService())->countPendientes(), $antes['registros']);
        $this->assertSame((new CorreccionService())->countPendientes(), $antes['correcciones']);
    }

    public function test_los_contadores_siguen_a_los_registros_pendientes(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);
        $c = new ControladorDeMenu();
        $antes = $c->menu('admin')['registros'];

        $id = Fixtures::usuario('Pendiente Uno', 'pendiente.uno@test.local', 'Clave-Segura-1', 3);
        $this->db->exec("UPDATE usuarios SET estado = 'pendiente' WHERE id = {$id}");
        $this->db->exec("INSERT INTO participantes (usuario_id, nombre, estado) VALUES ({$id}, 'Pendiente Uno', 'pendiente')");

        $this->assertSame($antes + 1, $c->menu('admin')['registros'], 'un registro nuevo sube el número');
    }

    public function test_el_menu_dibuja_los_numeros_que_recibe_sin_consultar(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);

        $dibujar = function (array $pendientes): string {
            $_sitio = (new ConfiguracionSistemaService())->sitio();   // lo inyecta BaseController::render()
            ob_start();
            $_pendientes = $pendientes;
            require APP_PATH . '/views/partials/sidebar.php';
            return (string) ob_get_clean();
        };

        $con = $dibujar(['registros' => 7, 'correcciones' => 12]);
        $this->assertTrue((bool) preg_match('~href="/admin/registros">\s*<span>Registros</span>\s*<span class="chip warning[^"]*"[^>]*>7</span>~', $con), 'el número de registros');
        $this->assertTrue((bool) preg_match('~href="/admin/correcciones">\s*<span>Correcciones</span>\s*<span class="chip warning[^"]*"[^>]*>12</span>~', $con), 'el de correcciones');

        $sin = $dibujar(['registros' => 0, 'correcciones' => 0]);
        $this->assertStringNotContainsString('chip warning', $sin, 'con cero no hay marca');
        $this->assertStringContainsString('Registros', $sin);

        $_sitio = (new ConfiguracionSistemaService())->sitio();   // lo inyecta BaseController::render()
        ob_start();
        require APP_PATH . '/views/partials/sidebar.php';       // sin la variable: sigue andando
        $sinVariable = (string) ob_get_clean();
        $this->assertStringNotContainsString('chip warning', $sinVariable);
    }
}

TestCase::correrSiEsScript(VistasSinModelosTest::class);
