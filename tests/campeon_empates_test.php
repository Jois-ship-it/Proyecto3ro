<?php
declare(strict_types=1);

/**
 * Test de dos defectos de los resultados que se encontraron al hacer los reportes:
 *
 * 1. UN EMPATE NO TIENE GANADOR.
 *    Antes: al cargar o corregir un partido empatado se guardaba al lado A como «ganador» y al lado B
 *    como «perdedor». La tabla de posiciones se calcula por los puntos y nunca lo leyó, así que no
 *    se notaba; pero cualquier lista que mostrara esa columna (el reporte, la API) presentaba un
 *    empate como una victoria. Corregir tampoco validaba el empate como cargar(): se podía
 *    «corregir» a un empate en Eliminación Directa o en un torneo que no los admite.
 *    Ahora: las columnas quedan en NULL, y corregir (y pedir una corrección) respeta la misma regla.
 *
 * 2. CORREGIR UN TORNEO FINALIZADO NO REVISABA AL CAMPEÓN.
 *    Antes: la corrección recalculaba la tabla y dejaba el campeón como estaba, así que podía quedar
 *    nombrado alguien que ya no era el primero (la siembra de demostración lo hacía en varios
 *    torneos). En Eliminación Directa, corregir la final dejaba el resultado nuevo y el campeón viejo.
 *    Ahora: se vuelve a decidir con la misma regla de siempre. Un líder distinto pasa a ser el
 *    campeón; si los dos primeros quedan empatados en todo el torneo se reabre con un desempate.
 *
 * 3. LA MIGRACIÓN `2026_10_empates_sin_ganador.sql` deja en NULL los empates ya guardados y no toca
 *    ni los bye ni las victorias.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/campeon_empates_test.php
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

final class ResultadoObservable extends ResultadoController
{
    protected function redirect(string $url): never { throw new RedirigidoA($url); }
}

class CampeonEmpatesTest extends TestCase
{
    private const MOTIVO = 'Error de planilla en el acta del partido';

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_REFERER']);
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    /** El partido entre dos participantes, sea cual sea el lado de cada uno. */
    private function partido(int $torneoId, int $p, int $q): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM enfrentamientos
             WHERE torneo_id = :t AND es_bye = 0
               AND ((participante_a_id = :p1 AND participante_b_id = :q1) OR (participante_a_id = :q2 AND participante_b_id = :p2))
             ORDER BY id LIMIT 1"
        );
        $st->execute([':t' => $torneoId, ':p1' => $p, ':q1' => $q, ':q2' => $q, ':p2' => $p]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new RuntimeException("No hay partido entre $p y $q");
        return $f;
    }

    /** Puntos de $p y de $q, puestos en el lado que le toca a cada uno. @return array{0:int,1:float,2:float} */
    private function orientar(array $enf, int $p, float $ptsP, float $ptsQ): array
    {
        return (int) $enf['participante_a_id'] === $p
            ? [(int) $enf['id'], $ptsP, $ptsQ]
            : [(int) $enf['id'], $ptsQ, $ptsP];
    }

    private function jugar(int $torneoId, int $p, int $q, float $ptsP, float $ptsQ): void
    {
        [$id, $a, $b] = $this->orientar($this->partido($torneoId, $p, $q), $p, $ptsP, $ptsQ);
        (new ResultadoService())->cargar($id, $a, $b, Fixtures::ADMIN);
    }

    private function corregirPartido(int $torneoId, int $p, int $q, float $ptsP, float $ptsQ): ?string
    {
        [$id, $a, $b] = $this->orientar($this->partido($torneoId, $p, $q), $p, $ptsP, $ptsQ);
        return (new ResultadoService())->corregir($id, $a, $b, self::MOTIVO, Fixtures::ADMIN);
    }

    private function fila(string $sql): array
    {
        return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function torneo(int $id): array
    {
        return $this->fila("SELECT * FROM torneos WHERE id = {$id}");
    }

    /** Una liga de tres con los empates permitidos, sin jugar. @return array{0:int,1:int,2:int,3:int} [torneo, X, Y, Z] */
    private function ligaDeTres(array $op = []): array
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 3, $op + ['permite_empates' => '1']);
        return [$tid, $p[0], $p[1], $p[2]];
    }

    /** Liga de tres terminada: X gana todo (6 puntos), Y gana a Z (3 puntos), Z 0. El campeón es X. */
    private function ligaTerminada(): array
    {
        [$tid, $x, $y, $z] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 2, 1);
        $this->jugar($tid, $x, $z, 2, 0);
        $this->jugar($tid, $y, $z, 2, 0);
        return [$tid, $x, $y, $z];
    }

    private function auditoria(string $accion): array
    {
        return $this->db->query("SELECT * FROM auditoria WHERE accion = " . $this->db->quote($accion) . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ═══ 1. Un empate no tiene ganador ════════════════════════════════════

    public function test_un_empate_no_guarda_ganador_ni_perdedor(): void
    {
        [$tid, $x, $y, $z] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 1, 1);
        $this->jugar($tid, $x, $z, 2, 0);

        $emp = $this->partido($tid, $x, $y);
        $this->assertSame('finalizado', $emp['estado']);
        foreach (['ganador_participante_id', 'ganador_equipo_id', 'perdedor_participante_id', 'perdedor_equipo_id'] as $col) {
            $this->assertSame(null, $emp[$col], "enfrentamientos.$col queda en NULL en un empate");
        }
        $res = $this->fila("SELECT * FROM resultados WHERE enfrentamiento_id = " . (int) $emp['id']);
        $this->assertSame(null, $res['ganador_participante_id'], 'resultados.ganador también');
        $this->assertSame(null, $res['ganador_equipo_id']);

        // Y una victoria sigue teniendo los dos.
        $vic = $this->partido($tid, $x, $z);
        $this->assertSame($x, (int) $vic['ganador_participante_id']);
        $this->assertSame($z, (int) $vic['perdedor_participante_id']);
    }

    public function test_la_tabla_cuenta_el_empate_igual_que_antes(): void
    {
        [$tid, $x, $y, $z] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 1, 1);
        $this->jugar($tid, $x, $z, 2, 0);
        $this->jugar($tid, $y, $z, 2, 0);

        $tabla = array_column($this->db->query("SELECT participante_id, pe, pg, puntos FROM tabla_posiciones WHERE torneo_id = {$tid}")->fetchAll(PDO::FETCH_ASSOC), null, 'participante_id');
        $this->assertSame(1, (int) $tabla[$x]['pe'], 'X empató una vez');
        $this->assertSame(1, (int) $tabla[$y]['pe']);
        $this->assertSame(0, (int) $tabla[$z]['pe']);
        $this->assertSame(4, (int) $tabla[$x]['puntos'], '3 por ganar y 1 por empatar');
    }

    public function test_un_empate_por_equipos_tampoco_tiene_ganador(): void
    {
        $eids = Fixtures::equipos(3, Fixtures::participantes(6));
        $tid = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'permite_empates' => '1']);
        Fixtures::inscribir($tid, $eids, 'equipos');
        (new LigaService())->generarFixture($tid);
        $enf = $this->fila("SELECT id FROM enfrentamientos WHERE torneo_id = {$tid} ORDER BY id LIMIT 1");
        (new ResultadoService())->cargar((int) $enf['id'], 1.0, 1.0, Fixtures::ADMIN);

        $e = $this->fila("SELECT * FROM enfrentamientos WHERE id = " . (int) $enf['id']);
        $this->assertSame('finalizado', $e['estado']);
        $this->assertSame([null, null], [$e['ganador_equipo_id'], $e['perdedor_equipo_id']]);
    }

    public function test_corregir_a_un_empate_y_de_un_empate_a_una_victoria_actualiza_las_columnas(): void
    {
        [$tid, $x, $y, $z] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 2, 1);

        $this->corregirPartido($tid, $x, $y, 1, 1);
        $enf = $this->partido($tid, $x, $y);
        $this->assertSame([null, null], [$enf['ganador_participante_id'], $enf['perdedor_participante_id']], 'una victoria corregida a empate pierde su ganador');

        $this->corregirPartido($tid, $x, $y, 0, 3);
        $enf = $this->partido($tid, $x, $y);
        $this->assertSame([$y, $x], [(int) $enf['ganador_participante_id'], (int) $enf['perdedor_participante_id']], 'y un empate corregido a victoria lo recupera');
        $res = $this->fila("SELECT ganador_participante_id FROM resultados WHERE enfrentamiento_id = " . (int) $enf['id']);
        $this->assertSame($y, (int) $res['ganador_participante_id']);
    }

    public function test_corregir_no_es_una_via_para_saltear_la_regla_de_empates(): void
    {
        // Liga que NO admite empates: cargar() los rechaza y corregir() ahora también.
        [$tid, $p] = Fixtures::torneoArrancado('liga', 3);
        $this->jugar($tid, $p[0], $p[1], 2, 1);
        $this->assertThrows(fn() => $this->corregirPartido($tid, $p[0], $p[1], 1, 1), 'no permite empates');
        $this->assertSame($p[0], (int) $this->partido($tid, $p[0], $p[1])['ganador_participante_id'], 'y el resultado quedó como estaba');

        // Eliminación Directa: nunca.
        [$bk, $q] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($bk);
        $final = $this->fila("SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id WHERE e.torneo_id = {$bk} ORDER BY r.numero DESC LIMIT 1");
        $this->assertThrows(fn() => (new ResultadoService())->corregir((int) $final['id'], 2.0, 2.0, self::MOTIVO, Fixtures::ADMIN), 'Eliminación Directa');
    }

    public function test_pedir_una_correccion_a_un_empate_donde_no_vale_tampoco_se_puede(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 3);
        $this->jugar($tid, $p[0], $p[1], 2, 1);
        $enf = $this->partido($tid, $p[0], $p[1]);
        $this->assertThrows(
            fn() => (new CorreccionService())->solicitar((int) $enf['id'], 1.0, 1.0, self::MOTIVO, Fixtures::ORGANIZADOR),
            'no permite empates'
        );
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM solicitudes_correccion")->fetchColumn(), 'no llegó a registrarse una solicitud imposible');
        // En una liga que sí los admite, se puede pedir.
        [$t2, $x, $y] = $this->ligaDeTres();
        $this->jugar($t2, $x, $y, 2, 1);
        $e2 = $this->partido($t2, $x, $y);
        (new CorreccionService())->solicitar((int) $e2['id'], 1.0, 1.0, self::MOTIVO, Fixtures::ORGANIZADOR);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM solicitudes_correccion")->fetchColumn());
    }

    // ═══ 2. Corregir un torneo finalizado revisa al campeón ═══════════════

    public function test_la_liga_terminada_tiene_como_campeon_al_primero(): void
    {
        [$tid, $x] = $this->ligaTerminada();
        $t = $this->torneo($tid);
        $this->assertSame('finalizado', $t['estado']);
        $this->assertSame($x, (int) $t['campeon_participante_id']);
    }

    public function test_una_correccion_que_cambia_al_lider_cambia_al_campeon(): void
    {
        [$tid, $x, $y] = $this->ligaTerminada();
        $this->db->exec("DELETE FROM auditoria");

        $aviso = $this->corregirPartido($tid, $x, $y, 0, 3);        // ahora Y le gana a X: Y 6 puntos, X 3

        $t = $this->torneo($tid);
        $this->assertSame($y, (int) $t['campeon_participante_id'], 'el campeón es el nuevo primero');
        $this->assertSame('finalizado', $t['estado'], 'el torneo sigue finalizado');
        $this->assertSame(1, (int) $this->fila("SELECT posicion FROM tabla_posiciones WHERE torneo_id = {$tid} AND participante_id = {$y}")['posicion']);
        $this->assertNotNull($aviso, 'quien corrige se entera');
        $this->assertStringContainsString('El campeón cambió', (string) $aviso);
        $this->assertStringContainsString('Jugador 2', (string) $aviso, 'dice quién es ahora');

        $log = $this->auditoria('cambiar_campeon');
        $this->assertCount(1, $log, 'queda en la auditoría');
        $this->assertStringContainsString('Jugador 1', (string) $log[0]['descripcion'], 'con el campeón anterior');
        $this->assertStringContainsString('Jugador 2', (string) $log[0]['descripcion'], 'y el nuevo');
    }

    public function test_una_correccion_que_no_cambia_al_lider_no_toca_el_campeon(): void
    {
        [$tid, $x, $y, $z] = $this->ligaTerminada();
        $this->db->exec("DELETE FROM auditoria");

        $aviso = $this->corregirPartido($tid, $x, $z, 5, 0);        // X sigue ganando, por más

        $this->assertSame(null, $aviso);
        $this->assertSame($x, (int) $this->torneo($tid)['campeon_participante_id']);
        $this->assertCount(0, $this->auditoria('cambiar_campeon'), 'sin cambio no hay anotación de cambio');
        $this->assertCount(0, $this->auditoria('reabrir_torneo'));
    }

    public function test_el_campeon_siempre_es_el_primero_de_la_tabla_tras_cualquier_correccion(): void
    {
        [$tid, $x, $y, $z] = $this->ligaTerminada();
        $secuencia = [
            [$x, $y, 0, 3], [$y, $z, 0, 4], [$x, $z, 1, 4], [$x, $y, 3, 0], [$y, $z, 3, 1], [$x, $z, 0, 2],
        ];
        foreach ($secuencia as $i => [$p, $q, $a, $b]) {
            $this->corregirPartido($tid, $p, $q, $a, $b);
            $t = $this->torneo($tid);
            $tabla = $this->db->query("SELECT participante_id FROM tabla_posiciones WHERE torneo_id = {$tid} ORDER BY posicion")->fetchAll(PDO::FETCH_COLUMN);
            if ($t['estado'] === 'finalizado') {
                $this->assertSame((int) $tabla[0], (int) $t['campeon_participante_id'], "corrección $i: el campeón es el primero de la tabla");
            } else {
                $this->assertSame(null, $t['campeon_participante_id'], "corrección $i: reabierto, sin campeón");
                $this->assertSame('en_curso', $t['estado']);
                // Se juega el desempate para volver a cerrar y seguir.
                $pend = Fixtures::partidosPendientes($tid);
                $this->assertCount(1, $pend, 'hay un único partido pendiente: el desempate');
                (new ResultadoService())->cargar($pend[0], 2.0, 1.0, Fixtures::ADMIN);
                $this->assertSame('finalizado', $this->torneo($tid)['estado'], 'el desempate cierra el torneo');
            }
        }
    }

    public function test_si_la_correccion_empata_a_los_dos_primeros_el_torneo_se_reabre_con_un_desempate(): void
    {
        [$tid, $x, $y] = $this->ligaTerminada();
        $this->db->exec("DELETE FROM auditoria");

        $aviso = $this->corregirPartido($tid, $x, $y, 1, 1);        // X: 4 puntos, Y: 4 puntos, iguales en todo

        $t = $this->torneo($tid);
        $this->assertSame('en_curso', $t['estado'], 'el torneo vuelve a «en curso»');
        $this->assertSame(null, $t['campeon_participante_id'], 'y no tiene campeón todavía');
        $this->assertStringContainsString('volvió a «En curso»', (string) $aviso);
        $this->assertCount(1, $this->auditoria('reabrir_torneo'));
        $this->assertCount(0, $this->auditoria('cambiar_campeon'));

        $pend = Fixtures::partidosPendientes($tid);
        $this->assertCount(1, $pend, 'el único partido pendiente es el desempate');
        $enf = $this->fila("SELECT e.*, r.nombre AS ronda FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id WHERE e.id = {$pend[0]}");
        $this->assertTrue(str_starts_with((string) $enf['ronda'], 'Desempate'), 'la ronda se llama «Desempate N»');
        $this->assertSame([$x, $y], (function (array $e) { $l = [(int) $e['participante_a_id'], (int) $e['participante_b_id']]; sort($l); return $l; })($enf), 'entre los dos empatados');

        // El desempate lo juegan y define al campeón, como cualquier desempate.
        (new ResultadoService())->cargar($pend[0], 2.0, 1.0, Fixtures::ADMIN);
        $t = $this->torneo($tid);
        $this->assertSame('finalizado', $t['estado']);
        $this->assertSame((int) $enf['participante_a_id'], (int) $t['campeon_participante_id'], 'gana el desempate el lado A');
    }

    public function test_corregir_un_torneo_en_curso_no_decide_campeon(): void
    {
        [$tid, $x, $y] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 2, 1);                           // faltan partidos: sigue en curso
        $this->corregirPartido($tid, $x, $y, 0, 3);
        $t = $this->torneo($tid);
        $this->assertSame('en_curso', $t['estado']);
        $this->assertSame(null, $t['campeon_participante_id'], 'no se corona a nadie por corregir a mitad del torneo');
        $this->assertCount(0, $this->auditoria('cambiar_campeon'));
    }

    public function test_el_campeon_de_una_liga_por_equipos_tambien_se_revisa(): void
    {
        $eids = Fixtures::equipos(3, Fixtures::participantes(6));
        $tid = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'permite_empates' => '1']);
        Fixtures::inscribir($tid, $eids, 'equipos');
        (new LigaService())->generarFixture($tid);

        [$e1, $e2, $e3] = $eids;
        $jugar = function (int $p, int $q, float $a, float $b, bool $corregir = false) use ($tid): void {
            $st = $this->db->prepare("SELECT * FROM enfrentamientos WHERE torneo_id = :t AND ((equipo_a_id = :p AND equipo_b_id = :q) OR (equipo_a_id = :q2 AND equipo_b_id = :p2))");
            $st->execute([':t' => $tid, ':p' => $p, ':q' => $q, ':q2' => $q, ':p2' => $p]);
            $enf = $st->fetch(PDO::FETCH_ASSOC);
            [$pa, $pb] = (int) $enf['equipo_a_id'] === $p ? [$a, $b] : [$b, $a];
            $svc = new ResultadoService();
            $corregir ? $svc->corregir((int) $enf['id'], $pa, $pb, self::MOTIVO, Fixtures::ADMIN) : $svc->cargar((int) $enf['id'], $pa, $pb, Fixtures::ADMIN);
        };
        $jugar($e1, $e2, 2, 1);
        $jugar($e1, $e3, 2, 0);
        $jugar($e2, $e3, 2, 0);
        $this->assertSame($e1, (int) $this->torneo($tid)['campeon_equipo_id']);

        $jugar($e1, $e2, 0, 3, true);
        $t = $this->torneo($tid);
        $this->assertSame($e2, (int) $t['campeon_equipo_id'], 'por equipos cambia campeon_equipo_id');
        $this->assertSame('finalizado', $t['estado']);
    }

    // ── Eliminación directa ──

    /** @return array{0:int,1:array} [torneo, la final] ya jugado: gana siempre el lado A */
    private function bracketJugado(): array
    {
        [$tid] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($tid);
        $final = $this->fila("SELECT e.* FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id WHERE e.torneo_id = {$tid} ORDER BY r.numero DESC LIMIT 1");
        return [$tid, $final];
    }

    public function test_corregir_la_final_a_favor_del_otro_finalista_cambia_al_campeon(): void
    {
        [$tid, $final] = $this->bracketJugado();
        $this->assertSame((int) $final['participante_a_id'], (int) $this->torneo($tid)['campeon_participante_id'], 'el campeón de la final cargada');
        $this->db->exec("DELETE FROM auditoria");

        $aviso = (new ResultadoService())->corregir((int) $final['id'], 0.0, 3.0, self::MOTIVO, Fixtures::ADMIN);

        $t = $this->torneo($tid);
        $this->assertSame((int) $final['participante_b_id'], (int) $t['campeon_participante_id'], 'ahora gana el lado B');
        $this->assertSame('finalizado', $t['estado']);
        $this->assertStringContainsString('El campeón cambió', (string) $aviso);
        $this->assertCount(1, $this->auditoria('cambiar_campeon'));
    }

    public function test_corregir_la_final_sin_cambiar_al_ganador_no_toca_al_campeon(): void
    {
        [$tid, $final] = $this->bracketJugado();
        $this->db->exec("DELETE FROM auditoria");
        $aviso = (new ResultadoService())->corregir((int) $final['id'], 5.0, 1.0, self::MOTIVO, Fixtures::ADMIN);
        $this->assertSame(null, $aviso);
        $this->assertSame((int) $final['participante_a_id'], (int) $this->torneo($tid)['campeon_participante_id']);
        $this->assertCount(0, $this->auditoria('cambiar_campeon'));
    }

    public function test_una_semifinal_sigue_sin_poder_corregirse_una_vez_que_su_ganador_avanzo(): void
    {
        [$tid] = $this->bracketJugado();
        $semi = $this->fila("SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id WHERE e.torneo_id = {$tid} ORDER BY r.numero ASC LIMIT 1");
        $this->assertThrows(fn() => (new ResultadoService())->corregir((int) $semi['id'], 0.0, 3.0, self::MOTIVO, Fixtures::ADMIN), 'ronda posterior');
    }

    // ── Suizo ──

    public function test_en_el_suizo_la_correccion_de_la_ultima_ronda_tambien_revisa_al_campeon(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 2, 'permite_empates' => '1']);
        $svc = new ResultadoService();
        foreach (Fixtures::partidosPendientes($tid, 1) as $eid) $svc->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        (new SistemaSuizoService())->generarSiguienteRonda($tid);
        foreach (Fixtures::partidosPendientes($tid, 2) as $eid) $svc->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);

        $t = $this->torneo($tid);
        $this->assertSame('finalizado', $t['estado'], 'con un líder claro el suizo termina');
        $lider = (int) $t['campeon_participante_id'];

        // La final de la ronda 2 es el partido del líder: se la damos al rival.
        $enf = $this->fila(
            "SELECT e.* FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = {$tid} AND r.numero = 2 AND (e.participante_a_id = {$lider} OR e.participante_b_id = {$lider})"
        );
        [$a, $b] = (int) $enf['participante_a_id'] === $lider ? [0.0, 3.0] : [3.0, 0.0];
        $aviso = $svc->corregir((int) $enf['id'], $a, $b, self::MOTIVO, Fixtures::ADMIN);

        $t = $this->torneo($tid);
        $primero = (int) $this->db->query("SELECT participante_id FROM tabla_posiciones WHERE torneo_id = {$tid} ORDER BY posicion LIMIT 1")->fetchColumn();
        if ($t['estado'] === 'finalizado') {
            $this->assertSame($primero, (int) $t['campeon_participante_id'], 'el campeón es el primero de la tabla');
            $this->assertNotSame($lider, (int) $t['campeon_participante_id'], 'y ya no es el que había perdido la final');
            $this->assertNotNull($aviso);
        } else {
            $this->assertSame(null, $t['campeon_participante_id'], 'o el torneo se reabrió por un empate arriba');
        }
        $this->assertTrue(count($p) === 4);
    }

    // ── El aviso llega a quien corrige ──

    public function test_aprobar_una_solicitud_devuelve_el_aviso_del_campeon(): void
    {
        [$tid, $x, $y] = $this->ligaTerminada();
        $enf = $this->partido($tid, $x, $y);
        [, $a, $b] = $this->orientar($enf, $x, 0, 3);
        $svc = new CorreccionService();
        $svc->solicitar((int) $enf['id'], $a, $b, self::MOTIVO, Fixtures::ORGANIZADOR);
        $sid = (int) $this->db->query("SELECT MAX(id) FROM solicitudes_correccion")->fetchColumn();

        $aviso = $svc->aprobar($sid, Fixtures::ADMIN);
        $this->assertStringContainsString('El campeón cambió', (string) $aviso);
        $this->assertSame($y, (int) $this->torneo($tid)['campeon_participante_id']);
        $this->assertSame('aprobada', $this->fila("SELECT estado FROM solicitudes_correccion WHERE id = {$sid}")['estado']);
    }

    public function test_la_pantalla_avisa_con_un_mensaje_aparte_que_el_campeon_cambio(): void
    {
        [$tid, $x, $y] = $this->ligaTerminada();
        $enf = $this->partido($tid, $x, $y);
        [, $a, $b] = $this->orientar($enf, $x, 0, 3);
        $_POST = [
            'csrf_token' => Csrf::generate(), 'enfrentamiento_id' => (string) $enf['id'], 'puntos_a' => (string) $a,
            'puntos_b' => (string) $b, 'motivo_correccion' => self::MOTIVO, 'torneo_id' => (string) $tid,
        ];
        $e = $this->assertThrows(fn() => (new ResultadoObservable())->corregir(), 'redirect');
        $this->assertTrue($e instanceof RedirigidoA);
        $this->assertStringContainsString('corregido correctamente', (string) Session::getFlash('success'));
        $this->assertStringContainsString('El campeón cambió', (string) Session::getFlash('warning'), 'un aviso aparte, para que no pase desapercibido');
    }

    // ═══ 3. La migración de los empates ya guardados ═════════════════════

    private const MIGRACION = __DIR__ . '/../database/migrations/2026_10_empates_sin_ganador.sql';

    /**
     * Corre las sentencias de la migración una por una. La conexión de la aplicación usa consultas
     * preparadas nativas, que no admiten varias sentencias juntas.
     */
    private function aplicarMigracion(): void
    {
        $sql = (string) preg_replace('/--[^
]*/', '', (string) file_get_contents(self::MIGRACION));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
            $stmt = $this->db->query($sentencia);
            if ($stmt->columnCount() > 0) $stmt->fetchAll();
        }
    }

    /** Un torneo con datos «de antes»: empates con el lado A como ganador. @return array<string,int|string> */
    private function datosDeAntes(): array
    {
        [$tid, $x, $y, $z] = $this->ligaDeTres();
        $this->jugar($tid, $x, $y, 1, 1);        // empate
        $this->jugar($tid, $x, $z, 2, 0);        // victoria de X
        $this->jugar($tid, $y, $z, 0, 0);        // otro empate
        // Se reescribe como lo hacía el sistema antes: al lado A como ganador y al B como perdedor.
        $this->db->exec(
            "UPDATE enfrentamientos e JOIN resultados r ON r.enfrentamiento_id = e.id
                SET e.ganador_participante_id = e.participante_a_id, e.perdedor_participante_id = e.participante_b_id,
                    r.ganador_participante_id = e.participante_a_id
              WHERE e.torneo_id = {$tid} AND r.puntos_a = r.puntos_b"
        );
        // Y un suizo con un bye, cuyo ganador SÍ es quien pasa de ronda aunque 0 = 0.
        [$sw] = Fixtures::torneoArrancado('suizo', 5);
        $bye = $this->fila("SELECT * FROM enfrentamientos WHERE torneo_id = {$sw} AND es_bye = 1");
        return ['liga' => $tid, 'x' => $x, 'y' => $y, 'z' => $z, 'bye' => (int) $bye['id'], 'byeLado' => (int) $bye['participante_a_id']];
    }

    public function test_la_migracion_deja_en_null_los_empates_y_no_toca_ni_victorias_ni_bye(): void
    {
        $d = $this->datosDeAntes();
        $this->db->exec("UPDATE enfrentamientos SET ganador_participante_id = {$d['byeLado']} WHERE id = {$d['bye']}");
        $this->db->exec("UPDATE resultados SET ganador_participante_id = {$d['byeLado']} WHERE enfrentamiento_id = {$d['bye']}");
        $this->assertSame((int) $d['x'], (int) $this->partido($d['liga'], $d['x'], $d['z'])['ganador_participante_id']);
        $this->assertNotNull($this->partido($d['liga'], $d['x'], $d['y'])['ganador_participante_id'], 'antes de migrar, el empate tiene un ganador falso');
        $actualizadoAntes = $this->partido($d['liga'], $d['x'], $d['y'])['updated_at'];

        $this->aplicarMigracion();

        foreach ([[$d['x'], $d['y']], [$d['y'], $d['z']]] as [$p, $q]) {
            $e = $this->partido($d['liga'], $p, $q);
            $this->assertSame([null, null, null, null], [$e['ganador_participante_id'], $e['ganador_equipo_id'], $e['perdedor_participante_id'], $e['perdedor_equipo_id']], 'enfrentamiento empatado');
            $r = $this->fila("SELECT * FROM resultados WHERE enfrentamiento_id = " . (int) $e['id']);
            $this->assertSame([null, null], [$r['ganador_participante_id'], $r['ganador_equipo_id']], 'resultado empatado');
        }
        $this->assertSame($actualizadoAntes, $this->partido($d['liga'], $d['x'], $d['y'])['updated_at'], 'no cambia updated_at');

        $vic = $this->partido($d['liga'], $d['x'], $d['z']);
        $this->assertSame([$d['x'], $d['z']], [(int) $vic['ganador_participante_id'], (int) $vic['perdedor_participante_id']], 'una victoria no se toca');

        $bye = $this->fila("SELECT * FROM enfrentamientos WHERE id = {$d['bye']}");
        $this->assertSame($d['byeLado'], (int) $bye['ganador_participante_id'], 'un bye conserva a quien pasa de ronda, aunque sus puntos coincidan con los del lado vacío');
        $this->assertSame($d['byeLado'], (int) $this->fila("SELECT ganador_participante_id FROM resultados WHERE enfrentamiento_id = {$d['bye']}")['ganador_participante_id']);
    }

    public function test_la_migracion_es_idempotente_y_solo_actualiza_datos(): void
    {
        $this->datosDeAntes();
        $this->aplicarMigracion();
        $foto = fn() => [
            $this->db->query("CHECKSUM TABLE enfrentamientos")->fetch(PDO::FETCH_ASSOC)['Checksum'],
            $this->db->query("CHECKSUM TABLE resultados")->fetch(PDO::FETCH_ASSOC)['Checksum'],
        ];
        $antes = $foto();
        $this->aplicarMigracion();
        $this->assertSame($antes, $foto(), 'la segunda vez no encuentra nada que cambiar');

        $sql = (string) file_get_contents(self::MIGRACION);
        $codigo = preg_replace('/--[^\n]*/', '', $sql);
        $this->assertSame(0, preg_match('/\b(DROP|ALTER|CREATE|DELETE|TRUNCATE|INSERT)\b/i', (string) $codigo), 'es solo UPDATE: no cambia el esquema ni borra nada');
    }
}

TestCase::correrSiEsScript(CampeonEmpatesTest::class);
