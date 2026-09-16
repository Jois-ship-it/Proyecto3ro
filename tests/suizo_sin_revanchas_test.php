<?php
declare(strict_types=1);

/**
 * Test de integración del Sistema Suizo.
 *
 * Todo se comprueba contra SistemaSuizoService real y la base de prueba.
 *
 * Cubre:
 *   - Ningún par se repite mientras exista un emparejamiento alternativo
 *     (las rondas «Desempate N» quedan fuera: ahí la revancha es intencional).
 *   - El mismo control sobre los 12 torneos de database/seed_demo.php.
 *   - No se genera la ronda siguiente con partidos pendientes.
 *   - No se generan más rondas que las configuradas.
 *   - La primera ronda no se puede generar dos veces.
 *   - Nadie recibe dos byes mientras haya inscritos sin ninguno.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/suizo_sin_revanchas_test.php
 */

require __DIR__ . '/bootstrap.php';

final class SuizoTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    /**
     * Pares que se repiten en un torneo, excluyendo byes y rondas de desempate.
     *
     * @return array<string,int[]> "menor-mayor" => números de ronda
     */
    private function revanchas(int $torneoId, bool $esEquipos = false): array
    {
        $colA = $esEquipos ? 'equipo_a_id' : 'participante_a_id';
        $colB = $esEquipos ? 'equipo_b_id' : 'participante_b_id';

        $enfs = $this->db->query(
            "SELECT r.numero AS ronda, e.$colA AS a, e.$colB AS b
             FROM enfrentamientos e
             JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = $torneoId
               AND e.es_bye = 0
               AND r.nombre NOT LIKE 'Desempate%'
               AND e.$colA IS NOT NULL AND e.$colB IS NOT NULL
             ORDER BY r.numero, e.orden"
        )->fetchAll(PDO::FETCH_ASSOC);

        $vistos = [];
        $repes  = [];
        foreach ($enfs as $e) {
            $a = (int)$e['a'];
            $b = (int)$e['b'];
            $clave = min($a, $b) . '-' . max($a, $b);
            if (isset($vistos[$clave])) {
                $repes[$clave] = array_merge($vistos[$clave], [(int)$e['ronda']]);
            }
            $vistos[$clave][] = (int)$e['ronda'];
        }
        return $repes;
    }

    private function describir(array $revanchas): string
    {
        $partes = [];
        foreach ($revanchas as $par => $rondas) {
            $partes[] = str_replace('-', ' vs ', $par) . ' en rondas ' . implode(', ', $rondas);
        }
        return implode('; ', $partes);
    }

    /** Juega un suizo completo generando cada ronda tras cerrar la anterior. */
    private function jugarSuizoCompleto(int $torneoId, int $rondas): void
    {
        $res = new ResultadoService();
        for ($n = 1; $n <= $rondas; $n++) {
            foreach (Fixtures::partidosPendientes($torneoId, $n) as $i => $eid) {
                $res->cargar($eid, (float)(3 - ($i % 3)), (float)($i % 3), Fixtures::ADMIN);
            }
            if ($n < $rondas) {
                (new SistemaSuizoService())->generarSiguienteRonda($torneoId);
            }
        }
    }

    // ─── No-revancha ────────────────────────────────────────────────────────

    public function test_con_4_jugadores_y_3_rondas_se_juegan_los_6_cruces_sin_repetir(): void
    {
        // K4 se descompone exactamente en 3 emparejamientos perfectos disjuntos:
        // un suizo de 4 en 3 rondas TIENE que poder jugarse sin ninguna revancha.
        [$torneoId] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);
        $this->jugarSuizoCompleto($torneoId, 3);

        $revanchas = $this->revanchas($torneoId);
        $this->assertCount(0, $revanchas, 'revanchas evitables: ' . $this->describir($revanchas));

        $partidos = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = $torneoId AND e.es_bye = 0 AND r.nombre NOT LIKE 'Desempate%'"
        )->fetchColumn();
        $this->assertSame(6, $partidos, 'se jugaron los 6 cruces posibles');
    }

    public function test_con_11_jugadores_y_5_rondas_no_hay_revanchas(): void
    {
        // Es la configuración del torneo 6 de seed_demo.php, donde el barrido
        // codicioso anterior forzaba 11 vs 16 y 10 vs 15 por segunda vez.
        [$torneoId] = Fixtures::torneoArrancado('suizo', 11, ['rondas_suizo' => 5]);
        $this->jugarSuizoCompleto($torneoId, 5);

        $revanchas = $this->revanchas($torneoId);
        $this->assertCount(0, $revanchas, 'revanchas evitables: ' . $this->describir($revanchas));
    }

    public function test_ningun_torneo_suizo_de_seed_demo_repite_emparejamientos(): void
    {
        // Corre el seed completo (12 torneos simulados) y audita los suizos.
        ob_start();
        require __DIR__ . '/../database/seed_demo.php';
        $salida = (string) ob_get_clean();
        $this->assertStringContainsString('Datos de demostración regenerados', $salida,
            'el seed tiene que terminar bien para que la auditoría valga');

        $torneos = $this->db->query(
            "SELECT t.id, t.nombre, t.modalidad
             FROM torneos t JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             WHERE tt.slug = 'suizo' ORDER BY t.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertGreaterThan(0, count($torneos), 'el seed tiene que dejar torneos suizos');

        foreach ($torneos as $t) {
            $revanchas = $this->revanchas((int)$t['id'], $t['modalidad'] === 'equipos');
            $this->assertCount(0, $revanchas,
                "torneo #{$t['id']} «{$t['nombre']}»: " . $this->describir($revanchas));
        }
    }

    // ─── Byes ───────────────────────────────────────────────────────────────

    public function test_nadie_recibe_dos_byes_mientras_haya_inscritos_sin_ninguno(): void
    {
        // 11 inscritos y 5 rondas: 5 byes a repartir entre 11, todos distintos.
        [$torneoId] = Fixtures::torneoArrancado('suizo', 11, ['rondas_suizo' => 5]);
        $this->jugarSuizoCompleto($torneoId, 5);

        $byes = $this->db->query(
            "SELECT participante_a_id AS id, COUNT(*) AS n
             FROM enfrentamientos
             WHERE torneo_id = $torneoId AND es_bye = 1
             GROUP BY participante_a_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(5, array_sum(array_map('intval', $byes)), 'cinco rondas impares, cinco byes');
        $repetidos = array_filter($byes, fn($n) => (int)$n > 1);
        $this->assertCount(0, $repetidos, 'ninguno recibió dos byes habiendo inscritos sin bye');
    }

    // ─── Control de rondas ──────────────────────────────────────────────────

    public function test_no_se_genera_la_ronda_siguiente_con_partidos_pendientes(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('suizo', 6, ['rondas_suizo' => 3]);

        $pendientes = Fixtures::partidosPendientes($torneoId, 1);
        $this->assertCount(3, $pendientes, 'la ronda 1 de 6 jugadores son 3 partidos');

        // Se carga solo uno de los tres.
        (new ResultadoService())->cargar($pendientes[0], 2.0, 1.0, Fixtures::ADMIN);

        $this->assertThrows(
            fn() => (new SistemaSuizoService())->generarSiguienteRonda($torneoId),
            'hay partidos pendientes'
        );
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM rondas WHERE torneo_id = $torneoId"
        )->fetchColumn(), 'no se creó ninguna ronda nueva');

        // Completando la ronda sí se puede avanzar.
        foreach (array_slice($pendientes, 1) as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }
        $this->assertDoesNotThrow(
            fn() => (new SistemaSuizoService())->generarSiguienteRonda($torneoId),
            'con la ronda completa la siguiente se genera'
        );
    }

    public function test_no_se_generan_mas_rondas_que_las_configuradas(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 2]);
        $this->jugarSuizoCompleto($torneoId, 2);

        $this->assertThrows(
            fn() => (new SistemaSuizoService())->generarSiguienteRonda($torneoId),
            'todas las rondas'
        );
    }

    public function test_la_primera_ronda_no_se_puede_generar_dos_veces(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);

        $this->assertThrows(
            fn() => (new SistemaSuizoService())->generarPrimeraRonda($torneoId),
            'Ya existe una ronda generada'
        );
    }

    public function test_se_necesitan_al_menos_dos_inscritos(): void
    {
        $pids     = Fixtures::participantes(1);
        $torneoId = Fixtures::torneo('suizo', ['rondas_suizo' => 3]);
        Fixtures::inscribir($torneoId, $pids);

        $this->assertThrows(
            fn() => (new SistemaSuizoService())->generarPrimeraRonda($torneoId),
            'al menos 2 inscritos'
        );
    }
}

exit(TestCase::ejecutar(SuizoTest::class));
