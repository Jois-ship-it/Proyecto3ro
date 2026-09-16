<?php
declare(strict_types=1);

/**
 * Test de integración del estado de las rondas.
 *
 * Hasta ahora `rondas.estado` era metadata muerta: se escribía al crear la ronda
 * y nadie lo volvía a tocar, así que quedaban torneos finalizados con rondas en
 * 'pendiente'. Acá se comprueba que el estado sigue a la realidad y que el
 * cerrar/reabrir manual del organizador tiene efecto.
 *
 * Cubre:
 *   - Ningún torneo finalizado queda con rondas sin cerrar (incluido seed_demo).
 *   - Estado al generar la estructura de cada formato.
 *   - Cierre automático al resolverse el último partido de la ronda.
 *   - Cierre y reapertura manual: bloquean y rehabilitan la carga.
 *   - La sincronización automática no deshace un cierre manual.
 *   - Ambas acciones quedan auditadas.
 *   - 'bloqueada' ya no existe en el ENUM.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/rondas_estado_test.php
 */

require __DIR__ . '/bootstrap.php';

final class RondasEstadoTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    /** @return array<int,string> numero de ronda => estado */
    private function estadosPorRonda(int $torneoId): array
    {
        $filas = $this->db->query(
            "SELECT numero, estado FROM rondas WHERE torneo_id = $torneoId ORDER BY numero"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('strval', $filas);
    }

    private function estadoDeRonda(int $torneoId, int $numero): string
    {
        return (string) $this->db->query(
            "SELECT estado FROM rondas WHERE torneo_id = $torneoId AND numero = $numero"
        )->fetchColumn();
    }

    private function idDeRonda(int $torneoId, int $numero): int
    {
        return (int) $this->db->query(
            "SELECT id FROM rondas WHERE torneo_id = $torneoId AND numero = $numero"
        )->fetchColumn();
    }

    /** Rondas sin cerrar de torneos que ya están finalizados. */
    private function rondasAbiertasEnTorneosFinalizados(): array
    {
        return $this->db->query(
            "SELECT t.id AS torneo, t.nombre, r.numero, r.nombre AS ronda, r.estado
             FROM rondas r
             JOIN torneos t ON t.id = r.torneo_id
             WHERE t.estado = 'finalizado' AND r.estado <> 'cerrada'
             ORDER BY t.id, r.numero"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── El ENUM ────────────────────────────────────────────────────────────

    public function test_el_enum_ya_no_tiene_el_estado_bloqueada(): void
    {
        $tipo = (string) $this->db->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'rondas' AND column_name = 'estado'"
        )->fetchColumn();

        $this->assertStringNotContainsString('bloqueada', $tipo,
            'la migración 2026_09_rondas_estado.sql saca el valor duplicado');
        foreach (['pendiente', 'en_curso', 'cerrada'] as $valor) {
            $this->assertStringContainsString($valor, $tipo);
        }
    }

    // ─── Estado al generar la estructura ────────────────────────────────────

    public function test_la_liga_deja_todas_sus_fechas_en_curso_al_generar_el_fixture(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);

        $estados = $this->estadosPorRonda($torneoId);
        $this->assertCount(3, $estados, 'una liga de 4 son 3 fechas');
        foreach ($estados as $numero => $estado) {
            $this->assertSame('en_curso', $estado, "fecha $numero");
        }
    }

    public function test_el_suizo_deja_la_ronda_1_en_curso(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);
        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 1));
    }

    public function test_en_el_bracket_la_ronda_siguiente_esta_pendiente_hasta_tener_sus_dos_lados(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 1), 'la ronda 1 es jugable');

        // Se resuelve solo una de las dos llaves: la final queda con un solo lado.
        $pendientes = Fixtures::partidosPendientes($torneoId, 1);
        (new ResultadoService())->cargar($pendientes[0], 2.0, 1.0, Fixtures::ADMIN);

        $this->assertSame('pendiente', $this->estadoDeRonda($torneoId, 2),
            'la final todavía no se puede jugar: le falta el otro finalista');

        // Al resolverse la segunda llave, la final queda armada.
        (new ResultadoService())->cargar($pendientes[1], 2.0, 1.0, Fixtures::ADMIN);

        $this->assertSame('cerrada', $this->estadoDeRonda($torneoId, 1), 'la ronda 1 terminó');
        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 2), 'la final ya es jugable');
    }

    // ─── Cierre automático ──────────────────────────────────────────────────

    public function test_la_ronda_se_cierra_sola_cuando_se_resuelve_su_ultimo_partido(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);

        $fecha1 = Fixtures::partidosPendientes($torneoId, 1);
        $this->assertCount(2, $fecha1);

        (new ResultadoService())->cargar($fecha1[0], 2.0, 1.0, Fixtures::ADMIN);
        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 1),
            'con un partido pendiente la fecha sigue abierta');

        (new ResultadoService())->cargar($fecha1[1], 2.0, 1.0, Fixtures::ADMIN);
        $this->assertSame('cerrada', $this->estadoDeRonda($torneoId, 1),
            'resuelto el último partido, la fecha se cierra sola');
        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 2),
            'las demás fechas no se tocan');
    }

    public function test_ningun_torneo_finalizado_queda_con_rondas_sin_cerrar(): void
    {
        foreach (['liga', 'eliminacion_directa', 'suizo'] as $slug) {
            [$torneoId] = Fixtures::torneoArrancado($slug, 4, ['rondas_suizo' => 2]);

            if ($slug === 'suizo') {
                // El suizo necesita que alguien genere cada ronda siguiente.
                foreach ([1, 2] as $n) {
                    foreach (Fixtures::partidosPendientes($torneoId, $n) as $eid) {
                        (new ResultadoService())->cargar($eid, 3.0, 1.0, Fixtures::ADMIN);
                    }
                    if ($n === 1) (new SistemaSuizoService())->generarSiguienteRonda($torneoId);
                }
                // Puede haber quedado un desempate abierto: se juega hasta cerrar.
                Fixtures::jugarTodo($torneoId, fn() => [3.0, 1.0]);
            } else {
                Fixtures::jugarTodo($torneoId);
            }

            $estado = (new TorneoModel())->findById($torneoId)['estado'];
            $this->assertSame('finalizado', $estado, "el torneo de $slug termina");
        }

        $abiertas = $this->rondasAbiertasEnTorneosFinalizados();
        $this->assertCount(0, $abiertas, 'rondas sin cerrar: ' . json_encode($abiertas, JSON_UNESCAPED_UNICODE));
    }

    public function test_seed_demo_no_deja_torneos_finalizados_con_rondas_abiertas(): void
    {
        ob_start();
        require __DIR__ . '/../database/seed_demo.php';
        $salida = (string) ob_get_clean();
        $this->assertStringContainsString('Datos de demostración regenerados', $salida);

        $finalizados = (int) $this->db->query(
            "SELECT COUNT(*) FROM torneos WHERE estado = 'finalizado'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $finalizados, 'el seed tiene que dejar torneos finalizados');

        $abiertas = $this->rondasAbiertasEnTorneosFinalizados();
        $this->assertCount(0, $abiertas, 'rondas sin cerrar: ' . json_encode($abiertas, JSON_UNESCAPED_UNICODE));

        // Y tampoco deben quedar rondas 'pendiente' con todos sus cruces definidos.
        $pendientesJugables = (int) $this->db->query(
            "SELECT COUNT(*) FROM rondas r
             WHERE r.estado = 'pendiente'
               AND EXISTS (SELECT 1 FROM enfrentamientos e WHERE e.ronda_id = r.id)
               AND NOT EXISTS (
                     SELECT 1 FROM enfrentamientos e
                     WHERE e.ronda_id = r.id AND e.es_bye = 0
                       AND ((e.participante_a_id IS NULL AND e.equipo_a_id IS NULL)
                         OR (e.participante_b_id IS NULL AND e.equipo_b_id IS NULL)))"
        )->fetchColumn();
        $this->assertSame(0, $pendientesJugables, 'una ronda jugable no puede quedar en «pendiente»');
    }

    // ─── Cierre y reapertura manual ─────────────────────────────────────────

    public function test_cerrar_una_ronda_a_mano_bloquea_la_carga_de_resultados(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId    = $this->idDeRonda($torneoId, 1);
        $partidos   = Fixtures::partidosPendientes($torneoId, 1);

        (new RondaService())->cerrar($rondaId, Fixtures::ADMIN);
        $this->assertSame('cerrada', $this->estadoDeRonda($torneoId, 1));

        $this->assertThrows(
            fn() => (new ResultadoService())->cargar($partidos[0], 2.0, 1.0, Fixtures::ADMIN),
            'está cerrada'
        );

        // Las demás fechas siguen aceptando resultados.
        $this->assertDoesNotThrow(
            fn() => (new ResultadoService())->cargar(Fixtures::partidosPendientes($torneoId, 2)[0], 2.0, 1.0, Fixtures::ADMIN),
            'cerrar una fecha no afecta a las otras'
        );
    }

    public function test_reabrir_devuelve_la_ronda_a_en_curso_y_rehabilita_la_carga(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId    = $this->idDeRonda($torneoId, 1);
        $partidos   = Fixtures::partidosPendientes($torneoId, 1);

        $svc = new RondaService();
        $svc->cerrar($rondaId, Fixtures::ADMIN);
        $svc->reabrir($rondaId, Fixtures::ADMIN);

        $this->assertSame('en_curso', $this->estadoDeRonda($torneoId, 1));
        $this->assertDoesNotThrow(
            fn() => (new ResultadoService())->cargar($partidos[0], 2.0, 1.0, Fixtures::ADMIN),
            'reabierta, vuelve a aceptar resultados'
        );
    }

    public function test_la_sincronizacion_automatica_no_deshace_un_cierre_manual(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId    = $this->idDeRonda($torneoId, 1);

        (new RondaService())->cerrar($rondaId, Fixtures::ADMIN);

        // Cargar un resultado de OTRA fecha dispara la sincronización del torneo.
        (new ResultadoService())->cargar(Fixtures::partidosPendientes($torneoId, 2)[0], 2.0, 1.0, Fixtures::ADMIN);

        $this->assertSame('cerrada', $this->estadoDeRonda($torneoId, 1),
            'el recálculo solo avanza el estado, nunca lo retrocede');
    }

    public function test_no_se_reabre_una_ronda_cuyos_partidos_ya_terminaron(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        foreach (Fixtures::partidosPendientes($torneoId, 1) as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }
        $rondaId = $this->idDeRonda($torneoId, 1);
        $this->assertSame('cerrada', $this->estadoDeRonda($torneoId, 1));

        $this->assertThrows(
            fn() => (new RondaService())->reabrir($rondaId, Fixtures::ADMIN),
            'todos los partidos de la ronda están resueltos'
        );
    }

    public function test_no_se_cierra_dos_veces_ni_se_reabre_lo_que_no_esta_cerrado(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId    = $this->idDeRonda($torneoId, 1);
        $svc        = new RondaService();

        $this->assertThrows(fn() => $svc->reabrir($rondaId, Fixtures::ADMIN), 'no está cerrada');

        $svc->cerrar($rondaId, Fixtures::ADMIN);
        $this->assertThrows(fn() => $svc->cerrar($rondaId, Fixtures::ADMIN), 'ya está cerrada');
    }

    public function test_cerrar_y_reabrir_quedan_auditados(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $rondaId    = $this->idDeRonda($torneoId, 1);

        $svc = new RondaService();
        $svc->cerrar($rondaId, Fixtures::ADMIN);
        $svc->reabrir($rondaId, Fixtures::ADMIN);

        foreach (['cerrar_ronda' => 'cerrada', 'reabrir_ronda' => 'en_curso'] as $accion => $estadoNuevo) {
            $filas = (int) $this->db->query(
                "SELECT COUNT(*) FROM auditoria
                 WHERE accion = '$accion' AND tabla_afectada = 'rondas' AND registro_id = $rondaId
                   AND usuario_id = " . Fixtures::ADMIN . "
                   AND JSON_UNQUOTE(JSON_EXTRACT(valor_nuevo, '$.estado')) = '$estadoNuevo'"
            )->fetchColumn();
            $this->assertSame(1, $filas, "quedó registrado «$accion»");
        }
    }
}

exit(TestCase::ejecutar(RondasEstadoTest::class));
