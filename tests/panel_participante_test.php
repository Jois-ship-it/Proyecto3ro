<?php
declare(strict_types=1);

/**
 * Test de los datos de «Mi panel» del participante (PanelParticipanteService).
 *
 * Qué pasaba antes (B6): el panel prometía calendario, resultados y posición y solo traía la lista
 * de torneos (letra §5.3: «el participante consulta sus torneos, calendario, resultados y
 * posición»). Ahora trae sus próximos partidos, sus últimos resultados y cómo va en cada torneo.
 *
 * Qué se comprueba:
 *   - Próximos: los partidos que le faltan en torneos en curso, con la fecha primero; sin los de
 *     otros, sin el bye, sin los de un torneo que no está en curso, y con tope.
 *   - Resultados: se ven desde su lado (sea el A o el B), con victoria/empate/derrota decidida por
 *     los puntos, y del más reciente al más viejo.
 *   - Posición: su lugar en la tabla de una liga o suizo, que coincide con la tabla pública; en una
 *     llave, si sigue en carrera, es campeón o en qué ronda quedó afuera.
 *   - Equipos: en un torneo por equipos juega su equipo, y el panel lo dice.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/panel_participante_test.php
 */

require __DIR__ . '/bootstrap.php';

class PanelParticipanteTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
    }

    // ── apoyo ──────────────────────────────────────────────────────────

    private function panel(int $participanteId): array
    {
        return (new PanelParticipanteService())->panel($participanteId);
    }

    /** Los partidos (con ambos lados) de un participante en un torneo, por ronda. */
    private function partidosDe(int $torneoId, int $pid): array
    {
        $st = $this->db->prepare(
            "SELECT e.*, r.numero AS ronda_numero FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = :t AND e.es_bye = 0 AND (e.participante_a_id = :p1 OR e.participante_b_id = :p2)
             ORDER BY r.numero, e.orden"
        );
        $st->execute([':t' => $torneoId, ':p1' => $pid, ':p2' => $pid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nombre(int $pid): string
    {
        return (string) $this->db->query("SELECT nombre FROM participantes WHERE id = {$pid}")->fetchColumn();
    }

    /** Carga el resultado de un partido poniendo `$mios` para `$pid` y `$suyos` para el rival, en el lado que le toque. */
    private function cargarDesdeSuLado(array $partido, int $pid, float $mios, float $suyos): void
    {
        $esA = (int) $partido['participante_a_id'] === $pid;
        (new ResultadoService())->cargar((int) $partido['id'], $esA ? $mios : $suyos, $esA ? $suyos : $mios, Fixtures::ADMIN);
    }

    // ── el panel vacío ─────────────────────────────────────────────────

    public function test_sin_actividad_el_panel_esta_vacio(): void
    {
        [$sinNada] = Fixtures::participantes(1, 'Solo');

        $this->assertSame(['proximos' => [], 'recientes' => [], 'competencias' => []], $this->panel($sinNada));
    }

    public function test_un_torneo_en_inscripcion_todavia_no_aparece(): void
    {
        $pids = Fixtures::participantes(4);
        $tid  = Fixtures::torneo('liga');
        Fixtures::inscribir($tid, $pids);          // sin generar el fixture: nadie juega todavía

        $this->assertSame(['proximos' => [], 'recientes' => [], 'competencias' => []], $this->panel($pids[0]));
    }

    // ── próximos partidos ──────────────────────────────────────────────

    public function test_los_proximos_son_los_que_le_faltan_y_se_ordenan_por_ronda(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $yo = $p[0];

        $proximos = $this->panel($yo)['proximos'];

        $this->assertCount(3, $proximos, 'en una liga de 4 juega 3 partidos');
        $esperados = $this->partidosDe($tid, $yo);
        foreach ($proximos as $i => $x) {
            $partido = $esperados[$i];
            $rivalId = (int) $partido['participante_a_id'] === $yo ? (int) $partido['participante_b_id'] : (int) $partido['participante_a_id'];
            $this->assertSame($this->nombre($rivalId), $x['rival'], 'el rival, sea el lado que sea');
            $this->assertSame($tid, $x['torneo_id']);
            $this->assertSame(null, $x['como'], 'juega solo');
            $this->assertSame(null, $x['fecha'], 'sin fecha programada');
        }
        $rondas = array_column($proximos, 'ronda');
        $this->assertSame(['Fecha 1', 'Fecha 2', 'Fecha 3'], $rondas, 'por ronda');
    }

    public function test_los_partidos_de_otros_no_son_los_suyos(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);

        $proximos = $this->panel($p[0])['proximos'];

        foreach ($proximos as $x) {
            $this->assertTrue($x['rival'] !== $this->nombre($p[0]), 'el rival nunca es él mismo');
        }
        $rivales = array_column($proximos, 'rival');
        sort($rivales);
        $otros = [$this->nombre($p[1]), $this->nombre($p[2]), $this->nombre($p[3])];
        sort($otros);
        $this->assertSame($otros, $rivales, 'juega una vez contra cada uno de los otros tres');
    }

    public function test_un_partido_con_fecha_va_primero(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $ultimo = $this->partidosDe($tid, $p[0])[2];                       // el de la última ronda
        (new EnfrentamientoModel())->programar((int) $ultimo['id'], '2030-05-01 18:30:00');

        $proximos = $this->panel($p[0])['proximos'];

        $this->assertSame('Fecha 3', $proximos[0]['ronda'], 'el que tiene fecha pasa adelante');
        $this->assertSame('2030-05-01 18:30:00', $proximos[0]['fecha']);
        $this->assertSame(null, $proximos[1]['fecha']);
    }

    public function test_lo_jugado_deja_de_ser_proximo(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $this->cargarDesdeSuLado($this->partidosDe($tid, $p[0])[0], $p[0], 2.0, 1.0);

        $panel = $this->panel($p[0]);

        $this->assertSame(['Fecha 2', 'Fecha 3'], array_column($panel['proximos'], 'ronda'));
        $this->assertCount(1, $panel['recientes']);
    }

    public function test_el_bye_no_es_un_partido(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('suizo', 3);                // con 3, uno descansa en cada ronda (bye)
        $conBye = (int) $this->db->query("SELECT participante_a_id FROM enfrentamientos WHERE torneo_id = {$tid} AND es_bye = 1 LIMIT 1")->fetchColumn();
        $this->assertTrue($conBye !== 0, 'preparación: alguien recibió un bye');

        $panel = $this->panel($conBye);
        $this->assertSame([], $panel['proximos'], 'el bye no es un partido por jugar');
        $this->assertSame([], $panel['recientes'], 'ni un resultado');
        $this->assertCount(1, $panel['competencias'], 'pero el torneo sí figura');

        Fixtures::jugarTodo($tid, null);                                    // se juega la ronda (el bye ya viene resuelto)
        $this->assertSame([], $this->panel($conBye)['recientes'], 'jugada la ronda, quien descansó sigue sin resultados');
        $otros = array_values(array_diff($p, [$conBye]));
        $this->assertCount(1, $this->panel($otros[0])['recientes'], 'quien jugó, uno');
    }

    public function test_un_torneo_que_no_esta_en_curso_no_tiene_proximos(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $this->db->exec("UPDATE torneos SET estado = 'cancelado' WHERE id = {$tid}");

        $this->assertSame([], $this->panel($p[0])['proximos'], 'un torneo cancelado no tiene partidos por jugar');
        $this->assertSame([], $this->panel($p[0])['competencias'], 'ni se cuenta entre sus torneos del panel');
    }

    public function test_se_muestran_como_mucho_cinco(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 8);                 // juega 7 partidos

        $this->assertCount(5, $this->panel($p[0])['proximos'], 'de 7 pendientes, 5');

        Fixtures::jugarTodo($tid);
        $this->assertCount(5, $this->panel($p[0])['recientes'], 'de 7 jugados, 5');
    }

    // ── resultados ─────────────────────────────────────────────────────

    public function test_el_resultado_se_ve_desde_su_lado_sea_el_a_o_el_b(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $yo = $p[0];

        // Se juegan sus tres partidos: gana el primero 3-1, pierde el segundo 0-2 y gana el tercero 5-4.
        $partidos = $this->partidosDe($tid, $yo);
        $this->cargarDesdeSuLado($partidos[0], $yo, 3.0, 1.0);
        $this->cargarDesdeSuLado($partidos[1], $yo, 0.0, 2.0);
        $this->cargarDesdeSuLado($partidos[2], $yo, 5.0, 4.0);

        $recientes = $this->panel($yo)['recientes'];

        // El más reciente primero: el de la última ronda.
        $this->assertSame(
            [[5.0, 4.0, 'victoria'], [0.0, 2.0, 'derrota'], [3.0, 1.0, 'victoria']],
            array_map(fn($r) => [$r['mios'], $r['rival_puntos'], $r['resultado']], $recientes)
        );
        $this->assertSame(['Fecha 3', 'Fecha 2', 'Fecha 1'], array_column($recientes, 'ronda'));
    }

    public function test_los_dos_lados_dan_el_mismo_resultado_visto_desde_cada_uno(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $a = $p[0];
        $partido = $this->partidosDe($tid, $a)[0];
        $b = (int) $partido['participante_a_id'] === $a ? (int) $partido['participante_b_id'] : (int) $partido['participante_a_id'];
        $this->cargarDesdeSuLado($partido, $a, 4.0, 2.0);

        $deA = $this->panel($a)['recientes'][0];
        $deB = $this->panel($b)['recientes'][0];

        $this->assertSame([4.0, 2.0, 'victoria'], [$deA['mios'], $deA['rival_puntos'], $deA['resultado']]);
        $this->assertSame([2.0, 4.0, 'derrota'], [$deB['mios'], $deB['rival_puntos'], $deB['resultado']]);
        $this->assertSame($this->nombre($b), $deA['rival']);
        $this->assertSame($this->nombre($a), $deB['rival']);
    }

    public function test_un_empate_es_un_empate_y_no_tiene_ganador(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4, ['permite_empates' => '1']);
        $partido = $this->partidosDe($tid, $p[0])[0];
        $this->cargarDesdeSuLado($partido, $p[0], 2.0, 2.0);

        $r = $this->panel($p[0])['recientes'][0];

        $this->assertSame([2.0, 2.0, 'empate'], [$r['mios'], $r['rival_puntos'], $r['resultado']]);
    }

    public function test_un_resultado_corregido_se_ve_corregido(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $partido = $this->partidosDe($tid, $p[0])[0];
        $this->cargarDesdeSuLado($partido, $p[0], 3.0, 1.0);
        $esA = (int) $partido['participante_a_id'] === $p[0];
        (new ResultadoService())->corregir((int) $partido['id'], $esA ? 0.0 : 2.0, $esA ? 2.0 : 0.0, 'Error de planilla en el acta', Fixtures::ADMIN);

        $r = $this->panel($p[0])['recientes'][0];

        $this->assertSame([0.0, 2.0, 'derrota'], [$r['mios'], $r['rival_puntos'], $r['resultado']], 'vale el resultado corregido');
    }

    // ── posición en una liga ───────────────────────────────────────────

    public function test_antes_de_jugar_no_hay_posicion_que_mostrar(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);

        $c = $this->panel($p[0])['competencias'];

        $this->assertCount(1, $c);
        $this->assertSame([$tid, 'liga', 'en_curso', null, null, null], [$c[0]['torneo_id'], $c[0]['formato'], $c[0]['estado'], $c[0]['posicion'], $c[0]['total'], $c[0]['llave']]);
    }

    public function test_la_posicion_coincide_con_la_tabla_publica(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        // El participante 0 gana todo, el 1 gana lo demás, etc.: se juega con marcador según los ids.
        Fixtures::jugarTodo($tid, fn(int $enf, int $i) => [(float) (($i % 3) + 1), 0.0]);

        $tabla = (new TablaPosicionesService())->getByTorneo($tid);
        foreach ($p as $yo) {
            $c = $this->panel($yo)['competencias'][0];
            $fila = null;
            foreach ($tabla as $n => $f) {
                if ((int) $f['participante_id'] === $yo) $fila = [$n + 1, $f];
            }
            $this->assertSame($fila[0], $c['posicion'], 'el lugar es el de la tabla');
            $this->assertSame(4, $c['total']);
            $this->assertSame((int) $fila[1]['puntos'], $c['puntos']);
            $this->assertSame((int) $fila[1]['pj'], $c['pj']);
            $this->assertSame(null, $c['llave']);
        }
    }

    public function test_un_torneo_finalizado_sigue_en_el_panel_con_su_posicion(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        Fixtures::jugarTodo($tid);
        $this->assertSame('finalizado', (string) $this->db->query("SELECT estado FROM torneos WHERE id = {$tid}")->fetchColumn(), 'preparación');

        $campeon = (int) (new TorneoModel())->findByIdCompleto($tid)['campeon_participante_id'];

        $c = $this->panel($campeon)['competencias'][0];
        $this->assertSame('finalizado', $c['estado']);
        $this->assertSame(1, $c['posicion'], 'el campeón es el primero de la tabla');
        $this->assertSame('finalizado', $this->panel($p[0])['competencias'][0]['estado'], 'y los demás también ven el torneo terminado');
    }

    public function test_primero_los_torneos_en_curso(): void
    {
        [$viejo, $p] = Fixtures::torneoArrancado('liga', 4);
        Fixtures::jugarTodo($viejo);                                            // finalizado
        $nuevo = Fixtures::torneo('liga', ['nombre' => 'Otro torneo']);
        Fixtures::inscribir($nuevo, $p);
        (new LigaService())->generarFixture($nuevo);                           // en curso, y con id mayor

        $ids = array_column($this->panel($p[0])['competencias'], 'torneo_id');

        $this->assertSame([$nuevo, $viejo], $ids);
        $this->assertSame('en_curso', $this->panel($p[0])['competencias'][0]['estado']);
    }

    public function test_una_inscripcion_retirada_no_cuenta(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('liga', 4);
        $this->db->exec("UPDATE inscripciones SET estado = 'retirada' WHERE torneo_id = {$tid} AND participante_id = {$p[0]}");

        $this->assertSame([], $this->panel($p[0])['competencias']);
    }

    // ── posición en una llave ──────────────────────────────────────────

    public function test_en_una_llave_se_dice_si_sigue_en_carrera(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('eliminacion_directa', 4);

        foreach ($p as $yo) {
            $c = $this->panel($yo)['competencias'][0];
            $this->assertSame(['eliminacion_directa', 'en_carrera', null, null], [$c['formato'], $c['llave'], $c['posicion'], $c['ronda_fuera']]);
        }
    }

    public function test_en_una_llave_se_dice_donde_quedo_afuera_y_quien_es_campeon(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($tid);                                              // siempre gana el lado A

        $torneo = (new TorneoModel())->findByIdCompleto($tid);
        $campeon = (int) $torneo['campeon_participante_id'];
        $this->assertTrue($campeon !== 0, 'preparación: hay campeón');

        $llaves = [];
        foreach ($p as $yo) {
            $c = $this->panel($yo)['competencias'][0];
            $llaves[$yo] = [$c['llave'], $c['ronda_fuera']];
        }
        $this->assertSame(['campeon', null], $llaves[$campeon]);
        $this->assertSame(1, count(array_filter($llaves, fn($l) => $l[0] === 'campeon')), 'uno solo es campeón');
        $this->assertSame(3, count(array_filter($llaves, fn($l) => $l[0] === 'eliminado')), 'los otros tres quedaron afuera');
        $rondasFuera = array_column(array_filter($llaves, fn($l) => $l[0] === 'eliminado'), 1);
        sort($rondasFuera);
        $this->assertSame(['Final', 'Semifinales', 'Semifinales'], $rondasFuera, 'dos en semifinales y el otro en la final');
    }

    public function test_quien_pasa_de_ronda_sigue_en_carrera(): void
    {
        [$tid, $p] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $semis = Fixtures::partidosPendientes($tid, 1);
        (new ResultadoService())->cargar($semis[0], 2.0, 1.0, Fixtures::ADMIN);     // gana el lado A de la primera semifinal

        $ganador = (int) $this->db->query("SELECT participante_a_id FROM enfrentamientos WHERE id = {$semis[0]}")->fetchColumn();
        $perdedor = (int) $this->db->query("SELECT participante_b_id FROM enfrentamientos WHERE id = {$semis[0]}")->fetchColumn();

        $this->assertSame('en_carrera', $this->panel($ganador)['competencias'][0]['llave'], 'ganó y espera la final');
        $c = $this->panel($perdedor)['competencias'][0];
        $this->assertSame(['eliminado', 'Semifinales'], [$c['llave'], $c['ronda_fuera']]);
    }

    // ── equipos ────────────────────────────────────────────────────────

    /** Una liga por equipos arrancada. @return array{0:int,1:int[],2:int[]} [torneo, participantes, equipos] */
    private function ligaDeEquipos(): array
    {
        $pids = Fixtures::participantes(12);
        $eids = Fixtures::equipos(4, $pids);                   // 3 integrantes por equipo: pids 0-2, 3-5, 6-8, 9-11
        $tid  = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'nombre' => 'Liga de equipos']);
        Fixtures::inscribir($tid, $eids, 'equipos');
        (new LigaService())->generarFixture($tid);
        return [$tid, $pids, $eids];
    }

    private function nombreEquipo(int $eid): string
    {
        return (string) $this->db->query("SELECT nombre FROM equipos WHERE id = {$eid}")->fetchColumn();
    }

    public function test_en_un_torneo_por_equipos_juega_su_equipo(): void
    {
        [$tid, $pids, $eids] = $this->ligaDeEquipos();
        $yo = $pids[0];                                          // integrante del equipo 1

        $panel = $this->panel($yo);

        $this->assertCount(3, $panel['proximos'], 'su equipo juega 3 partidos');
        foreach ($panel['proximos'] as $x) {
            $this->assertSame($this->nombreEquipo($eids[0]), $x['como'], 'el panel dice con qué equipo juega');
            $this->assertTrue($x['rival'] !== $this->nombreEquipo($eids[0]));
            $this->assertStringContainsString('Equipo', $x['rival']);
        }
        $c = $panel['competencias'][0];
        $this->assertSame([$tid, $this->nombreEquipo($eids[0])], [$c['torneo_id'], $c['como']]);
    }

    public function test_los_dos_lados_de_un_partido_de_equipos_se_ven_con_su_equipo(): void
    {
        [$tid, $pids, $eids] = $this->ligaDeEquipos();
        // Un partido cualquiera: gana el lado A 3-1, y se mira desde un integrante del equipo del lado B.
        $st = $this->db->prepare("SELECT * FROM enfrentamientos WHERE torneo_id = ? AND es_bye = 0 AND equipo_b_id IS NOT NULL ORDER BY id LIMIT 1");
        $st->execute([$tid]);
        $partido = $st->fetch(PDO::FETCH_ASSOC);
        $ladoB = (int) $partido['equipo_b_id'];
        (new ResultadoService())->cargar((int) $partido['id'], 3.0, 1.0, Fixtures::ADMIN);
        $integranteB = (int) $this->db->query("SELECT participante_id FROM equipo_participantes WHERE equipo_id = {$ladoB} LIMIT 1")->fetchColumn();

        $r = $this->panel($integranteB)['recientes'][0];

        $this->assertSame($this->nombreEquipo($ladoB), $r['como'], 'si su equipo es el lado B, igual se dice con quién juega');
        $this->assertSame([1.0, 3.0, 'derrota'], [$r['mios'], $r['rival_puntos'], $r['resultado']]);
    }

    public function test_quien_no_es_del_equipo_no_ve_sus_partidos(): void
    {
        [$tid, $pids, $eids] = $this->ligaDeEquipos();
        [$ajeno] = Fixtures::participantes(1, 'Ajeno');

        $this->assertSame(['proximos' => [], 'recientes' => [], 'competencias' => []], $this->panel($ajeno));
    }

    public function test_si_deja_el_equipo_deja_de_verlo(): void
    {
        [$tid, $pids, $eids] = $this->ligaDeEquipos();
        $this->assertCount(3, $this->panel($pids[0])['proximos']);

        (new EquipoModel())->quitarParticipante($eids[0], $pids[0]);

        $this->assertSame(['proximos' => [], 'recientes' => [], 'competencias' => []], $this->panel($pids[0]));
    }

    public function test_un_participante_en_dos_torneos_los_ve_a_los_dos(): void
    {
        [$tid1, $p] = Fixtures::torneoArrancado('liga', 4);
        $tid2 = Fixtures::torneo('eliminacion_directa', ['nombre' => 'Copa']);
        Fixtures::inscribir($tid2, $p);
        (new EliminacionDirectaService())->generarBracket($tid2);

        $panel = $this->panel($p[0]);

        $this->assertSame([$tid1, $tid2], array_values(array_unique(array_column($panel['proximos'], 'torneo_id'))), 'la liga (3 fechas) y la copa (1 partido)');
        $this->assertCount(2, $panel['competencias']);
    }
}

TestCase::correrSiEsScript(PanelParticipanteTest::class);
