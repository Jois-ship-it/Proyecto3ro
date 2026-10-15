<?php
declare(strict_types=1);

/**
 * Test de la API JSON pública de solo lectura (parte G del plan).
 *
 *   GET /api/torneos?q=&tipo=&estado=&page=&por_pagina=
 *   GET /api/torneos/{id}/posiciones
 *   GET /api/torneos/{id}/calendario
 *
 * Qué pasaba antes: el sitio no tenía ninguna interfaz de datos; todo era HTML y no había un solo
 * fetch. Ahora cualquier cliente (una página, una app, un script) puede leer los torneos.
 *
 * Qué se comprueba:
 *   - El formato: {ok, datos, meta}, números como números, fechas ISO 8601, competidores {id,nombre,tipo}.
 *   - Que muestre solo lo público (ni borradores, ni torneos privados, ni cancelados en la lista) y
 *     que NO filtre datos personales (documento, correo, teléfono, cuentas).
 *   - Los filtros y la paginación, con las mismas reglas del sitio (lo inválido se ignora; los topes).
 *   - Los casos de borde: un empate (sin ganador, por los puntos), un bye, un partido sin jugar, un
 *     bracket (sin tabla), un torneo por equipos.
 *   - Los errores: 404 en JSON para lo que no existe o no es público.
 *   - Que no haya ninguna ruta de escritura bajo /api, y que no exija sesión.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/api_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

/** Se lanza en lugar del exit de Respuesta::enviar(). */
final class RespondioJson extends Exception
{
    public function __construct(public readonly array $cuerpo, public readonly int $estado)
    {
        parent::__construct("json {$estado}");
    }
}

final class ApiObservable extends ApiController
{
    protected function responderJson(array $cuerpo, int $estado = 200): never
    {
        throw new RespondioJson($cuerpo, $estado);
    }
}

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::cerrarSesion();             // la API no pide sesión: se prueba SIN ella
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    /** Ejecuta una acción y devuelve [estado, cuerpo]. */
    private function llamar(string $accion, string ...$args): array
    {
        try {
            (new ApiObservable())->{$accion}(...$args);
        } catch (RespondioJson $r) {
            return [$r->estado, $r->cuerpo];
        }
        $this->fallar("La acción $accion no respondió JSON");
    }

    private function torneo(string $nombre, string $slug = 'liga', string $estado = 'inscripcion', array $op = []): int
    {
        $privado = ($op['publico'] ?? '1') === '0';
        unset($op['publico']);
        $id = Fixtures::torneo($slug, array_merge(['nombre' => $nombre], $op));
        if ($privado) (new TorneoModel())->update($id, ['publico' => 0]);
        if ($estado !== 'inscripcion') (new TorneoModel())->updateEstado($id, $estado);
        return $id;
    }

    /** Una liga de cuatro con empates permitidos y todo jugado: dos empates y cuatro victorias. */
    private function ligaJugada(): array
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4, ['permite_empates' => '1', 'nombre' => 'Liga De La API']);
        Fixtures::jugarTodo($tid, fn(int $eid, int $i) => match ($i % 3) { 0 => [2.0, 1.0], 1 => [1.0, 1.0], default => [0.0, 3.0] });
        return [$tid, $p];
    }

    /** Todas las claves de un arreglo anidado. @return string[] */
    private function claves(mixed $v): array
    {
        $claves = [];
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_string($k)) $claves[] = $k;
                $claves = array_merge($claves, $this->claves($x));
            }
        }
        return $claves;
    }

    // ═══ /api/torneos ═════════════════════════════════════════════════════

    public function test_la_lista_solo_trae_torneos_publicos_y_con_el_formato_documentado(): void
    {
        $visible   = $this->torneo('Visible', 'liga', 'en_curso');
        $this->torneo('Borrador Secreto', 'liga', 'borrador');
        $this->torneo('Torneo Privado', 'liga', 'inscripcion', ['publico' => '0']);
        $this->torneo('Cancelado Club', 'liga', 'cancelado');

        [$estado, $r] = $this->llamar('torneos');
        $this->assertSame(200, $estado);
        $this->assertSame(true, $r['ok']);
        $this->assertCount(1, $r['datos'], 'ni borradores, ni privados, ni cancelados');
        $t = $r['datos'][0];
        $this->assertSame(['id', 'nombre', 'formato', 'modalidad', 'estado', 'inscritos', 'fecha_inicio', 'fecha_fin', 'url'], array_keys($t));
        $this->assertSame($visible, $t['id']);
        $this->assertSame(['slug' => 'liga', 'nombre' => 'Liga'], $t['formato']);
        $this->assertSame('en_curso', $t['estado']);
        $this->assertSame('individual', $t['modalidad']);
        $this->assertSame(0, $t['inscritos'], 'un número, no un texto');
        $this->assertSame('/torneo/' . $visible, $t['url']);
        $this->assertTrue(is_string($t['fecha_inicio']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['fecha_inicio']) === 1);
        $this->assertSame(['pagina', 'por_pagina', 'total', 'paginas', 'filtros'], array_keys($r['meta']));
        $this->assertSame([1, 12, 1, 1], [$r['meta']['pagina'], $r['meta']['por_pagina'], $r['meta']['total'], $r['meta']['paginas']]);
    }

    public function test_la_lista_se_filtra_como_la_del_sitio_y_dice_que_filtros_aplico(): void
    {
        $this->torneo('Copa Ñandú', 'liga', 'inscripcion');
        $this->torneo('Liga Verano', 'liga', 'en_curso');
        $this->torneo('Suizo Otoño', 'suizo', 'en_curso');

        $_GET = ['q' => 'nandu'];
        [, $r] = $this->llamar('torneos');
        $this->assertCount(1, $r['datos'], 'sin importar mayúsculas ni tildes');
        $this->assertSame('Copa Ñandú', $r['datos'][0]['nombre']);

        $_GET = ['estado' => 'en_curso', 'tipo' => 'suizo'];
        [, $r] = $this->llamar('torneos');
        $this->assertCount(1, $r['datos']);
        $this->assertSame(['q' => '', 'tipo' => 'suizo', 'estado' => 'en_curso'], $r['meta']['filtros']);

        // Lo inválido se ignora y meta.filtros lo muestra: el cliente ve qué se aplicó.
        $_GET = ['tipo' => 'inventado', 'estado' => 'borrador', 'q' => ['a']];
        [$estado, $r] = $this->llamar('torneos');
        $this->assertSame(200, $estado, 'un filtro inválido no es un error');
        $this->assertSame(['q' => '', 'tipo' => '', 'estado' => ''], $r['meta']['filtros']);
        $this->assertSame(3, $r['meta']['total']);
    }

    public function test_los_comodines_y_el_sql_en_la_busqueda_no_rompen_ni_amplian_el_resultado(): void
    {
        $this->torneo('Liga 50% Off');
        $this->torneo('Liga Común');
        foreach ([['q' => '%', 1], ['q' => "' OR '1'='1", 0], ['q' => '"; DROP TABLE torneos; --', 0], ['q' => '50%', 1]] as $caso) {
            $_GET = ['q' => $caso['q']];
            [$estado, $r] = $this->llamar('torneos');
            $this->assertSame(200, $estado);
            $this->assertSame($caso[0], $r['meta']['total'], 'q=' . $caso['q']);
        }
        $_GET = [];
        [, $r] = $this->llamar('torneos');
        $this->assertSame(2, $r['meta']['total'], 'y la tabla sigue entera');
    }

    public function test_la_paginacion_cubre_todo_con_los_topes_de_siempre(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->torneo(sprintf('Copa %02d', $i));

        $_GET = ['por_pagina' => '3'];
        [, $p1] = $this->llamar('torneos');
        $this->assertSame([3, 7, 3], [$p1['meta']['por_pagina'], $p1['meta']['total'], $p1['meta']['paginas']]);
        $ids = array_column($p1['datos'], 'id');
        foreach ([2, 3] as $n) {
            $_GET = ['por_pagina' => '3', 'page' => (string) $n];
            [, $r] = $this->llamar('torneos');
            $this->assertSame($n, $r['meta']['pagina']);
            $ids = array_merge($ids, array_column($r['datos'], 'id'));
        }
        $this->assertCount(7, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'sin repetidos');

        $_GET = ['por_pagina' => '3', 'page' => '999'];
        [, $r] = $this->llamar('torneos');
        $this->assertSame(3, $r['meta']['pagina'], 'fuera de rango: la última');
        $this->assertCount(1, $r['datos']);

        foreach (['abc', '0', '-5', ''] as $malo) {
            $_GET = ['por_pagina' => $malo];
            [, $r] = $this->llamar('torneos');
            $this->assertSame(ApiService::POR_PAGINA, $r['meta']['por_pagina'], "por_pagina={$malo} vale el valor por defecto");
        }
        $_GET = ['por_pagina' => '100000'];
        [, $r] = $this->llamar('torneos');
        $this->assertSame(ApiService::POR_PAGINA_MAXIMA, $r['meta']['por_pagina'], 'ningún cliente pide la lista entera');
        $_GET = ['page' => 'xyz'];
        [, $r] = $this->llamar('torneos');
        $this->assertSame(1, $r['meta']['pagina']);
    }

    public function test_sin_torneos_la_lista_es_vacia_y_ok(): void
    {
        [$estado, $r] = $this->llamar('torneos');
        $this->assertSame(200, $estado);
        $this->assertSame([], $r['datos']);
        $this->assertSame([1, 0, 1], [$r['meta']['pagina'], $r['meta']['total'], $r['meta']['paginas']]);
    }

    // ═══ /api/torneos/{id}/posiciones ═════════════════════════════════════

    public function test_las_posiciones_coinciden_con_la_tabla_y_traen_al_campeon(): void
    {
        [$tid] = $this->ligaJugada();
        [$estado, $r] = $this->llamar('posiciones', (string) $tid);

        $this->assertSame(200, $estado);
        $d = $r['datos'];
        $this->assertSame(['id', 'nombre', 'formato', 'modalidad', 'estado', 'campeon'], array_keys($d['torneo']));
        $this->assertSame('Liga De La API', $d['torneo']['nombre']);
        $this->assertSame('finalizado', $d['torneo']['estado']);
        $this->assertSame(['id', 'nombre', 'tipo'], array_keys($d['torneo']['campeon']));
        $this->assertSame('participante', $d['torneo']['campeon']['tipo']);

        $this->assertCount(4, $d['tabla']);
        $this->assertSame([1, 2, 3, 4], array_column($d['tabla'], 'posicion'));
        $this->assertSame($d['torneo']['campeon']['id'], $d['tabla'][0]['competidor']['id'], 'el campeón es el primero');
        foreach ($d['tabla'] as $fila) {
            $this->assertSame(['posicion', 'competidor', 'pj', 'pg', 'pe', 'pp', 'pf', 'pc', 'diferencia', 'puntos'], array_keys($fila));
            foreach (['posicion', 'pj', 'pg', 'pe', 'pp', 'pf', 'pc', 'diferencia', 'puntos'] as $n) {
                $this->assertTrue(is_int($fila[$n]), "$n es un número entero");
            }
            $this->assertSame(['id', 'nombre', 'tipo'], array_keys($fila['competidor']));
        }
        $this->assertSame(array_sum(array_column($d['tabla'], 'pf')), array_sum(array_column($d['tabla'], 'pc')), 'lo que anotan unos lo reciben otros');
        $this->assertSame(['tiene_tabla' => true, 'total' => 4], $r['meta']);

        // Lo mismo que guarda la base.
        $base = $this->db->query("SELECT participante_id, puntos FROM tabla_posiciones WHERE torneo_id = {$tid} ORDER BY posicion")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(array_map(fn($f) => (int) $f['puntos'], $base), array_column($d['tabla'], 'puntos'));
    }

    public function test_un_bracket_no_tiene_tabla_pero_si_campeon(): void
    {
        [$tid] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($tid);
        [, $r] = $this->llamar('posiciones', (string) $tid);
        $this->assertSame([], $r['datos']['tabla']);
        $this->assertSame(['tiene_tabla' => false, 'total' => 0], $r['meta']);
        $this->assertNotNull($r['datos']['torneo']['campeon']);
        $this->assertSame('eliminacion_directa', $r['datos']['torneo']['formato']['slug']);
    }

    public function test_un_torneo_por_equipos_muestra_equipos_como_competidores(): void
    {
        $eids = Fixtures::equipos(3, Fixtures::participantes(6));
        $tid = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1]);
        Fixtures::inscribir($tid, $eids, 'equipos');
        (new LigaService())->generarFixture($tid);
        Fixtures::jugarTodo($tid);

        [, $r] = $this->llamar('posiciones', (string) $tid);
        $this->assertCount(3, $r['datos']['tabla']);
        foreach ($r['datos']['tabla'] as $f) {
            $this->assertSame('equipo', $f['competidor']['tipo']);
            $this->assertTrue(in_array($f['competidor']['id'], $eids, true));
        }
        $this->assertSame('equipo', $r['datos']['torneo']['campeon']['tipo']);
    }

    public function test_un_torneo_sin_generar_tiene_una_tabla_vacia_y_sin_campeon(): void
    {
        $tid = $this->torneo('Sin generar');
        [$estado, $r] = $this->llamar('posiciones', (string) $tid);
        $this->assertSame(200, $estado);
        $this->assertSame([], $r['datos']['tabla']);
        $this->assertSame(null, $r['datos']['torneo']['campeon']);
        $this->assertSame(true, $r['meta']['tiene_tabla'], 'una liga tiene tabla aunque todavía esté vacía');
    }

    // ═══ /api/torneos/{id}/calendario ═════════════════════════════════════

    public function test_el_calendario_distingue_victoria_empate_y_partido_sin_jugar(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 3, ['permite_empates' => '1']);
        $svc = new ResultadoService();
        $pend = Fixtures::partidosPendientes($tid);
        $svc->cargar($pend[0], 2.0, 1.0, Fixtures::ADMIN);        // victoria del lado A
        $svc->cargar($pend[1], 1.0, 1.0, Fixtures::ADMIN);        // empate
        // el tercero queda pendiente

        [$estado, $r] = $this->llamar('calendario', (string) $tid);
        $this->assertSame(200, $estado);
        $partidos = $r['datos']['partidos'];
        $this->assertCount(3, $partidos);
        $this->assertSame(['total' => 3], $r['meta']);
        $this->assertSame(['id', 'ronda', 'orden', 'estado', 'es_bye', 'local', 'visitante', 'resultado', 'empate', 'ganador', 'fecha_programada'], array_keys($partidos[0]));

        $porId = array_column($partidos, null, 'id');
        $vic = $porId[$pend[0]];
        $this->assertSame(['local' => 2.0, 'visitante' => 1.0], $vic['resultado']);
        $this->assertSame(false, $vic['empate']);
        $this->assertSame($vic['local']['id'], $vic['ganador']['id'], 'gana quien hizo más puntos');
        $this->assertSame('finalizado', $vic['estado']);

        $emp = $porId[$pend[1]];
        $this->assertSame(['local' => 1.0, 'visitante' => 1.0], $emp['resultado']);
        $this->assertSame(true, $emp['empate']);
        $this->assertSame(null, $emp['ganador'], 'un empate no tiene ganador');

        $sin = $porId[$pend[2]];
        $this->assertSame(null, $sin['resultado']);
        $this->assertSame(false, $sin['empate']);
        $this->assertSame(null, $sin['ganador']);
        $this->assertTrue(in_array($sin['estado'], ['pendiente', 'en_curso'], true));
        $this->assertTrue(isset($p));
    }

    public function test_un_empate_viejo_con_ganador_guardado_igual_sale_sin_ganador(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 3, ['permite_empates' => '1']);
        $eid = Fixtures::partidosPendientes($tid)[0];
        (new ResultadoService())->cargar($eid, 1.0, 1.0, Fixtures::ADMIN);
        // Como lo dejaba el sistema antes de octubre: el lado A como «ganador».
        $this->db->exec("UPDATE enfrentamientos SET ganador_participante_id = participante_a_id WHERE id = {$eid}");

        [, $r] = $this->llamar('calendario', (string) $tid);
        $p = array_column($r['datos']['partidos'], null, 'id')[$eid];
        $this->assertSame(true, $p['empate']);
        $this->assertSame(null, $p['ganador'], 'se decide por los puntos, no por esa columna');
    }

    public function test_un_bye_no_tiene_visitante_ni_resultado_y_pasa_quien_lo_recibe(): void
    {
        [$tid] = Fixtures::torneoArrancado('suizo', 5);
        [, $r] = $this->llamar('calendario', (string) $tid);
        $bye = array_values(array_filter($r['datos']['partidos'], fn($p) => $p['es_bye']));
        $this->assertCount(1, $bye);
        $this->assertSame(null, $bye[0]['visitante']);
        $this->assertSame(null, $bye[0]['resultado']);
        $this->assertSame(false, $bye[0]['empate']);
        $this->assertSame($bye[0]['local']['id'], $bye[0]['ganador']['id'], 'pasa de ronda quien lo recibe');
        $this->assertSame('bye', $bye[0]['estado']);
    }

    public function test_la_fecha_programada_sale_en_iso_8601(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 3);
        $eid = Fixtures::partidosPendientes($tid)[0];
        $this->db->exec("UPDATE enfrentamientos SET fecha_programada = '2026-10-12 18:30:00' WHERE id = {$eid}");
        [, $r] = $this->llamar('calendario', (string) $tid);
        $partidos = array_column($r['datos']['partidos'], null, 'id');
        $this->assertSame(1, preg_match('/^2026-10-12T18:30:00[+-]\d{2}:\d{2}$/', (string) $partidos[$eid]['fecha_programada']), (string) $partidos[$eid]['fecha_programada']);
        $otro = array_values(array_filter($partidos, fn($p) => $p['id'] !== $eid))[0];
        $this->assertSame(null, $otro['fecha_programada'], 'sin fecha, null');
    }

    public function test_los_partidos_vienen_ronda_por_ronda(): void
    {
        [$tid] = $this->ligaJugada();
        [, $r] = $this->llamar('calendario', (string) $tid);
        $claves = array_map(fn($p) => [$p['ronda']['numero'], $p['orden']], $r['datos']['partidos']);
        $orden = $claves;
        usort($orden, fn($a, $b) => $a <=> $b);
        $this->assertSame($orden, $claves);
        $this->assertCount(6, $r['datos']['partidos']);
    }

    // ═══ Qué se puede ver y qué no ════════════════════════════════════════

    public function test_lo_que_no_existe_o_no_es_publico_es_un_404_en_json(): void
    {
        $borrador = $this->torneo('Borrador', 'liga', 'borrador');
        $privado  = $this->torneo('Privado', 'liga', 'inscripcion', ['publico' => '0']);
        foreach (['posiciones', 'calendario'] as $accion) {
            foreach ([(string) $borrador, (string) $privado, '999999'] as $id) {
                [$estado, $r] = $this->llamar($accion, $id);
                $this->assertSame(404, $estado, "$accion/$id");
                $this->assertSame(false, $r['ok']);
                $this->assertSame('no_encontrado', $r['error']['codigo']);
                $this->assertSame('El torneo no existe o no es público.', $r['error']['mensaje']);
                $this->assertSame(['ok', 'error'], array_keys($r), 'sin datos de ninguna clase');
            }
        }
    }

    public function test_un_torneo_cancelado_se_ve_por_su_enlace_como_en_el_sitio(): void
    {
        $tid = $this->torneo('Cancelado Club', 'liga', 'cancelado');
        [$estado] = $this->llamar('posiciones', (string) $tid);
        $this->assertSame(200, $estado, 'la página pública de un torneo cancelado también se abre por enlace');
        [, $lista] = $this->llamar('torneos');
        $this->assertSame(0, $lista['meta']['total'], 'pero no figura en la lista');
    }

    public function test_ninguna_respuesta_filtra_datos_personales(): void
    {
        $pid = (new ParticipanteModel())->insert([
            'nombre' => 'Ana Reservada', 'nick' => 'ana', 'documento' => '9.999.999-9',
            'email' => 'secreto@test.local', 'telefono' => '099123456', 'estado' => 'activo',
        ]);
        $otros = Fixtures::participantes(3);
        [$tid] = [Fixtures::torneo('liga', ['nombre' => 'Torneo Con Datos'])];
        Fixtures::inscribir($tid, array_merge([$pid], $otros));
        (new LigaService())->generarFixture($tid);
        Fixtures::jugarTodo($tid);

        $prohibidas = ['email', 'documento', 'telefono', 'password', 'password_hash', 'usuario_id', 'token', 'creado_por', 'organizador_id', 'ip', 'user_agent'];
        $respuestas = [$this->llamar('torneos')[1], $this->llamar('posiciones', (string) $tid)[1], $this->llamar('calendario', (string) $tid)[1]];
        foreach ($respuestas as $i => $r) {
            $json = Respuesta::codificar($r);
            foreach (['secreto@test.local', '099123456', '9.999.999-9'] as $dato) {
                $this->assertStringNotContainsString($dato, $json, "respuesta $i: ni el dato personal");
            }
            $claves = $this->claves($r);
            foreach ($prohibidas as $k) {
                $this->assertFalse(in_array($k, $claves, true), "respuesta $i: ninguna clave «{$k}»");
            }
        }
        $this->assertStringContainsString('Ana Reservada', Respuesta::codificar($respuestas[1]), 'el nombre sí: es público como en el sitio');
    }

    public function test_funciona_sin_sesion_y_con_ella_da_lo_mismo(): void
    {
        $this->torneo('Visible', 'liga', 'en_curso');
        [, $anonimo] = $this->llamar('torneos');
        Fixtures::loguearComo(Fixtures::ADMIN);
        [, $admin] = $this->llamar('torneos');
        $this->assertSame($anonimo, $admin, 'ni el administrador ve más por la API: es la vista pública');
    }

    // ═══ Formato y rutas ══════════════════════════════════════════════════

    public function test_el_json_es_valido_y_conserva_las_tildes(): void
    {
        $this->torneo('Copa Ñandú Ávila');
        [, $r] = $this->llamar('torneos');
        $json = Respuesta::codificar($r);
        $this->assertStringContainsString('Copa Ñandú Ávila', $json, 'UTF-8 sin escapes \\u00f1');
        $vuelta = json_decode($json, true);
        $this->assertSame($r, $vuelta, 'ida y vuelta sin pérdidas');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_hay_tres_rutas_y_ninguna_escribe(): void
    {
        $fuente = (string) file_get_contents(BASE_PATH . '/config/routes.php');
        preg_match_all('/\$router->(get|post)\(\s*\'(api[^\']*)\'\s*,\s*\'(\w+)\'\s*,\s*\'(\w+)\'\s*\)/', $fuente, $m, PREG_SET_ORDER);
        $rutas = array_map(fn($x) => [$x[1], $x[2], $x[3], $x[4]], $m);
        $this->assertSame([
            ['get', 'api/torneos',                 'ApiController', 'torneos'],
            ['get', 'api/torneos/{id}/posiciones', 'ApiController', 'posiciones'],
            ['get', 'api/torneos/{id}/calendario', 'ApiController', 'calendario'],
        ], $rutas, 'las tres rutas, todas GET');
        $this->assertSame(0, preg_match('/\$router->(post)\(\s*\'api/', $fuente), 'ninguna ruta de escritura bajo /api');
    }

    public function test_el_codigo_de_la_api_no_escribe_en_la_base(): void
    {
        foreach (['app/controllers/ApiController.php', 'app/services/ApiService.php'] as $archivo) {
            $codigo = (string) file_get_contents(BASE_PATH . '/' . $archivo);
            $codigo = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $codigo);        // sin comentarios
            $this->assertSame(0, preg_match('/->(insert|update|delete|exec|execute)\s*\(|\$_POST|\$_COOKIE|file_put_contents/i', $codigo), "$archivo no escribe ni lee escrituras");
        }
    }

    public function test_una_direccion_de_la_api_con_otro_metodo_es_un_405_y_no_un_404(): void
    {
        $router = new Router();
        require BASE_PATH . '/config/routes.php';
        $this->assertSame(['GET'], $router->metodosPermitidos('api/torneos'));
        $this->assertSame(['GET'], $router->metodosPermitidos('api/torneos/5/posiciones'));
        $this->assertSame(['POST'], $router->metodosPermitidos('admin/resultados/cargar'));
        $this->assertSame([], $router->metodosPermitidos('api/lo-que-no-existe'), 'una dirección que no existe con ningún método sigue siendo un 404');
        $this->assertSame([], $router->metodosPermitidos('api/torneos/abc/posiciones'), 'y un {id} inválido también');
        $this->assertSame('metodo_no_permitido', Respuesta::codigoPara(405));
    }

    public function test_la_api_no_abre_sesion_ni_manda_cookie(): void
    {
        $front = (string) file_get_contents(BASE_PATH . '/public/index.php');
        $this->assertSame(1, preg_match('/if \(\$rutaPedida !== \'api\' && !str_starts_with\(\$rutaPedida, \'api\/\'\)\) \{\s*Session::start\(\);/', $front),
            'index.php solo inicia la sesión fuera de /api: un cliente de la API no crea un archivo de sesión por pedido');
        $this->assertSame(1, substr_count($front, 'Session::start();'), 'y no la inicia en ningún otro lado');
    }

    public function test_el_estado_de_la_base_no_cambia_al_leer(): void
    {
        [$tid] = $this->ligaJugada();
        $tablas = ['torneos', 'enfrentamientos', 'resultados', 'tabla_posiciones', 'inscripciones', 'participantes', 'usuarios', 'auditoria'];
        $suma = function () use ($tablas): array {
            $r = [];
            foreach ($tablas as $t) $r[$t] = $this->db->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_ASSOC)['Checksum'];
            return $r;
        };
        $antes = $suma();
        $this->llamar('torneos');
        $this->llamar('posiciones', (string) $tid);
        $this->llamar('calendario', (string) $tid);
        $this->assertSame($antes, $suma(), 'ninguna tabla cambió, ni siquiera la auditoría');
    }
}

TestCase::correrSiEsScript(ApiTest::class);
