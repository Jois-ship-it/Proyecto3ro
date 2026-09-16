<?php
declare(strict_types=1);

/**
 * Test de integración de la integridad del bracket de Eliminación Directa.
 *
 * Ejercita EliminacionDirectaService (generarBracket + avanzarGanador) contra
 * una base de prueba y comprueba las invariantes estructurales del cuadro:
 *   - Cantidad de rondas y de partidos por ronda.
 *   - Cada inscrito aparece exactamente una vez en la primera ronda.
 *   - Nadie aparece dos veces en la misma ronda.
 *   - Los que pasan de ronda son exactamente los ganadores de la anterior.
 *   - Los byes avanzan solos cuando la cantidad no es potencia de 2.
 *   - El campeón es quien gana la final.
 *   - No se puede corregir un resultado cuyo ganador ya avanzó.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/bracket_integridad_test.php
 */

require __DIR__ . '/bootstrap.php';

final class BracketIntegridadTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    /** @return array[] filas de enfrentamientos de una ronda por número */
    private function partidosDeRonda(int $torneoId, int $numero): array
    {
        return $this->db->query(
            "SELECT e.* FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = $torneoId AND r.numero = $numero
             ORDER BY e.orden"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return int[] participantes que aparecen en una ronda (ambos lados) */
    private function participantesDeRonda(int $torneoId, int $numero): array
    {
        $ids = [];
        foreach ($this->partidosDeRonda($torneoId, $numero) as $e) {
            foreach (['participante_a_id', 'participante_b_id'] as $col) {
                if ($e[$col] !== null) $ids[] = (int)$e[$col];
            }
        }
        sort($ids);
        return $ids;
    }

    /** @return int[] ganadores de una ronda */
    private function ganadoresDeRonda(int $torneoId, int $numero): array
    {
        $ids = array_map(
            'intval',
            array_column(
                array_filter(
                    $this->partidosDeRonda($torneoId, $numero),
                    fn($e) => $e['ganador_participante_id'] !== null
                ),
                'ganador_participante_id'
            )
        );
        sort($ids);
        return $ids;
    }

    public function test_con_8_inscritos_la_primera_ronda_tiene_4_partidos_y_a_todos_una_sola_vez(): void
    {
        [$torneoId, $pids] = Fixtures::torneoArrancado('eliminacion_directa', 8);

        $ronda1 = $this->partidosDeRonda($torneoId, 1);
        $this->assertCount(4, $ronda1, 'ronda 1 de un cuadro de 8');

        foreach ($ronda1 as $e) {
            $this->assertSame(0, (int)$e['es_bye'], 'con 8 inscritos no hay byes');
            $this->assertNotNull($e['participante_a_id']);
            $this->assertNotNull($e['participante_b_id']);
        }

        $enRonda = $this->participantesDeRonda($torneoId, 1);
        $esperados = $pids;
        sort($esperados);
        $this->assertSame($esperados, $enRonda, 'están los 8 inscritos, cada uno una sola vez');
    }

    public function test_solo_los_ganadores_pasan_a_la_ronda_siguiente(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 8);

        $svc = new ResultadoService();
        foreach ($this->partidosDeRonda($torneoId, 1) as $e) {
            $svc->cargar((int)$e['id'], 2.0, 1.0, Fixtures::ADMIN);
        }

        $ganadores = $this->ganadoresDeRonda($torneoId, 1);
        $this->assertCount(4, $ganadores, 'la ronda 1 dejó 4 ganadores');

        $ronda2 = $this->partidosDeRonda($torneoId, 2);
        $this->assertCount(2, $ronda2, 'la ronda 2 tiene 2 partidos');
        $this->assertSame($ganadores, $this->participantesDeRonda($torneoId, 2),
            'en la ronda 2 están exactamente los 4 ganadores, sin repetidos ni colados');
    }

    public function test_el_cuadro_completo_deja_un_campeon_que_es_el_ganador_de_la_final(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 8);
        Fixtures::jugarTodo($torneoId);

        $rondas = (int) $this->db->query("SELECT COUNT(*) FROM rondas WHERE torneo_id = $torneoId")->fetchColumn();
        $this->assertSame(3, $rondas, 'un cuadro de 8 se juega en 3 rondas');

        $final = $this->partidosDeRonda($torneoId, 3);
        $this->assertCount(1, $final, 'la última ronda es un solo partido');

        $torneo = (new TorneoModel())->findById($torneoId);
        $this->assertSame('finalizado', $torneo['estado']);
        $this->assertSame(
            (int)$final[0]['ganador_participante_id'],
            (int)$torneo['campeon_participante_id'],
            'el campeón es quien ganó la final'
        );

        $partidos = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos WHERE torneo_id = $torneoId"
        )->fetchColumn();
        $this->assertSame(7, $partidos, 'un cuadro de 8 son 7 partidos en total');
    }

    public function test_con_12_inscritos_se_generan_byes_que_avanzan_solos(): void
    {
        // 12 → la potencia de 2 más cercana hacia arriba es 16 → 4 byes.
        [$torneoId, $pids] = Fixtures::torneoArrancado('eliminacion_directa', 12);

        $ronda1 = $this->partidosDeRonda($torneoId, 1);
        $this->assertCount(8, $ronda1, 'la ronda 1 de un cuadro de 16 son 8 llaves');

        $byes = array_filter($ronda1, fn($e) => (int)$e['es_bye'] === 1);
        $this->assertCount(4, $byes, '16 - 12 = 4 byes');

        $enRonda1 = $this->participantesDeRonda($torneoId, 1);
        $esperados = $pids;
        sort($esperados);
        $this->assertSame($esperados, $enRonda1, 'los 12 inscritos aparecen una sola vez');

        // Los que recibieron bye ya tienen lugar en la ronda 2 sin jugar.
        $conBye = array_map(fn($e) => (int)$e['participante_a_id'], $byes);
        sort($conBye);
        $enRonda2 = $this->participantesDeRonda($torneoId, 2);
        foreach ($conBye as $pid) {
            $this->assertTrue(in_array($pid, $enRonda2, true), "el bye $pid avanzó solo a la ronda 2");
        }
    }

    public function test_el_cuadro_con_byes_tambien_llega_a_un_campeon(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 12);
        Fixtures::jugarTodo($torneoId);

        $torneo = (new TorneoModel())->findById($torneoId);
        $this->assertSame('finalizado', $torneo['estado']);
        $this->assertNotNull($torneo['campeon_participante_id']);

        // Nadie puede aparecer dos veces en una misma ronda.
        foreach ([1, 2, 3, 4] as $numero) {
            $ids = $this->participantesDeRonda($torneoId, $numero);
            $this->assertSame(count($ids), count(array_unique($ids)),
                "ronda $numero sin participantes repetidos");
        }
    }

    public function test_eliminacion_directa_no_acepta_empates(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $partido = Fixtures::partidosPendientes($torneoId)[0];

        $this->assertThrows(
            fn() => (new ResultadoService())->cargar($partido, 1.0, 1.0, Fixtures::ADMIN),
            'empates no están permitidos'
        );
    }

    public function test_no_se_corrige_un_resultado_cuyo_ganador_ya_avanzo(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 4);

        $ronda1 = $this->partidosDeRonda($torneoId, 1);
        (new ResultadoService())->cargar((int)$ronda1[0]['id'], 2.0, 1.0, Fixtures::ADMIN);

        $elim = new EliminacionDirectaService();
        $this->assertFalse(
            $elim->puedeCorregir((int)$ronda1[0]['id'], $torneoId),
            'el ganador ya figura en la ronda siguiente'
        );

        $this->assertThrows(
            fn() => (new ResultadoService())->corregir(
                (int)$ronda1[0]['id'], 0.0, 3.0, 'El acta tenía el marcador invertido', Fixtures::ADMIN
            ),
            'ya generó una ronda posterior',
            'corregir debe rechazarse cuando el bracket ya avanzó'
        );
    }

    public function test_la_final_si_se_puede_corregir_porque_nadie_avanza_despues(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        Fixtures::jugarTodo($torneoId);

        $final = $this->partidosDeRonda($torneoId, 2)[0];
        $this->assertTrue(
            (new EliminacionDirectaService())->puedeCorregir((int)$final['id'], $torneoId),
            'de la final no avanza nadie, así que su resultado sigue siendo corregible'
        );
    }
}

exit(TestCase::ejecutar(BracketIntegridadTest::class));
