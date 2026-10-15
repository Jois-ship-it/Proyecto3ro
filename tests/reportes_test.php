<?php
declare(strict_types=1);

/**
 * Test de los reportes (G1 del plan; letra §5.1 «acceder a reportes» y §5.2 «consultar reportes
 * del torneo») y de su exportación a CSV.
 *
 * Qué pasaba antes: no existía ninguna pantalla de reportes ni de exportación; el administrador
 * tenía cuatro contadores en el panel y el organizador no tenía nada para llevar a una reunión.
 *
 * Qué se comprueba:
 *   - Las cifras: que el reporte de un torneo coincida con su tabla de posiciones y con sus
 *     partidos, y que el reporte general coincida con la suma de los de cada torneo.
 *   - Los casos de borde: un empate (se reconoce por los puntos, no por la columna «ganador»), un
 *     bye (no es un partido), un torneo sin generar (nada que dividir por cero) y un bracket.
 *   - El CSV: formato, filtros, y que un nombre como «=HYPERLINK(...)» no llegue a la planilla
 *     como una fórmula.
 *   - Quién entra: el general solo el administrador; el de un torneo, el administrador y el
 *     organizador dueño; nadie más. Cada exportación queda en la auditoría.
 *   - Que lo escrito por usuarios se dibuje escapado.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/reportes_test.php
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

/** El controlador real, con la salida (vista, redirección y descarga) a la vista del test. */
final class ReporteObservable extends ReporteController
{
    public static string $vista = '';
    public static array  $datos = [];
    public static string $html  = '';
    /** @var array{archivo:string,contenido:string}|null */
    public static ?array $csv   = null;

    public static function limpiar(): void
    {
        self::$vista = '';
        self::$datos = [];
        self::$html  = '';
        self::$csv   = null;
    }

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        self::$vista = $view;
        self::$datos = $data;
        ob_start();
        try {
            parent::render($view, $data, '');
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never
    {
        throw new RedirigidoA($url);
    }

    protected function descargarCsv(string $archivo, array $encabezados, array $filas): void
    {
        self::$csv = ['archivo' => $archivo, 'contenido' => Csv::generar($encabezados, $filas)];
    }
}

class ReportesTest extends TestCase
{
    private const PARTICIPANTE_USUARIO = 4;       // una cuenta de participante del seed

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_GET = [];
        ReporteObservable::limpiar();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $this->db->exec("UPDATE permisos SET puede_ver = 1 WHERE rol_id = 2 AND modulo_slug = 'torneos'");
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    private function servicio(): ReporteService
    {
        return new ReporteService();
    }

    /** Una liga de cuatro, jugada entera, con dos empates. @return array{0:int,1:int[]} */
    private function ligaJugada(array $op = []): array
    {
        [$tid, $pids] = Fixtures::torneoArrancado('liga', 4, $op + ['permite_empates' => '1']);
        Fixtures::jugarTodo($tid, fn(int $eid, int $i) => match ($i % 3) {
            0 => [2.0, 1.0],
            1 => [1.0, 1.0],     // empate
            default => [0.0, 3.0],
        });
        return [$tid, $pids];
    }

    /** Un suizo de cinco con la primera ronda jugada: dos partidos y un bye. */
    private function suizoConBye(): int
    {
        [$tid] = Fixtures::torneoArrancado('suizo', 5);
        $svc = new ResultadoService();
        foreach (Fixtures::partidosPendientes($tid, 1) as $eid) {
            $svc->cargar($eid, 3.0, 1.0, Fixtures::ADMIN);
        }
        return $tid;
    }

    private function controlador(): ReporteObservable
    {
        ReporteObservable::limpiar();
        return new ReporteObservable();
    }

    /** Lo que lanza (o null) una acción del controlador. */
    private function intentar(callable $accion): ?Throwable
    {
        try {
            $accion();
            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** @return string[][] las filas de un CSV, ya sin el BOM */
    private function parsear(string $csv): array
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($h);
        $filas = [];
        while (($f = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $filas[] = $f;
        }
        fclose($h);
        return $filas;
    }

    private function exportaciones(): array
    {
        return $this->db->query("SELECT * FROM auditoria WHERE accion = 'exportar_reporte' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ═══ Las cifras de un torneo ══════════════════════════════════════════

    public function test_el_reporte_de_una_liga_coincide_con_su_tabla_y_sus_partidos(): void
    {
        [$tid] = $this->ligaJugada();
        $r = $this->servicio()->torneo($tid);

        $this->assertNotNull($r);
        $res = $r['resumen'];
        $this->assertSame(4, $res['inscritos']);
        $this->assertSame(6, $res['partidos_total'], 'cuatro jugadores, todos contra todos: seis partidos');
        $this->assertSame(6, $res['partidos_jugados']);
        $this->assertSame(0, $res['partidos_pendientes']);
        $this->assertSame(0, $res['byes']);

        $tabla = $r['tabla'];
        $this->assertCount(4, $tabla);
        $suma = fn(string $col) => array_sum(array_map(fn($f) => (int) $f[$col], $tabla));
        $this->assertSame(2 * $res['partidos_jugados'], $suma('pj'), 'cada partido lo juegan dos: la suma de PJ es el doble');
        $this->assertSame($suma('pg'), $suma('pp'), 'cada victoria es una derrota de otro');
        $this->assertSame(0, $suma('pe') % 2, 'los empates vienen de a dos');
        $this->assertSame($suma('pf'), $suma('pc'), 'los puntos a favor de unos son en contra de otros');
        $this->assertSame((float) $suma('pf'), $res['puntos_total'], 'y los puntos anotados son los puntos a favor');
        $this->assertSame($res['puntos_total'] / 6, $res['promedio']);
        $this->assertSame(1, (int) $tabla[0]['posicion'], 'de la primera a la última');
    }

    public function test_el_campeon_del_reporte_es_el_primero_de_la_tabla_de_una_liga_recien_terminada(): void
    {
        [$tid] = $this->ligaJugada();
        $r = $this->servicio()->torneo($tid);
        $this->assertSame('finalizado', $r['torneo']['estado']);
        $this->assertSame($r['tabla'][0]['competidor'], $r['campeon']);
    }

    public function test_un_empate_se_reconoce_por_los_puntos_y_no_por_la_columna_ganador(): void
    {
        [$tid] = $this->ligaJugada();
        $partidos = $this->servicio()->torneo($tid)['partidos'];

        $empates = array_values(array_filter($partidos, fn($p) => $p['es_empate']));
        $this->assertCount(2, $empates);
        foreach ($empates as $p) {
            $this->assertSame('Empate', $p['ganador_texto']);
            $this->assertSame((float) $p['puntos_a'], (float) $p['puntos_b']);
            // (Se mira por los puntos y no por la columna «ganador»: una base vieja guardaba ahí al lado A.)
        }
        foreach (array_filter($partidos, fn($p) => !$p['es_empate']) as $p) {
            $this->assertNotSame('Empate', $p['ganador_texto']);
            $esperado = (float) $p['puntos_a'] > (float) $p['puntos_b'] ? $p['lado_a'] : $p['lado_b'];
            $this->assertSame($esperado, $p['ganador_texto'], 'gana quien hizo más puntos');
        }
    }

    public function test_un_bye_no_cuenta_como_partido(): void
    {
        $tid = $this->suizoConBye();
        $r = $this->servicio()->torneo($tid);
        $res = $r['resumen'];

        $this->assertSame(5, $res['inscritos']);
        $this->assertSame(1, $res['byes']);
        $this->assertSame(2, $res['partidos_total'], 'cinco jugadores: dos partidos y uno que pasa de ronda sin jugar');
        $this->assertSame(2, $res['partidos_jugados']);

        $bye = array_values(array_filter($r['partidos'], fn($p) => (int) $p['es_bye'] === 1));
        $this->assertCount(1, $bye);
        $this->assertSame($bye[0]['lado_a'], $bye[0]['ganador_texto'], 'en un bye «gana» quien pasa');
        $this->assertFalse($bye[0]['es_empate']);
        $this->assertCount(5, $r['tabla'], 'el suizo tiene tabla');
    }

    public function test_con_partidos_pendientes_el_avance_se_calcula_sobre_los_que_se_juegan(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $svc = new ResultadoService();
        foreach (array_slice(Fixtures::partidosPendientes($tid), 0, 2) as $eid) {
            $svc->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }
        $res = $this->servicio()->torneo($tid)['resumen'];
        $this->assertSame([6, 2, 4], [$res['partidos_total'], $res['partidos_jugados'], $res['partidos_pendientes']]);
        $this->assertSame(6.0, $res['puntos_total'], 'dos partidos de 2–1');
        $this->assertSame(3.0, $res['promedio']);
        $this->assertSame(null, $this->servicio()->torneo($tid)['campeon'], 'todavía nadie campeón');
    }

    public function test_un_torneo_sin_generar_no_rompe_ni_divide_por_cero(): void
    {
        $tid = Fixtures::torneo('liga');
        Fixtures::inscribir($tid, Fixtures::participantes(3));
        $r = $this->servicio()->torneo($tid);

        $this->assertSame(3, $r['resumen']['inscritos']);
        $this->assertSame([0, 0, 0], [$r['resumen']['partidos_total'], $r['resumen']['partidos_jugados'], $r['resumen']['partidos_pendientes']]);
        $this->assertSame(0.0, $r['resumen']['promedio']);
        $this->assertSame([], $r['partidos']);
        $this->assertSame([], $r['tabla']);
        $this->assertSame(null, $r['campeon']);

        $_GET = [];
        $this->controlador()->torneo((string) $tid);
        $this->assertStringContainsString('Todavía no se generó la competencia.', ReporteObservable::$html);
        $this->assertStringContainsString('En disputa', ReporteObservable::$html);
    }

    public function test_un_bracket_no_tiene_tabla_de_posiciones_pero_si_partidos_y_campeon(): void
    {
        [$tid] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($tid);
        $r = $this->servicio()->torneo($tid);
        $this->assertSame([], $r['tabla'], 'el bracket no tiene tabla de posiciones');
        $this->assertSame(3, $r['resumen']['partidos_total'], 'dos semifinales y la final');
        $this->assertSame(3, $r['resumen']['partidos_jugados']);
        $this->assertNotNull($r['campeon']);

        $this->controlador()->torneo((string) $tid);
        $this->assertStringNotContainsString('Tabla de posiciones', ReporteObservable::$html);
        $this->assertStringContainsString('Semifinales', ReporteObservable::$html);
    }

    public function test_un_torneo_que_no_existe_devuelve_null(): void
    {
        $this->assertSame(null, $this->servicio()->torneo(999999));
    }

    // ═══ El reporte general ═══════════════════════════════════════════════

    public function test_el_reporte_general_es_la_suma_de_los_de_cada_torneo(): void
    {
        $this->ligaJugada();
        $this->suizoConBye();
        [$bk] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($bk);
        Fixtures::torneo('liga', ['nombre' => 'Borrador vacio', 'estado' => 'borrador']);

        $s = $this->servicio();
        $g = $s->general();
        $filas = $s->torneos([], 100, 0);

        $this->assertSame($s->contarTorneos([]), count($filas));
        $this->assertSame((int) $g['resumen']['torneos_total'], count($filas));
        $this->assertSame(array_sum(array_map(fn($f) => (int) $f['partidos_jugados'], $filas)), $g['resumen']['partidos_jugados'],
            'los partidos jugados del general son los de todos los torneos juntos');
        foreach ($filas as $f) {
            $individual = $s->torneo((int) $f['id'])['resumen'];
            $this->assertSame($individual['partidos_total'], (int) $f['partidos_total'], 'torneo ' . $f['id']);
            $this->assertSame($individual['partidos_jugados'], (int) $f['partidos_jugados']);
            $this->assertSame($individual['inscritos'], (int) $f['inscritos']);
        }
    }

    public function test_el_general_cuenta_los_formatos_las_correcciones_y_las_cuentas(): void
    {
        $this->ligaJugada();
        $this->suizoConBye();
        $g = $this->servicio()->general();

        $this->assertSame(['Liga', 'Eliminación Directa', 'Sistema Suizo'], array_keys($g['porFormato']), 'los tres formatos, en su orden, aunque alguno no tenga torneos');
        $this->assertSame((int) $g['resumen']['torneos_total'], array_sum($g['porFormato']));
        $this->assertSame(1, $g['porFormato']['Liga']);
        $this->assertSame(1, $g['porFormato']['Sistema Suizo']);
        $this->assertSame(0, $g['porFormato']['Eliminación Directa']);

        $this->assertSame(['pendiente', 'aprobada', 'rechazada'], array_keys($g['correcciones']), 'siempre los tres estados');
        $this->assertSame([0, 0, 0], array_values($g['correcciones']));

        $this->assertSame(['Administradores', 'Organizadores', 'Participantes', 'Pendientes de aprobación', 'Bloqueadas'], array_keys($g['cuentas']));
        $this->assertSame((int) $this->db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = 'organizador' AND u.estado = 'activo'")->fetchColumn(), $g['cuentas']['Organizadores']);
        $this->assertSame((int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'bloqueada'")->fetchColumn(), $g['cuentas']['Bloqueadas']);
        $this->assertSame((int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'pendiente'")->fetchColumn(), $g['cuentas']['Pendientes de aprobación']);
    }

    public function test_las_correcciones_se_cuentan_por_estado(): void
    {
        [$tid] = $this->ligaJugada();
        $partidos = $this->db->query("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $svc = new CorreccionService();
        $svc->solicitar((int) $partidos[0], 5.0, 0.0, 'Error de planilla', Fixtures::ORGANIZADOR);
        $svc->solicitar((int) $partidos[1], 4.0, 0.0, 'Otro error', Fixtures::ORGANIZADOR);
        $primera = (int) $this->db->query("SELECT MIN(id) FROM solicitudes_correccion")->fetchColumn();
        $svc->rechazar($primera, Fixtures::ADMIN, 'No corresponde');

        $c = $this->servicio()->general()['correcciones'];
        $this->assertSame([1, 0, 1], [$c['pendiente'], $c['aprobada'], $c['rechazada']]);
    }

    public function test_la_tabla_del_general_se_filtra_y_cada_fila_trae_su_campeon(): void
    {
        [$liga] = $this->ligaJugada(['nombre' => 'Liga Del Norte']);
        $suizo  = $this->suizoConBye();
        $s = $this->servicio();

        $f = $s->torneos(['q' => 'norte'], 15, 0);
        $this->assertSame([$liga], array_map(fn($x) => (int) $x['id'], $f));
        $this->assertNotNull($f[0]['campeon'], 'el finalizado trae a su campeón');

        $this->assertSame(1, $s->contarTorneos(['tipo' => 'suizo']));
        $this->assertSame($suizo, (int) $s->torneos(['tipo' => 'suizo'], 15, 0)[0]['id']);
        $this->assertSame(null, $s->torneos(['tipo' => 'suizo'], 15, 0)[0]['campeon'], 'el que sigue en juego, no');
        $this->assertSame(1, $s->contarTorneos(['estado' => 'finalizado']));
        $this->assertSame(0, $s->contarTorneos(['q' => 'zzz']));
    }

    // ═══ CSV ══════════════════════════════════════════════════════════════

    public function test_el_csv_de_posiciones_tiene_encabezado_y_una_fila_por_competidor(): void
    {
        [$tid] = $this->ligaJugada();
        $this->controlador()->posicionesCsv((string) $tid);
        $csv = ReporteObservable::$csv;

        $this->assertNotNull($csv);
        $this->assertTrue(str_starts_with($csv['contenido'], "\xEF\xBB\xBF"), 'con BOM');
        $this->assertSame(1, preg_match('/^torneo-' . $tid . '-posiciones-\d{8}\.csv$/', $csv['archivo']), $csv['archivo']);
        $filas = $this->parsear($csv['contenido']);
        $this->assertSame(['Posición', 'Competidor', 'PJ', 'PG', 'PE', 'PP', 'PF', 'PC', 'Dif', 'Puntos'], $filas[0]);
        $this->assertCount(5, $filas, 'el encabezado y los cuatro competidores');
        $this->assertSame('1', $filas[1][0]);
        $tabla = $this->servicio()->torneo($tid)['tabla'];
        foreach ($tabla as $i => $f) {
            $this->assertSame($f['competidor'], $filas[$i + 1][1]);
            $this->assertSame((string) (int) $f['puntos'], $filas[$i + 1][9], 'lo que se ve en pantalla es lo que se exporta');
        }
    }

    public function test_el_csv_de_partidos_muestra_empates_byes_y_decimales_con_coma(): void
    {
        $tid = $this->suizoConBye();
        $this->controlador()->partidosCsv((string) $tid);
        $filas = $this->parsear(ReporteObservable::$csv['contenido']);

        $this->assertSame(['Ronda', 'Local', 'Visitante', 'Puntos local', 'Puntos visitante', 'Ganador', 'Estado', 'Fecha programada'], $filas[0]);
        $this->assertCount(4, $filas, 'encabezado, dos partidos y el bye');
        $bye = array_values(array_filter($filas, fn($f) => ($f[2] ?? '') === 'Bye'));
        $this->assertCount(1, $bye);
        $this->assertSame('', $bye[0][3], 'un bye no tiene puntos');
        $this->assertSame($bye[0][1], $bye[0][5], 'y gana quien pasa');
        $jugado = array_values(array_filter($filas, fn($f) => ($f[3] ?? '') === '3'));
        $this->assertCount(2, $jugado, 'los dos partidos terminaron 3–1');
        $this->assertSame('1', $jugado[0][4]);

        // Decimales: un 2,5 sale con coma (la planilla en español lo entiende como número).
        [$t2] = Fixtures::torneoArrancado('liga', 3);
        $eid = Fixtures::partidosPendientes($t2)[0];
        (new ResultadoService())->cargar($eid, 2.5, 1.0, Fixtures::ADMIN);
        $this->controlador()->partidosCsv((string) $t2);
        $this->assertStringContainsString(';2,5;1;', ReporteObservable::$csv['contenido']);
    }

    public function test_el_csv_de_inscritos_no_lleva_datos_de_contacto(): void
    {
        $tid = Fixtures::torneo('liga');
        $pid = (new ParticipanteModel())->insert([
            'nombre' => 'Ana Reservada', 'nick' => 'ana', 'documento' => '9.999.999-9',
            'email' => 'secreto@test.local', 'telefono' => '099123456', 'estado' => 'activo',
        ]);
        Fixtures::inscribir($tid, [$pid]);

        $this->controlador()->inscritosCsv((string) $tid);
        $contenido = ReporteObservable::$csv['contenido'];
        $filas = $this->parsear($contenido);
        $this->assertSame(['Competidor', 'Tipo', 'Inscripción', 'Fecha de inscripción'], $filas[0]);
        $this->assertSame('Ana Reservada', $filas[1][0]);
        $this->assertSame(['Participante', 'Activa'], [$filas[1][1], $filas[1][2]]);
        foreach (['secreto@test.local', '099123456', '9.999.999-9'] as $dato) {
            $this->assertStringNotContainsString($dato, $contenido, 'el reporte no es una fuga de datos personales');
        }
    }

    public function test_un_nombre_con_formula_no_llega_a_la_planilla_como_formula(): void
    {
        $nombres = ['=HYPERLINK("http://x","clic")', '+cmd|calc', '@SUM(1+1)', '-2+3', 'Con;punto y coma', 'Con "comillas"'];
        $m = new ParticipanteModel();
        $pids = [];
        foreach ($nombres as $i => $n) {
            $pids[] = $m->insert(['nombre' => $n, 'nick' => 'x' . $i, 'estado' => 'activo']);
        }
        $tid = Fixtures::torneo('liga');
        Fixtures::inscribir($tid, $pids);
        (new LigaService())->generarFixture($tid);
        Fixtures::jugarTodo($tid);

        foreach (['posicionesCsv', 'partidosCsv', 'inscritosCsv'] as $accion) {
            $this->controlador()->{$accion}((string) $tid);
            $contenido = ReporteObservable::$csv['contenido'];
            foreach ($this->parsear($contenido) as $fila) {
                foreach ($fila as $celda) {
                    $esNumero = (bool) preg_match('/^[+-]?\d+([.,]\d+)?$/', $celda);
                    $this->assertFalse(!$esNumero && (bool) preg_match('/^[=+\-@\t\r]/', $celda), "{$accion}: una celda quedó como fórmula: " . $celda);
                }
            }
            $this->assertStringContainsString('\'=HYPERLINK', $contenido, "{$accion}: la fórmula quedó como texto");
            $this->assertStringContainsString('"Con;punto y coma"', $contenido, 'y el separador dentro de un nombre va entre comillas');
            $this->assertStringContainsString('"Con ""comillas"""', $contenido);
        }
    }

    public function test_el_csv_general_trae_todos_los_torneos_que_cumplen_el_filtro_no_solo_la_pagina(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            Fixtures::torneo('liga', ['nombre' => sprintf('Copa %02d', $i)]);
        }
        Fixtures::torneo('liga', ['nombre' => 'Liga Aparte']);

        $_GET = ['q' => 'copa'];
        $this->controlador()->generalCsv();
        $filas = $this->parsear(ReporteObservable::$csv['contenido']);
        $this->assertSame(['ID', 'Torneo', 'Formato', 'Modalidad', 'Estado', 'Inicio', 'Fin', 'Inscritos', 'Partidos jugados', 'Partidos', 'Campeón'], $filas[0]);
        $this->assertCount(18, $filas, 'el encabezado y los 17 «Copa», más allá de los 15 de una página');
        $this->assertSame(1, preg_match('/^torneos-\d{8}\.csv$/', ReporteObservable::$csv['archivo']));

        $_GET = [];
        $this->controlador()->generalCsv();
        $this->assertCount(19, $this->parsear(ReporteObservable::$csv['contenido']), 'sin filtro, los 18');
    }

    public function test_cada_exportacion_queda_en_la_auditoria(): void
    {
        [$tid] = $this->ligaJugada();
        $this->db->exec("DELETE FROM auditoria");

        $this->controlador()->posicionesCsv((string) $tid);
        $this->controlador()->partidosCsv((string) $tid);
        $this->controlador()->inscritosCsv((string) $tid);
        $this->controlador()->generalCsv();

        $filas = $this->exportaciones();
        $this->assertCount(4, $filas);
        $this->assertSame(Fixtures::ADMIN, (int) $filas[0]['usuario_id'], 'con quién exportó');
        $this->assertSame($tid, (int) $filas[0]['registro_id']);
        $this->assertStringContainsString('posiciones', (string) $filas[0]['descripcion']);
        $this->assertStringContainsString('4 filas', (string) $filas[0]['descripcion'], 'y cuántas filas salieron');
        $this->assertStringContainsString('partidos', (string) $filas[1]['descripcion']);
        $this->assertStringContainsString('inscritos', (string) $filas[2]['descripcion']);
        $this->assertStringContainsString('general', (string) $filas[3]['descripcion']);
    }

    // ═══ Quién entra ══════════════════════════════════════════════════════

    /** Las seis acciones del controlador, con un torneo que existe. @return array<string,callable> */
    private function acciones(int $tid): array
    {
        $id = (string) $tid;
        return [
            'general'       => fn() => $this->controlador()->general(),
            'generalCsv'    => fn() => $this->controlador()->generalCsv(),
            'torneo'        => fn() => $this->controlador()->torneo($id),
            'posicionesCsv' => fn() => $this->controlador()->posicionesCsv($id),
            'partidosCsv'   => fn() => $this->controlador()->partidosCsv($id),
            'inscritosCsv'  => fn() => $this->controlador()->inscritosCsv($id),
        ];
    }

    public function test_sin_sesion_ninguna_accion_se_abre(): void
    {
        [$tid] = $this->ligaJugada();
        Fixtures::cerrarSesion();
        foreach ($this->acciones($tid) as $nombre => $accion) {
            $e = $this->intentar($accion);
            $this->assertTrue($e instanceof SesionRequerida, "$nombre pide iniciar sesión");
        }
        $this->assertSame(null, ReporteObservable::$csv, 'y no salió ningún archivo');
        $this->assertCount(0, $this->exportaciones());
    }

    public function test_un_participante_no_entra_a_ningun_reporte(): void
    {
        [$tid] = $this->ligaJugada();
        Fixtures::loguearComo(self::PARTICIPANTE_USUARIO);
        foreach ($this->acciones($tid) as $nombre => $accion) {
            $e = $this->intentar($accion);
            $this->assertTrue($e instanceof AccesoDenegado, "$nombre es un 403 para un participante");
        }
        $this->assertSame(null, ReporteObservable::$csv);
        $this->assertCount(0, $this->exportaciones());
    }

    public function test_el_reporte_general_es_solo_del_administrador(): void
    {
        $this->ligaJugada();
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertTrue($this->intentar(fn() => $this->controlador()->general()) instanceof AccesoDenegado, 'ni el organizador');
        $this->assertTrue($this->intentar(fn() => $this->controlador()->generalCsv()) instanceof AccesoDenegado);
        $this->assertCount(0, $this->exportaciones());

        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->assertSame(null, $this->intentar(fn() => $this->controlador()->general()));
        $this->assertSame('admin/reportes', ReporteObservable::$vista);
    }

    public function test_el_organizador_ve_el_reporte_de_su_torneo_y_exporta_sus_csv(): void
    {
        [$tid] = $this->ligaJugada(['organizador_id' => Fixtures::ORGANIZADOR]);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->db->exec("DELETE FROM auditoria");

        $this->assertSame(null, $this->intentar(fn() => $this->controlador()->torneo((string) $tid)));
        $this->assertSame('admin/torneo_reporte', ReporteObservable::$vista);
        $this->assertStringContainsString('href="/organizador/torneos/' . $tid . '"', ReporteObservable::$html, 'el organizador vuelve a SU pantalla de gestión');

        foreach (['posicionesCsv', 'partidosCsv', 'inscritosCsv'] as $accion) {
            $this->assertSame(null, $this->intentar(fn() => $this->controlador()->{$accion}((string) $tid)), $accion);
            $this->assertNotNull(ReporteObservable::$csv, $accion);
        }
        $filas = $this->exportaciones();
        $this->assertCount(3, $filas);
        $this->assertSame(Fixtures::ORGANIZADOR, (int) $filas[0]['usuario_id'], 'queda a nombre del organizador');
    }

    public function test_el_organizador_no_ve_ni_exporta_el_reporte_del_torneo_de_otro(): void
    {
        [$ajeno] = $this->ligaJugada(['organizador_id' => Fixtures::ORGANIZADOR2]);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->db->exec("DELETE FROM auditoria");

        foreach (['torneo', 'posicionesCsv', 'partidosCsv', 'inscritosCsv'] as $accion) {
            $e = $this->intentar(fn() => $this->controlador()->{$accion}((string) $ajeno));
            $this->assertTrue($e instanceof RedirigidoA, "$accion no se abre");
            $this->assertSame('/admin/torneos', $e->url);
            $this->assertSame('No tenés acceso a este torneo.', Session::getFlash('error'));
            $this->assertSame('', ReporteObservable::$html, 'y no se dibujó nada');
            $this->assertSame(null, ReporteObservable::$csv, 'ni salió un archivo');
        }
        $this->assertCount(0, $this->exportaciones(), 'lo denegado no se anota como exportado');
    }

    public function test_el_administrador_ve_cualquier_torneo_y_uno_inexistente_vuelve_a_la_lista(): void
    {
        [$tid] = $this->ligaJugada(['organizador_id' => Fixtures::ORGANIZADOR2]);
        $this->assertSame(null, $this->intentar(fn() => $this->controlador()->torneo((string) $tid)));

        $e = $this->intentar(fn() => $this->controlador()->torneo('999999'));
        $this->assertTrue($e instanceof RedirigidoA);
        $this->assertSame('/admin/torneos', $e->url);
        $this->assertSame('Torneo no encontrado.', Session::getFlash('error'));

        $e = $this->intentar(fn() => $this->controlador()->posicionesCsv('999999'));
        $this->assertTrue($e instanceof RedirigidoA, 'tampoco se exporta uno inexistente');
        $this->assertSame(null, ReporteObservable::$csv);
    }

    public function test_sin_el_permiso_de_ver_torneos_el_organizador_no_entra(): void
    {
        [$tid] = $this->ligaJugada(['organizador_id' => Fixtures::ORGANIZADOR]);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->db->exec("UPDATE permisos SET puede_ver = 0 WHERE rol_id = 2 AND modulo_slug = 'torneos'");
        foreach (['torneo', 'posicionesCsv', 'partidosCsv', 'inscritosCsv'] as $accion) {
            $e = $this->intentar(fn() => $this->controlador()->{$accion}((string) $tid));
            $this->assertTrue($e instanceof AccesoDenegado, "$accion respeta la matriz de permisos");
        }
    }

    // ═══ Las pantallas ════════════════════════════════════════════════════

    public function test_la_pantalla_general_muestra_las_cifras_los_filtros_y_el_enlace_del_csv(): void
    {
        $this->ligaJugada(['nombre' => 'Liga Del Norte']);
        $_GET = ['q' => 'norte', 'estado' => 'inventado'];
        $this->controlador()->general();
        $html = ReporteObservable::$html;

        $this->assertSame('admin/reportes', ReporteObservable::$vista);
        $this->assertStringContainsString('Estado general de la plataforma', $html);
        $this->assertStringContainsString('Torneos por estado', $html);
        $this->assertStringContainsString('Torneos por formato', $html);
        $this->assertStringContainsString('Correcciones de resultados', $html);
        $this->assertStringContainsString('Liga Del Norte', $html);
        $this->assertStringContainsString('Mostrando 1–1 de 1 torneos', $html);
        $this->assertStringContainsString('href="/admin/reportes/torneos.csv?q=norte"', $html, 'el CSV conserva el filtro (y descarta el estado inventado)');
        $this->assertStringContainsString('Imprimir / PDF', $html);
        $this->assertStringContainsString('solo-impresion', $html);
        $this->assertStringContainsString('por Administrador General', $html, 'la hoja impresa dice quién la generó');
        $this->assertStringContainsString('/reporte"', $html, 'y cada torneo enlaza a su reporte');
    }

    public function test_la_pantalla_de_un_torneo_trae_resumen_posiciones_partidos_y_descargas(): void
    {
        [$tid] = $this->ligaJugada();
        $this->controlador()->torneo((string) $tid);
        $html = ReporteObservable::$html;

        $this->assertStringContainsString('Reporte del torneo', $html);
        $this->assertStringContainsString('Tabla de posiciones', $html);
        $this->assertStringContainsString('6 / 6', $html, 'partidos jugados');
        $this->assertStringContainsString('100% de los partidos jugados', $html);
        $this->assertStringContainsString("/admin/torneos/{$tid}/reporte/posiciones.csv", $html);
        $this->assertStringContainsString("/admin/torneos/{$tid}/reporte/partidos.csv", $html);
        $this->assertStringContainsString("/admin/torneos/{$tid}/reporte/inscritos.csv", $html);
        $this->assertStringContainsString('Empate', $html, 'los empates se rotulan');
        $this->assertStringContainsString('href="/admin/torneos/' . $tid . '"', $html, 'el administrador vuelve a la gestión del panel');
        $this->assertStringContainsString('no-print', $html, 'los botones no salen impresos');
    }

    public function test_lo_escrito_por_los_usuarios_se_dibuja_escapado_en_los_reportes(): void
    {
        $xss = '<script>alert(1)</script>';
        $pids = [];
        foreach ([$xss, 'B <b>', 'C', 'D'] as $i => $n) {
            $pids[] = (new ParticipanteModel())->insert(['nombre' => $n, 'nick' => 'p' . $i, 'estado' => 'activo']);
        }
        $tid = Fixtures::torneo('liga', ['nombre' => '<img src=x onerror=alert(2)>']);
        Fixtures::inscribir($tid, $pids);
        (new LigaService())->generarFixture($tid);
        Fixtures::jugarTodo($tid);

        $this->controlador()->torneo((string) $tid);
        $html = ReporteObservable::$html;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);

        $this->controlador()->general();
        $this->assertStringNotContainsString('<img src=x', ReporteObservable::$html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', ReporteObservable::$html);
    }

    public function test_el_menu_del_administrador_ofrece_reportes_y_el_del_organizador_no(): void
    {
        $menu = function (): string {
            $_sitio = (new ConfiguracionSistemaService())->sitio();   // lo inyecta BaseController::render()
            ob_start();
            try { require APP_PATH . '/views/partials/sidebar.php'; } finally { $html = (string) ob_get_clean(); }
            return $html;
        };
        $this->assertStringContainsString('href="/admin/reportes"', $menu());

        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertStringNotContainsString('/admin/reportes', $menu(), 'el general no es suyo; su reporte está en cada torneo');
    }

    public function test_las_seis_rutas_existen_y_son_de_lectura(): void
    {
        $fuente = (string) file_get_contents(BASE_PATH . '/config/routes.php');
        $esperadas = [
            'admin/reportes'                           => 'general',
            'admin/reportes/torneos.csv'               => 'generalCsv',
            'admin/torneos/{id}/reporte'               => 'torneo',
            'admin/torneos/{id}/reporte/posiciones.csv' => 'posicionesCsv',
            'admin/torneos/{id}/reporte/partidos.csv'  => 'partidosCsv',
            'admin/torneos/{id}/reporte/inscritos.csv' => 'inscritosCsv',
        ];
        foreach ($esperadas as $ruta => $accion) {
            $this->assertSame(1, preg_match('/\$router->get\(\s*\'' . preg_quote($ruta, '/') . '\'\s*,\s*\'ReporteController\'\s*,\s*\'' . $accion . '\'\s*\)/', $fuente), "$ruta → $accion (GET)");
            $this->assertSame(0, preg_match('/\$router->post\(\s*\'' . preg_quote($ruta, '/') . '\'/', $fuente), "$ruta no acepta POST: un reporte no modifica nada");
        }
    }

    public function test_los_reportes_no_escriben_en_la_base(): void
    {
        [$tid] = $this->ligaJugada();
        $tablas = ['torneos', 'enfrentamientos', 'resultados', 'tabla_posiciones', 'inscripciones', 'participantes', 'usuarios'];
        $checksum = function () use ($tablas): array {
            $r = [];
            foreach ($tablas as $t) {
                $r[$t] = $this->db->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_ASSOC)['Checksum'];
            }
            return $r;
        };
        $antes = $checksum();
        $this->controlador()->general();
        $this->controlador()->torneo((string) $tid);
        $this->controlador()->posicionesCsv((string) $tid);
        $this->controlador()->partidosCsv((string) $tid);
        $this->controlador()->inscritosCsv((string) $tid);
        $this->controlador()->generalCsv();
        $this->assertSame($antes, $checksum(), 'las tablas del dominio quedan idénticas (solo se anota la exportación en la auditoría)');
    }
}

TestCase::correrSiEsScript(ReportesTest::class);
