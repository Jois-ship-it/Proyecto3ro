<?php
declare(strict_types=1);

/**
 * Test de las listas con búsqueda, filtros y paginación (B8 y M4 del plan).
 *
 * Qué pasaba antes:
 *   - La lista pública de torneos traía TODOS los torneos y los filtraba en PHP (formato y
 *     estado); no había búsqueda por nombre. La portada traía todos para quedarse con uno.
 *   - Usuarios, participantes, equipos y torneos del panel se listaban enteros, sin búsqueda ni
 *     páginas. Solo la auditoría paginaba, sin filtros.
 *
 * Qué pasa ahora: el filtro, el orden y el recorte de la página los hace la consulta (WHERE,
 * LIMIT/OFFSET y un COUNT aparte). Acá se comprueba, lista por lista:
 *   - que cada filtro deja pasar lo que corresponde y nada más;
 *   - que buscar «50%» o «a_b» busca esos caracteres (los comodines del LIKE se escapan) y que un
 *     texto con comillas o SQL no cambia el resultado ni rompe nada;
 *   - que el total (COUNT) coincide con las filas y que las páginas, juntas, cubren todo sin
 *     repetir ni saltear (el orden es estable);
 *   - que el controlador sanea lo que llega por la dirección y que la pantalla muestra el
 *     resumen, los enlaces que conservan los filtros y el texto buscado ESCAPADO.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/listados_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

/** Controladores que, en vez de mandar la vista a la salida, la devuelven como texto. */
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
            parent::render($view, $data, '');          // sin layout: solo la vista
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }
}

final class PublicoObservable extends PublicoController { use ObservaLaVista; }
final class TorneoObservable  extends TorneoController  { use ObservaLaVista; }
final class AdminObservable   extends AdminController   { use ObservaLaVista; }
final class OrganizadorObservable  extends OrganizadorController  { use ObservaLaVista; }
final class ParticipanteObservable extends ParticipanteController { use ObservaLaVista; }
final class CorreccionObservable   extends CorreccionController   { use ObservaLaVista; }

class ListadosTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    /**
     * Crea un torneo y le pone el estado que se pide (el alta siempre arranca en inscripción).
     * «publico» => '0' lo deja privado: en el formulario «público» es una casilla, y el servicio
     * la lee por su sola presencia, de modo que para apagarla hay que sacarla después del alta.
     */
    private function torneo(string $nombre, string $slug = 'liga', string $estado = 'inscripcion', array $op = []): int
    {
        $privado = ($op['publico'] ?? '1') === '0';
        unset($op['publico']);
        $id = Fixtures::torneo($slug, array_merge(['nombre' => $nombre], $op));
        if ($privado) {
            (new TorneoModel())->update($id, ['publico' => 0]);
        }
        if ($estado !== 'inscripcion') {
            (new TorneoModel())->updateEstado($id, $estado);
        }
        return $id;
    }

    /**
     * Ocho torneos con nombres pensados para las búsquedas. Solo cinco son visibles al público.
     * @return array<string,int> clave corta => id
     */
    private function torneosDeBusqueda(): array
    {
        return [
            'nandu'     => $this->torneo('Copa Ñandú',        'liga',                'inscripcion'),
            'verano'    => $this->torneo('Liga Verano',       'liga',                'en_curso'),
            'cincuenta' => $this->torneo('Liga 50% Off',      'suizo',               'inscripcion'),
            'guion'     => $this->torneo('Liga_Z',            'eliminacion_directa', 'finalizado'),
            'avila'     => $this->torneo('Copa Ávila',        'suizo',               'en_curso'),
            'borrador'  => $this->torneo('Borrador Secreto',  'liga',                'borrador'),
            'privado'   => $this->torneo('Torneo Privado',    'liga',                'inscripcion', ['publico' => '0']),
            'cancelado' => $this->torneo('Cancelado Club',    'liga',                'cancelado'),
        ];
    }

    /** @return int[] los ids de las filas */
    private function ids(array $filas): array
    {
        return array_map(fn(array $f) => (int) $f['id'], $filas);
    }

    // ═══ Lista pública de torneos ═════════════════════════════════════════

    public function test_lo_publico_no_incluye_borradores_privados_ni_cancelados(): void
    {
        $t = $this->torneosDeBusqueda();
        $m = new TorneoModel();

        $visibles = $this->ids($m->buscarPublicos([]));
        sort($visibles);
        $esperados = [$t['nandu'], $t['verano'], $t['cincuenta'], $t['guion'], $t['avila']];
        sort($esperados);
        $this->assertSame($esperados, $visibles);
        $this->assertSame(5, $m->contarPublicos([]));
        // Aunque se pida a la fuerza, un estado que no es público no devuelve esos torneos.
        $this->assertSame(0, $m->contarPublicos(['estado' => 'borrador']));
        $this->assertSame(0, $m->contarPublicos(['estado' => 'cancelado']));
    }

    public function test_la_busqueda_por_nombre_ignora_mayusculas_y_acentos(): void
    {
        $t = $this->torneosDeBusqueda();
        $m = new TorneoModel();

        $this->assertSame([$t['nandu']], $this->ids($m->buscarPublicos(['q' => 'nandu'])), 'la ñ y la tilde no se exigen');
        $this->assertSame([$t['nandu']], $this->ids($m->buscarPublicos(['q' => 'ÑANDÚ'])));
        $this->assertSame([$t['avila']], $this->ids($m->buscarPublicos(['q' => 'avila'])));
        $this->assertSame(3, $m->contarPublicos(['q' => 'LIGA']), 'Liga Verano, Liga 50% y Liga_Z');
        $this->assertSame(2, $m->contarPublicos(['q' => 'copa']));
        $this->assertSame(1, $m->contarPublicos(['q' => 'verano']), 'en cualquier parte del nombre');
        $this->assertSame(1, $m->contarPublicos(['q' => 'erano']), 'contiene, no solo empieza');
        $this->assertSame(0, $m->contarPublicos(['q' => 'secreto']), 'un borrador no se encuentra ni por el nombre');
    }

    public function test_los_comodines_se_buscan_como_caracteres(): void
    {
        $t = $this->torneosDeBusqueda();
        $m = new TorneoModel();

        $this->assertSame([$t['cincuenta']], $this->ids($m->buscarPublicos(['q' => '50%'])));
        $this->assertSame([$t['cincuenta']], $this->ids($m->buscarPublicos(['q' => '%'])), 'un % solo no es «todo»: es el único nombre con %');
        $this->assertSame([$t['guion']], $this->ids($m->buscarPublicos(['q' => '_'])), 'un _ solo no es «cualquier letra»');
        $this->assertSame([$t['guion']], $this->ids($m->buscarPublicos(['q' => 'Liga_Z'])));
        $this->assertSame(0, $m->contarPublicos(['q' => 'Liga_V']), '«Liga Verano» no debe responder a «Liga_V»');
        $this->assertSame(0, $m->contarPublicos(['q' => '|']), 'el carácter de escape tampoco se interpreta');
        $this->assertSame(0, $m->contarPublicos(['q' => '\\']));
    }

    public function test_un_texto_con_sql_no_cambia_el_resultado_ni_rompe_nada(): void
    {
        $this->torneosDeBusqueda();
        $m = new TorneoModel();
        foreach (["' OR '1'='1", "x' OR 1=1 -- ", "\"; DROP TABLE torneos; --", "') UNION SELECT * FROM usuarios -- ", "%' OR '%'='"] as $ataque) {
            $this->assertSame(0, $m->contarPublicos(['q' => $ataque]), 'sin coincidencias: ' . $ataque);
            $this->assertSame([], $m->buscarPublicos(['q' => $ataque]));
        }
        $this->assertSame(5, $m->contarPublicos([]), 'y la tabla sigue entera');
    }

    public function test_los_filtros_de_formato_y_estado_se_combinan(): void
    {
        $t = $this->torneosDeBusqueda();
        $m = new TorneoModel();

        $ids   = function (array $f) use ($m): array { $r = $this->ids($m->buscarPublicos($f)); sort($r); return $r; };
        $orden = function (array $a): array { sort($a); return $a; };

        $this->assertSame($orden([$t['cincuenta'], $t['avila']]), $ids(['tipo' => 'suizo']));
        $this->assertSame($orden([$t['verano'], $t['avila']]), $ids(['estado' => 'en_curso']));
        $this->assertSame([$t['avila']], $ids(['tipo' => 'suizo', 'estado' => 'en_curso']));
        $this->assertSame([$t['guion']], $ids(['tipo' => 'eliminacion_directa']));
        $this->assertSame([$t['avila']], $ids(['tipo' => 'suizo', 'estado' => 'en_curso', 'q' => 'copa']));
        $this->assertSame([], $ids(['tipo' => 'suizo', 'estado' => 'finalizado']));
    }

    public function test_el_total_coincide_con_las_filas_para_cada_combinacion_de_filtros(): void
    {
        $this->torneosDeBusqueda();
        $m = new TorneoModel();
        foreach ([[], ['q' => 'liga'], ['q' => 'copa', 'estado' => 'en_curso'], ['tipo' => 'suizo'], ['estado' => 'finalizado'], ['q' => 'zzz']] as $f) {
            $this->assertSame(count($m->buscarPublicos($f)), $m->contarPublicos($f), 'COUNT = filas con ' . json_encode($f));
        }
    }

    public function test_las_paginas_cubren_la_lista_publica_sin_repetir_ni_saltear(): void
    {
        $this->torneosDeBusqueda();
        $m = new TorneoModel();

        $todos = $this->ids($m->buscarPublicos([]));
        $this->assertSame($todos, array_values(array_unique($todos)));
        $rev = $todos;
        rsort($rev);
        $this->assertSame($rev, $todos, 'del más nuevo al más viejo');

        foreach ([1, 2, 3, 5] as $porPagina) {
            $juntas = [];
            $pag = Paginador::de($m->contarPublicos([]), 1, $porPagina);
            for ($n = 1; $n <= $pag->paginas; $n++) {
                $p = Paginador::de($m->contarPublicos([]), $n, $porPagina);
                $fila = $this->ids($m->buscarPublicos([], $p->porPagina, $p->desplazamiento));
                $this->assertTrue(count($fila) <= $porPagina, "la página $n no pasa de $porPagina");
                $juntas = array_merge($juntas, $fila);
            }
            $this->assertSame($todos, $juntas, "a $porPagina por página, las páginas juntas son la lista entera");
        }
    }

    public function test_el_destacado_de_la_portada_es_el_mas_reciente_en_curso(): void
    {
        $m = new TorneoModel();
        $this->assertSame(null, $m->destacadoPublico(), 'sin torneos públicos no hay destacado');

        $t = $this->torneosDeBusqueda();
        $d = $m->destacadoPublico();
        $this->assertSame($t['avila'], (int) $d['id'], 'entre los dos en curso, el más reciente');
        $this->assertSame('en_curso', $d['estado']);

        // Sin ninguno en curso, el más reciente de los públicos.
        $m->updateEstado($t['verano'], 'finalizado');
        $m->updateEstado($t['avila'], 'finalizado');
        $this->assertSame($t['avila'], (int) $m->destacadoPublico()['id'], 'el más reciente, que sigue siendo Ávila');
        $this->assertSame(array_keys($d), array_keys($m->destacadoPublico()), 'con las mismas columnas que la lista');
    }

    public function test_el_destacado_no_es_un_borrador_ni_un_cancelado(): void
    {
        $this->torneo('Solo Borrador', 'liga', 'borrador');
        $this->torneo('Solo Cancelado', 'liga', 'cancelado');
        $this->assertSame(null, (new TorneoModel())->destacadoPublico());
    }

    // ═══ Controlador público ══════════════════════════════════════════════

    public function test_el_controlador_publico_sanea_lo_que_llega_por_la_direccion(): void
    {
        $t = $this->torneosDeBusqueda();
        $_GET = ['q' => '  liga  ', 'tipo' => 'inventado', 'estado' => 'borrador', 'page' => '-4'];

        $c = new PublicoObservable();
        $c->torneos();

        $this->assertSame('public/torneos', PublicoObservable::$vista);
        $d = PublicoObservable::$datos;
        $this->assertSame(['q' => 'liga', 'tipo' => '', 'estado' => ''], $d['filtros'], 'el formato inventado y el estado no público se descartan');
        $this->assertSame(1, $d['pag']->pagina);
        $this->assertSame(3, $d['pag']->total);
        $this->assertCount(3, $d['torneos']);
        $this->assertSame(['liga', 'eliminacion_directa', 'suizo'], array_keys($d['tipos']), 'los formatos del filtro salen de la tabla');
        $this->assertSame(['inscripcion', 'en_curso', 'finalizado'], array_keys($d['estados']), 'no se ofrece «borrador»: no hay borradores públicos');
        $this->assertTrue(isset($t['nandu']));
    }

    public function test_una_pagina_fuera_de_rango_muestra_la_ultima(): void
    {
        $this->torneosDeBusqueda();
        $_GET = ['page' => '999'];
        $c = new PublicoObservable();
        $c->torneos();
        $this->assertSame(1, PublicoObservable::$datos['pag']->pagina, 'con 5 torneos a 12 por página hay una sola');
        $this->assertCount(5, PublicoObservable::$datos['torneos']);
    }

    public function test_la_pantalla_publica_muestra_resumen_y_enlaces_que_conservan_el_filtro(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->torneo(sprintf('Copa %02d', $i));
        }
        $this->torneo('Liga Otra');

        $_GET = ['q' => 'copa'];
        $c = new PublicoObservable();
        $c->torneos();
        $html = PublicoObservable::$html;

        $this->assertStringContainsString('Mostrando 1–12 de 14 torneos', $html);
        $this->assertSame(12, substr_count($html, 'Ver detalle'), 'doce tarjetas en la primera página');
        $this->assertStringContainsString('href="/torneos?q=copa&amp;page=2"', $html, 'el enlace a la página 2 conserva la búsqueda');
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringContainsString('value="copa"', $html, 'el cuadro de búsqueda recuerda lo escrito');
        $this->assertStringContainsString('Limpiar', $html);
        $this->assertStringNotContainsString('Liga Otra', $html);

        $_GET = ['q' => 'copa', 'page' => '2'];
        $c = new PublicoObservable();
        $c->torneos();
        $html = PublicoObservable::$html;
        $this->assertStringContainsString('Mostrando 13–14 de 14 torneos', $html);
        $this->assertSame(2, substr_count($html, 'Ver detalle'));
        $this->assertStringContainsString('href="/torneos?q=copa"', $html, 'volver a la 1 no lleva ?page=');
        $this->assertStringContainsString('rel="prev"', $html);
    }

    public function test_sin_resultados_la_pantalla_lo_dice_y_ofrece_limpiar(): void
    {
        $this->torneosDeBusqueda();
        $_GET = ['q' => 'zzzz'];
        $c = new PublicoObservable();
        $c->torneos();
        $this->assertStringContainsString('No encontramos torneos con esa búsqueda.', PublicoObservable::$html);
        $this->assertStringContainsString('Limpiar', PublicoObservable::$html);

        Fixtures::reset($this->db);
        $_GET = [];
        $c = new PublicoObservable();
        $c->torneos();
        $this->assertStringContainsString('No hay torneos públicos disponibles.', PublicoObservable::$html);
        $this->assertStringNotContainsString('Limpiar', PublicoObservable::$html, 'sin filtros no hay nada que limpiar');
    }

    public function test_lo_que_se_busca_se_dibuja_escapado(): void
    {
        $this->torneosDeBusqueda();
        $_GET = ['q' => '"><script>alert(1)</script>'];
        $c = new PublicoObservable();
        $c->torneos();
        $html = PublicoObservable::$html;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    public function test_el_nombre_de_un_torneo_se_dibuja_escapado_en_la_lista(): void
    {
        $this->torneo('<img src=x onerror=alert(1)>');
        $c = new PublicoObservable();
        $c->torneos();
        $this->assertStringNotContainsString('<img src=x', PublicoObservable::$html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', PublicoObservable::$html);
    }

    // ═══ Lista de torneos del panel ═══════════════════════════════════════

    public function test_el_panel_incluye_borradores_privados_y_cancelados(): void
    {
        $t = $this->torneosDeBusqueda();
        $m = new TorneoModel();
        $this->assertSame(8, $m->contarParaPanel([]));
        $this->assertSame(8, count($m->buscarParaPanel([])));
        $this->assertSame([$t['borrador']], $this->ids($m->buscarParaPanel(['estado' => 'borrador'])));
        $this->assertSame([$t['cancelado']], $this->ids($m->buscarParaPanel(['estado' => 'cancelado'])));
        $this->assertSame([$t['privado']], $this->ids($m->buscarParaPanel(['q' => 'privado'])));
        $filas = $m->buscarParaPanel(['q' => 'nandu']);
        $this->assertSame('Liga', $filas[0]['tipo_nombre'], 'con el nombre del formato');
        $this->assertSame('0', (string) $filas[0]['inscritos'], 'y la cantidad de inscritos');
    }

    public function test_un_organizador_solo_ve_sus_torneos_y_la_regla_va_en_la_consulta(): void
    {
        $mio  = $this->torneo('Mio A', 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR]);
        $mio2 = $this->torneo('Mio B', 'suizo', 'en_curso', ['organizador_id' => Fixtures::ORGANIZADOR]);
        $otro = $this->torneo('De otro', 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR2]);
        $m = new TorneoModel();

        $mios = $this->ids($m->buscarParaPanel([], null, 0, Fixtures::ORGANIZADOR));
        sort($mios);
        $this->assertSame([$mio, $mio2], $mios);
        $this->assertSame(2, $m->contarParaPanel([], Fixtures::ORGANIZADOR));
        $this->assertSame([$otro], $this->ids($m->buscarParaPanel([], null, 0, Fixtures::ORGANIZADOR2)));
        $this->assertSame(0, $m->contarParaPanel(['q' => 'otro'], Fixtures::ORGANIZADOR), 'buscar no le abre los torneos de otro');
        $this->assertSame([$mio2], $this->ids($m->buscarParaPanel(['estado' => 'en_curso'], null, 0, Fixtures::ORGANIZADOR)));
        $this->assertSame(3, $m->contarParaPanel([]), 'sin organizador (administrador) ve todos');
    }

    public function test_el_controlador_del_panel_aplica_la_regla_segun_quien_mira(): void
    {
        $this->torneo('Mio A', 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR]);
        $this->torneo('De otro', 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR2]);

        $c = new TorneoObservable();
        $c->listadoAdmin();
        $this->assertSame(2, TorneoObservable::$datos['pag']->total, 'el administrador ve los dos');

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $c = new TorneoObservable();
        $c->listadoAdmin();
        $this->assertSame(1, TorneoObservable::$datos['pag']->total, 'el organizador, el suyo');
        $this->assertSame('Mio A', TorneoObservable::$datos['torneos'][0]['nombre']);

        $_GET = ['q' => 'otro'];
        $c = new TorneoObservable();
        $c->listadoAdmin();
        $this->assertSame(0, TorneoObservable::$datos['pag']->total, 'ni buscándolo por el nombre');
        $this->assertStringContainsString('Ningún torneo coincide con la búsqueda.', TorneoObservable::$html);
    }

    public function test_el_panel_pagina_de_a_quince_y_cada_pagina_trae_su_resumen(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $this->torneo(sprintf('Torneo %02d', $i));
        }
        $c = new TorneoObservable();
        $c->listadoAdmin();
        $this->assertCount(15, TorneoObservable::$datos['torneos']);
        $this->assertStringContainsString('Mostrando 1–15 de 17 torneos', TorneoObservable::$html);

        $_GET = ['page' => '2'];
        $c = new TorneoObservable();
        $c->listadoAdmin();
        $this->assertCount(2, TorneoObservable::$datos['torneos']);
        $this->assertStringContainsString('Mostrando 16–17 de 17 torneos', TorneoObservable::$html);
        $this->assertStringContainsString('/reporte"', TorneoObservable::$html, 'cada fila ofrece su reporte');
    }

    // ═══ Participantes ════════════════════════════════════════════════════

    private function participantesDeBusqueda(): array
    {
        $m = new ParticipanteModel();
        return [
            'ana'   => $m->insert(['nombre' => 'Ana Pérez',   'nick' => 'anita', 'documento' => '1.111.111-1', 'email' => 'ana@test.local',  'estado' => 'activo']),
            'luis'  => $m->insert(['nombre' => 'Luis Gómez',  'nick' => 'lucho', 'documento' => '2.222.222-2', 'email' => 'luis@test.local', 'estado' => 'inactivo']),
            'maria' => $m->insert(['nombre' => 'María 100%',  'nick' => 'mari_x', 'documento' => '3.333.333-3', 'email' => 'maria@test.local', 'estado' => 'suspendido']),
        ];
    }

    public function test_los_participantes_se_buscan_por_nombre_apodo_documento_y_correo(): void
    {
        $p = $this->participantesDeBusqueda();
        $m = new ParticipanteModel();

        $this->assertSame([$p['ana']], $this->ids($m->buscarConEquipo(['q' => 'perez'])), 'por nombre, sin tilde');
        $this->assertSame([$p['luis']], $this->ids($m->buscarConEquipo(['q' => 'LUCHO'])), 'por apodo');
        $this->assertSame([$p['maria']], $this->ids($m->buscarConEquipo(['q' => '3.333'])), 'por documento');
        $this->assertSame([$p['ana']], $this->ids($m->buscarConEquipo(['q' => 'ana@test'])), 'por correo');
        $this->assertSame([$p['maria']], $this->ids($m->buscarConEquipo(['q' => '100%'])));
        $this->assertSame([$p['maria']], $this->ids($m->buscarConEquipo(['q' => 'mari_x'])));
        $this->assertSame(0, $m->contarBuscados(['q' => 'mari_y']), 'el _ no es un comodín');
        $this->assertSame(3, $m->contarBuscados([]));
    }

    public function test_los_participantes_se_filtran_por_estado_y_el_total_coincide(): void
    {
        $p = $this->participantesDeBusqueda();
        $m = new ParticipanteModel();
        $this->assertSame([$p['luis']], $this->ids($m->buscarConEquipo(['estado' => 'inactivo'])));
        $this->assertSame([$p['ana']], $this->ids($m->buscarConEquipo(['estado' => 'activo', 'q' => 'a'])), 'estado y texto juntos');
        foreach ([[], ['estado' => 'activo'], ['q' => 'a'], ['q' => 'zzz']] as $f) {
            $this->assertSame(count($m->buscarConEquipo($f)), $m->contarBuscados($f), 'COUNT = filas con ' . json_encode($f));
        }
    }

    public function test_cada_participante_sale_una_sola_vez_aunque_este_en_varios_equipos(): void
    {
        $p = $this->participantesDeBusqueda();
        $e1 = (new EquipoModel())->insert(['nombre' => 'Norte', 'categoria' => 'A', 'estado' => 'activo']);
        $e2 = (new EquipoModel())->insert(['nombre' => 'Sur',   'categoria' => 'B', 'estado' => 'activo']);
        (new EquipoModel())->agregarParticipante($e1, $p['ana']);
        (new EquipoModel())->agregarParticipante($e2, $p['ana']);

        $m = new ParticipanteModel();
        $filas = $m->buscarConEquipo(['q' => 'ana']);
        $this->assertCount(1, $filas, 'el JOIN con equipos no duplica la fila');
        $this->assertSame(1, $m->contarBuscados(['q' => 'ana']), 'ni el total');
        $equipos = explode(', ', (string) $filas[0]['equipos']);
        sort($equipos);
        $this->assertSame(['Norte', 'Sur'], $equipos);
    }

    public function test_los_participantes_se_ordenan_por_nombre_y_las_paginas_no_repiten(): void
    {
        Fixtures::participantes(17);                           // «Jugador 1» … «Jugador 17»
        $m = new ParticipanteModel();
        $todos = $this->ids($m->buscarConEquipo([]));
        $this->assertCount(17, $todos);
        $nombres = array_column($m->buscarConEquipo([]), 'nombre');
        $orden = $nombres;
        sort($orden);
        $this->assertSame($orden, $nombres, 'por nombre');

        $juntas = [];
        for ($n = 1; $n <= 3; $n++) {
            $p = Paginador::de($m->contarBuscados([]), $n, 7);
            $juntas = array_merge($juntas, $this->ids($m->buscarConEquipo([], $p->porPagina, $p->desplazamiento)));
        }
        $this->assertSame($todos, $juntas);
    }

    public function test_el_controlador_de_participantes_sanea_y_dibuja_el_filtro(): void
    {
        Fixtures::participantes(17);
        $_GET = ['q' => 'jugador', 'estado' => 'inventado', 'page' => 'x'];
        $c = new AdminObservable();
        $c->participantes();
        $d = AdminObservable::$datos;
        $this->assertSame(['q' => 'jugador', 'estado' => ''], $d['filtros']);
        $this->assertSame(17, $d['pag']->total);
        $this->assertCount(15, $d['participantes']);
        $html = AdminObservable::$html;
        $this->assertStringContainsString('Mostrando 1–15 de 17 participantes', $html);
        $this->assertStringContainsString('action="/admin/participantes"', $html);
        $this->assertStringContainsString('href="/admin/participantes?q=jugador&amp;page=2"', $html);
        $this->assertStringContainsString('Nombre, apodo, documento o correo', $html);
    }

    // ═══ Equipos ══════════════════════════════════════════════════════════

    public function test_los_equipos_se_buscan_por_nombre_categoria_y_disciplina(): void
    {
        $m = new EquipoModel();
        $a = $m->insert(['nombre' => 'Halcones', 'categoria' => 'Sub-18', 'disciplina' => 'Fútbol',  'estado' => 'activo']);
        $b = $m->insert(['nombre' => 'Águilas',  'categoria' => 'Mayores', 'disciplina' => 'Ajedrez', 'estado' => 'inactivo']);

        $this->assertSame([$a], $this->ids($m->buscarConConteo(['q' => 'halc'])));
        $this->assertSame([$b], $this->ids($m->buscarConConteo(['q' => 'aguilas'])), 'sin tilde');
        $this->assertSame([$a], $this->ids($m->buscarConConteo(['q' => 'sub-18'])), 'por categoría');
        $this->assertSame([$b], $this->ids($m->buscarConConteo(['q' => 'ajedrez'])), 'por disciplina');
        $this->assertSame([$b], $this->ids($m->buscarConConteo(['estado' => 'inactivo'])));
        $this->assertSame(2, $m->contarBuscados([]));
        $this->assertSame(0, $m->contarBuscados(['q' => 'halc', 'estado' => 'inactivo']));
    }

    public function test_cada_equipo_cuenta_sus_integrantes_aunque_se_busque(): void
    {
        $pids = Fixtures::participantes(6);
        $eids = Fixtures::equipos(2, $pids);                     // 3 integrantes cada uno
        $m = new EquipoModel();
        $filas = $m->buscarConConteo(['q' => 'Equipo 1']);
        $this->assertCount(1, $filas);
        $this->assertSame(3, (int) $filas[0]['total_integrantes'], 'el filtro no altera el conteo de integrantes');
        $this->assertSame(2, count($eids));
    }

    public function test_el_controlador_de_equipos_pagina(): void
    {
        $pids = Fixtures::participantes(6);
        Fixtures::equipos(16, $pids);
        $c = new AdminObservable();
        $c->equipos();
        $this->assertSame(16, AdminObservable::$datos['pag']->total);
        $this->assertCount(15, AdminObservable::$datos['equipos']);
        $this->assertStringContainsString('Mostrando 1–15 de 16 equipos', AdminObservable::$html);

        $_GET = ['q' => 'Equipo 16', 'estado' => 'activo'];
        $c = new AdminObservable();
        $c->equipos();
        $this->assertCount(1, AdminObservable::$datos['equipos']);
        $this->assertStringContainsString('Limpiar', AdminObservable::$html);
    }

    // ═══ Cuentas por rol ══════════════════════════════════════════════════

    public function test_las_cuentas_se_listan_solo_del_rol_pedido(): void
    {
        $zoe = Fixtures::usuario('Zoe Organizadora', 'zoe@test.local', 'Clave-1234-ok', 2);
        $m = new UsuarioModel();

        // El seed trae más organizadores que los de Fixtures (por ejemplo uno bloqueado): se compara
        // contra lo que hay en la base, no contra una cantidad fija.
        $hay = (int) $this->db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = 'organizador'")->fetchColumn();
        $filas = $m->buscarPorRol('organizador', []);
        $desc  = $this->ids($filas);
        $this->assertSame($hay, count($filas));
        $this->assertSame($hay, $m->contarPorRol('organizador', []));
        $this->assertTrue(in_array($zoe, $desc, true) && in_array(Fixtures::ORGANIZADOR, $desc, true) && in_array(Fixtures::ORGANIZADOR2, $desc, true));
        foreach ($filas as $f) {
            $this->assertSame('organizador', $f['rol_nombre'], 'ninguna cuenta de otro rol');
        }
        $admins = $this->ids($m->buscarPorRol('administrador', []));
        $this->assertTrue(in_array(Fixtures::ADMIN, $admins, true));
        $this->assertFalse(in_array($zoe, $admins, true), 'los administradores no se mezclan con los organizadores');
        $asc = $desc;
        sort($asc);
        $this->assertSame(array_reverse($asc), $desc, 'del id más nuevo al más viejo');
    }

    public function test_las_cuentas_se_buscan_por_nombre_o_correo_y_por_estado(): void
    {
        $zoe = Fixtures::usuario('Zoe Organizadora', 'zoe@test.local', 'Clave-1234-ok', 2);
        $this->db->exec("UPDATE usuarios SET estado = 'bloqueada' WHERE id = {$zoe}");
        $m = new UsuarioModel();

        $this->assertSame([$zoe], $this->ids($m->buscarPorRol('organizador', ['q' => 'zoe'])));
        $this->assertSame([$zoe], $this->ids($m->buscarPorRol('organizador', ['q' => 'zoe@test'])), 'por correo');
        $bloqueadas = $this->ids($m->buscarPorRol('organizador', ['estado' => 'bloqueada']));
        $this->assertTrue(in_array($zoe, $bloqueadas, true), 'Zoe está bloqueada');
        $this->assertSame($m->contarPorRol('organizador', ['estado' => 'bloqueada']), count($bloqueadas));
        foreach ($m->buscarPorRol('organizador', ['estado' => 'bloqueada']) as $f) {
            $this->assertSame('bloqueada', $f['estado'], 'solo bloqueadas');
        }
        $this->assertSame(0, $m->contarPorRol('administrador', ['q' => 'zoe']), 'buscar no cruza de un rol a otro');
        $this->assertSame(0, $m->contarPorRol('organizador', ['q' => 'zoe', 'estado' => 'activo']));
        foreach ([[], ['q' => 'org'], ['estado' => 'activo']] as $f) {
            $this->assertSame(count($m->buscarPorRol('organizador', $f)), $m->contarPorRol('organizador', $f));
        }
    }

    public function test_el_controlador_de_organizadores_y_el_de_administradores(): void
    {
        $c = new AdminObservable();
        $c->usuarios();
        $organizadores = (int) $this->db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = 'organizador'")->fetchColumn();
        $this->assertSame($organizadores, AdminObservable::$datos['pag']->total, 'los organizadores del seed');
        $this->assertSame(['activo', 'inactivo', 'bloqueada'], array_keys(AdminObservable::$datos['estados']));
        $this->assertStringContainsString('action="/admin/organizadores"', AdminObservable::$html);

        $_GET = ['q' => 'no-existe'];
        $c = new AdminObservable();
        $c->usuarios();
        $this->assertStringContainsString('Ninguna cuenta coincide con la búsqueda.', AdminObservable::$html);

        $_GET = [];
        $c = new AdminObservable();
        $c->administradores();
        $administradores = (int) $this->db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = 'administrador'")->fetchColumn();
        $this->assertSame($administradores, AdminObservable::$datos['pag']->total);
        $this->assertStringContainsString('action="/admin/administradores"', AdminObservable::$html);
        $this->assertStringContainsString('Es tu cuenta', AdminObservable::$html, 'el resto de la pantalla sigue igual (quien mira es ese administrador)');
    }

    // ═══ Auditoría ════════════════════════════════════════════════════════

    private function auditar(string $accion, string $fecha, ?int $usuario = Fixtures::ADMIN, string $descripcion = '', string $tabla = 'torneos'): int
    {
        $st = $this->db->prepare(
            "INSERT INTO auditoria (usuario_id, accion, tabla_afectada, descripcion, created_at)
             VALUES (:u, :a, :t, :d, :f)"
        );
        $st->execute([':u' => $usuario, ':a' => $accion, ':t' => $tabla, ':d' => $descripcion, ':f' => $fecha]);
        return (int) $this->db->lastInsertId();
    }

    public function test_la_auditoria_sale_de_la_mas_nueva_a_la_mas_vieja_y_desempata_por_id(): void
    {
        $a = $this->auditar('crear_torneo', '2026-10-01 10:00:00');
        $b = $this->auditar('crear_torneo', '2026-10-03 10:00:00');
        $c = $this->auditar('crear_torneo', '2026-10-03 10:00:00');          // misma hora que $b
        $d = $this->auditar('crear_torneo', '2026-10-02 10:00:00');

        $ids = $this->ids((new AuditoriaModel())->buscar([]));
        $this->assertSame([$c, $b, $d, $a], $ids, 'los de la misma hora, el último primero');
    }

    public function test_la_auditoria_se_filtra_por_accion_texto_y_rango_de_fechas(): void
    {
        $this->auditar('crear_torneo',    '2026-10-01 23:59:59', Fixtures::ADMIN, 'Torneo creado: Copa');
        $this->auditar('crear_torneo',    '2026-10-02 00:00:00', Fixtures::ADMIN, 'Torneo creado: Liga');
        $dentro2 = $this->auditar('editar_torneo',   '2026-10-03 23:59:59', Fixtures::ORGANIZADOR, 'Torneo editado: Liga', 'torneos');
        $this->auditar('editar_torneo',   '2026-10-04 00:00:00', Fixtures::ADMIN, 'Torneo editado: Otro');
        $sistema = $this->auditar('login', '2026-10-02 12:00:00', null, 'Ingreso', 'usuarios');
        $m = new AuditoriaModel();

        $this->assertSame(3, $m->contarBuscados(['desde' => '2026-10-02', 'hasta' => '2026-10-03']), 'el día «hasta» entra completo, el siguiente no');
        $this->assertSame(4, $m->contarBuscados(['desde' => '2026-10-02']), 'solo «desde»');
        $this->assertSame(4, $m->contarBuscados(['hasta' => '2026-10-03']), 'solo «hasta»');
        $this->assertSame(2, $m->contarBuscados(['accion' => 'editar_torneo']));
        $this->assertSame([$dentro2], $this->ids($m->buscar(['accion' => 'editar_torneo', 'desde' => '2026-10-02', 'hasta' => '2026-10-03'])));
        $this->assertSame([$dentro2], $this->ids($m->buscar(['q' => 'organizador'])), 'por el nombre del usuario');
        $this->assertSame(2, $m->contarBuscados(['q' => 'liga']), 'por la descripción: «creado: Liga» y «editado: Liga»');
        $this->assertSame([$sistema], $this->ids($m->buscar(['q' => 'usuarios'])), 'por la tabla');
        $this->assertSame(0, $m->contarBuscados(['q' => 'zzz']));
        $this->assertSame(0, $m->contarBuscados(['desde' => '2026-11-01']));
    }

    public function test_el_total_de_la_auditoria_coincide_y_las_paginas_cubren_todo(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->auditar('crear_torneo', sprintf('2026-10-%02d 10:%02d:00', 1 + ($i % 9), $i), Fixtures::ADMIN, "Fila $i");
        }
        $m = new AuditoriaModel();
        $todos = $this->ids($m->buscar([]));
        $this->assertSame(23, $m->contarBuscados([]));
        $this->assertSame($todos, array_values(array_unique($todos)));

        $juntas = [];
        $pag = Paginador::de(23, 1, 10);
        for ($n = 1; $n <= $pag->paginas; $n++) {
            $p = Paginador::de(23, $n, 10);
            $juntas = array_merge($juntas, $this->ids($m->buscar([], $p->porPagina, $p->desplazamiento)));
        }
        $this->assertSame($todos, $juntas);
    }

    public function test_las_acciones_para_el_filtro_salen_de_la_propia_auditoria(): void
    {
        $this->auditar('editar_torneo', '2026-10-01 10:00:00');
        $this->auditar('crear_torneo',  '2026-10-02 10:00:00');
        $this->auditar('crear_torneo',  '2026-10-03 10:00:00');
        $this->assertSame(['crear_torneo', 'editar_torneo'], (new AuditoriaModel())->acciones(), 'sin repetir y en orden');
    }

    public function test_el_controlador_de_auditoria_sanea_filtros_y_fechas(): void
    {
        $this->auditar('crear_torneo', '2026-10-02 10:00:00', Fixtures::ADMIN, 'Torneo creado: Copa');
        $_GET = ['accion' => 'inventada', 'desde' => '2026-02-30', 'hasta' => '2026-10-31', 'q' => 'copa'];
        $c = new AdminObservable();
        $c->auditoria();
        $d = AdminObservable::$datos;
        $this->assertSame(['q' => 'copa', 'accion' => '', 'desde' => '', 'hasta' => '2026-10-31'], $d['filtros'], 'la acción inventada y la fecha imposible se descartan');
        $this->assertSame(1, $d['pag']->total);
        $this->assertSame(['crear_torneo' => 'crear_torneo'], $d['acciones']);
        $html = AdminObservable::$html;
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('name="desde"', $html);
        $this->assertStringContainsString('registros con el filtro aplicado', $html);
        $this->assertStringContainsString('Mostrando 1–1 de 1 registros', $html);
    }

    // ═══ Registros pendientes ═════════════════════════════════════════════

    private function pendientes(int $cantidad): void
    {
        $st = $this->db->prepare(
            "INSERT INTO usuarios (rol_id, nombre, email, password_hash, estado, created_at)
             VALUES (3, :n, :e, '!sin-clave-definida', 'pendiente', :f)"
        );
        for ($i = 1; $i <= $cantidad; $i++) {
            $st->execute([':n' => sprintf('Pendiente %02d', $i), ':e' => sprintf('pendiente%02d@test.local', $i), ':f' => sprintf('2026-10-%02d 10:00:00', $i)]);
        }
    }

    public function test_los_registros_pendientes_se_buscan_y_se_atienden_en_orden_de_llegada(): void
    {
        $base = (int) $this->db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = 'participante' AND u.estado = 'pendiente'")->fetchColumn();
        $this->pendientes(17);
        $m = new UsuarioModel();

        $this->assertSame($base + 17, $m->contarParticipantesPendientes([]));
        $todos = $this->ids($m->buscarParticipantesPendientes([]));
        $this->assertCount($base + 17, $todos);
        $fechas = array_column($m->buscarParticipantesPendientes([]), 'created_at');
        $orden = $fechas;
        sort($orden);
        $this->assertSame($orden, $fechas, 'del más antiguo al más nuevo');

        $this->assertSame(1, $m->contarParticipantesPendientes(['q' => 'pendiente17@test']), 'por correo');
        $this->assertSame(1, $m->contarParticipantesPendientes(['q' => 'Pendiente 05']), 'por nombre');
        $this->assertSame(17, $m->contarParticipantesPendientes(['q' => 'test.local']));
        $this->assertSame(0, $m->contarParticipantesPendientes(['q' => 'zzz']));
        foreach ($m->buscarParticipantesPendientes([]) as $f) {
            $this->assertSame('pendiente', $f['estado'], 'solo pendientes');
        }

        $juntas = [];
        $total = $m->contarParticipantesPendientes([]);
        for ($n = 1; $n <= Paginador::de($total, 1, 6)->paginas; $n++) {
            $p = Paginador::de($total, $n, 6);
            $juntas = array_merge($juntas, $this->ids($m->buscarParticipantesPendientes([], $p->porPagina, $p->desplazamiento)));
        }
        $this->assertSame($todos, $juntas, 'las páginas cubren todo sin repetir');
    }

    public function test_el_controlador_de_registros_pagina_y_conserva_la_busqueda(): void
    {
        $this->pendientes(17);
        $c = new AdminObservable();
        $c->registros();
        $this->assertCount(15, AdminObservable::$datos['pendientes']);
        $this->assertStringContainsString('registros', AdminObservable::$html);
        $this->assertStringContainsString('Aprobar', AdminObservable::$html, 'las acciones siguen en cada fila');

        $_GET = ['q' => 'pendiente1', 'page' => '2'];
        $c = new AdminObservable();
        $c->registros();
        $this->assertStringContainsString('action="/admin/registros"', AdminObservable::$html);
        $this->assertStringContainsString('value="pendiente1"', AdminObservable::$html);

        $_GET = ['q' => 'zzz'];
        $c = new AdminObservable();
        $c->registros();
        $this->assertStringContainsString('Ningún registro coincide con la búsqueda.', AdminObservable::$html);
    }

    // ═══ Solicitudes de corrección ════════════════════════════════════════

    /** Una liga jugada y tres solicitudes: una pendiente, una rechazada y una aprobada. @return array{0:int,1:string} */
    private function solicitudes(): array
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4, ['nombre' => 'Liga De Las Correcciones', 'permite_empates' => '1']);
        Fixtures::jugarTodo($tid);
        $partidos = $this->db->query("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $svc = new CorreccionService();
        foreach ($partidos as $i => $eid) {
            $svc->solicitar((int) $eid, 5.0 + $i, 0.0, 'Error de planilla número ' . $i, Fixtures::ORGANIZADOR);
        }
        $ids = $this->db->query("SELECT id FROM solicitudes_correccion ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $svc->rechazar((int) $ids[0], Fixtures::ADMIN, 'No corresponde al acta');
        $svc->aprobar((int) $ids[1], Fixtures::ADMIN);
        return [$tid, 'Liga De Las Correcciones'];
    }

    public function test_las_solicitudes_se_filtran_por_estado_y_texto_con_las_pendientes_primero(): void
    {
        $this->solicitudes();
        $m = new SolicitudCorreccionModel();

        $this->assertSame(3, $m->contarBuscadas([]));
        $filas = $m->buscarConDetalle([]);
        $this->assertSame('pendiente', $filas[0]['estado'], 'las pendientes primero: son las que esperan una decisión');
        $this->assertSame(1, $m->contarBuscadas(['estado' => 'pendiente']));
        $this->assertSame(1, $m->contarBuscadas(['estado' => 'aprobada']));
        $this->assertSame(1, $m->contarBuscadas(['estado' => 'rechazada']));
        $this->assertSame(3, $m->contarBuscadas(['q' => 'correcciones']), 'por el nombre del torneo');
        $this->assertSame(3, $m->contarBuscadas(['q' => 'carlos']), 'por el solicitante');
        $this->assertSame(0, $m->contarBuscadas(['q' => 'zzz']));
        $this->assertSame(0, $m->contarBuscadas(['q' => 'correcciones', 'estado' => 'inventado-no-llega-aca-pero-no-rompe']));
        foreach ([[], ['estado' => 'pendiente'], ['q' => 'liga']] as $f) {
            $this->assertSame(count($m->buscarConDetalle($f)), $m->contarBuscadas($f));
        }
        $this->assertSame(['pendiente'], array_unique(array_column($m->buscarConDetalle(['estado' => 'pendiente']), 'estado')));
    }

    public function test_un_organizador_no_ve_las_solicitudes_de_torneos_ajenos(): void
    {
        $this->solicitudes();
        $m = new SolicitudCorreccionModel();
        $this->assertSame(3, $m->contarBuscadas([], Fixtures::ORGANIZADOR), 'las del torneo que organiza');
        $this->assertSame(0, $m->contarBuscadas([], Fixtures::ORGANIZADOR2), 'ninguna de un torneo ajeno');
        $this->assertSame([], $m->buscarConDetalle([], null, 0, Fixtures::ORGANIZADOR2));
    }

    public function test_las_paginas_de_solicitudes_cubren_todo_sin_repetir(): void
    {
        $this->solicitudes();
        $m = new SolicitudCorreccionModel();
        $todos = $this->ids($m->buscarConDetalle([]));
        $juntas = [];
        for ($n = 1; $n <= 3; $n++) {
            $p = Paginador::de(3, $n, 1);
            $juntas = array_merge($juntas, $this->ids($m->buscarConDetalle([], $p->porPagina, $p->desplazamiento)));
        }
        $this->assertSame($todos, $juntas);
    }

    public function test_el_controlador_de_correcciones_sanea_filtros_y_dibuja_la_bandeja(): void
    {
        $this->solicitudes();
        $_GET = ['estado' => 'pendiente', 'q' => ' liga '];
        $c = new CorreccionObservable();
        $c->index();
        $d = CorreccionObservable::$datos;
        $this->assertSame(['q' => 'liga', 'estado' => 'pendiente'], $d['filtros']);
        $this->assertCount(1, $d['solicitudes']);
        $this->assertStringContainsString('Mostrando 1–1 de 1 solicitudes', CorreccionObservable::$html);
        $this->assertStringContainsString('Aprobar', CorreccionObservable::$html);

        $_GET = ['estado' => 'inventado'];
        $c = new CorreccionObservable();
        $c->index();
        $this->assertSame('', CorreccionObservable::$datos['filtros']['estado'], 'un estado inventado se descarta');
        $this->assertSame(3, CorreccionObservable::$datos['pag']->total);

        $_GET = ['q' => 'zzz'];
        $c = new CorreccionObservable();
        $c->index();
        $this->assertStringContainsString('Ninguna solicitud coincide con la búsqueda.', CorreccionObservable::$html);
    }

    // ═══ Mis torneos: organizador y participante ══════════════════════════

    public function test_mis_torneos_del_organizador_pagina_filtra_y_no_abre_los_de_otro(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->torneo(sprintf('Mio %02d', $i), 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR]);
        }
        $this->torneo('De otro', 'liga', 'inscripcion', ['organizador_id' => Fixtures::ORGANIZADOR2]);

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $c = new OrganizadorObservable();
        $c->misTorneos();
        $d = OrganizadorObservable::$datos;
        $this->assertSame(16, $d['pag']->total, 'solo los suyos');
        $this->assertCount(15, $d['torneos']);
        $this->assertStringContainsString('Mostrando 1–15 de 16 torneos', OrganizadorObservable::$html);
        $this->assertStringContainsString('action="/organizador/torneos"', OrganizadorObservable::$html);
        $this->assertStringContainsString('/reporte"', OrganizadorObservable::$html, 'el botón Reporte sigue');

        $_GET = ['q' => 'otro'];
        $c = new OrganizadorObservable();
        $c->misTorneos();
        $this->assertSame(0, OrganizadorObservable::$datos['pag']->total, 'buscar el nombre del torneo de otro no lo muestra');
        $this->assertStringContainsString('Ningún torneo coincide con la búsqueda.', OrganizadorObservable::$html);

        $_GET = ['q' => 'mio 16', 'estado' => 'inscripcion', 'tipo' => 'inventado'];
        $c = new OrganizadorObservable();
        $c->misTorneos();
        $this->assertSame(1, OrganizadorObservable::$datos['pag']->total);
        $this->assertSame('', OrganizadorObservable::$datos['filtros']['tipo']);

        Fixtures::loguearComo(Fixtures::ADMIN);
        $_GET = [];
        $c = new OrganizadorObservable();
        $c->misTorneos();
        $this->assertSame(17, OrganizadorObservable::$datos['pag']->total, 'el administrador ve todos');
    }

    /** Un participante con cuenta (la 4 del seed) inscrito en $cantidad torneos. @return int su id de participante */
    private function participanteInscrito(int $cantidad): int
    {
        $pid = (new ParticipanteModel())->insert([
            'usuario_id' => 4, 'nombre' => 'Matias Prueba', 'nick' => 'matiasp', 'estado' => 'activo',
        ]);
        for ($i = 1; $i <= $cantidad; $i++) {
            Fixtures::inscribir($this->torneo(sprintf('Mi Copa %02d', $i)), [$pid]);
        }
        return $pid;
    }

    public function test_mis_torneos_del_participante_pagina_y_busca_solo_los_suyos(): void
    {
        $this->participanteInscrito(16);
        $otro = Fixtures::participantes(1, 'Otro')[0];
        Fixtures::inscribir($this->torneo('Torneo De Otro'), [$otro]);

        Fixtures::loguearComo(4);
        $c = new ParticipanteObservable();
        $c->misTorneos();
        $d = ParticipanteObservable::$datos;
        $this->assertSame(16, $d['pag']->total, 'solo sus inscripciones');
        $this->assertCount(15, $d['torneos']);
        $this->assertStringContainsString('Mostrando 1–15 de 16 torneos', ParticipanteObservable::$html);
        $this->assertStringContainsString('action="/participante/torneos"', ParticipanteObservable::$html);

        $_GET = ['page' => '2'];
        $c = new ParticipanteObservable();
        $c->misTorneos();
        $this->assertCount(1, ParticipanteObservable::$datos['torneos']);

        $_GET = ['q' => 'otro'];
        $c = new ParticipanteObservable();
        $c->misTorneos();
        $this->assertSame(0, ParticipanteObservable::$datos['pag']->total, 'no encuentra el torneo de otro participante');

        $_GET = ['estado' => 'en_curso'];
        $c = new ParticipanteObservable();
        $c->misTorneos();
        $this->assertSame(0, ParticipanteObservable::$datos['pag']->total, 'por el estado del torneo: ninguno en curso');
        $this->assertStringContainsString('Ningún torneo coincide con la búsqueda.', ParticipanteObservable::$html);
    }

    public function test_sin_perfil_de_participante_mis_torneos_queda_vacio_sin_error(): void
    {
        Fixtures::loguearComo(4);            // la cuenta existe pero el test no le creó perfil
        $c = new ParticipanteObservable();
        $c->misTorneos();
        $this->assertSame(0, ParticipanteObservable::$datos['pag']->total);
        $this->assertStringContainsString('No estás inscrito en ningún torneo.', ParticipanteObservable::$html);
    }

    public function test_la_auditoria_pagina_de_a_cincuenta(): void
    {
        for ($i = 0; $i < 52; $i++) {
            $this->auditar('crear_torneo', sprintf('2026-09-%02d 10:00:00', 1 + ($i % 28)), Fixtures::ADMIN, "Fila $i");
        }
        $c = new AdminObservable();
        $c->auditoria();
        $this->assertCount(50, AdminObservable::$datos['registros']);
        $this->assertStringContainsString('Mostrando 1–50 de 52 registros', AdminObservable::$html);
    }
}

TestCase::correrSiEsScript(ListadosTest::class);
