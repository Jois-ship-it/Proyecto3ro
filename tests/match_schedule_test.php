<?php
declare(strict_types=1);

/**
 * Test de integración de la programación de partidos.
 *
 * Reemplaza a la versión que reimplementaba la comparación de fechas: ahora se
 * llama a ResultadoService::programar() de verdad sobre torneos reales y se
 * comprueba qué quedó en `enfrentamientos.fecha_programada`.
 *
 * Regla: la fecha del partido debe caer en [fecha_inicio 00:00, fecha_fin 23:59],
 * inclusive. En torneos de una sola jornada, solo ese día (cualquier hora).
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/match_schedule_test.php
 */

require __DIR__ . '/bootstrap.php';

final class MatchScheduleTest extends TestCase
{
    private const INICIO = '2026-07-10';
    private const FIN    = '2026-07-15';
    private const UNICO  = '2026-07-15';

    private int $torneoRango = 0;
    private int $partidoRango = 0;
    private int $torneoUnaJornada = 0;
    private int $partidoUnaJornada = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);

        [$this->torneoRango] = Fixtures::torneoArrancado('liga', 4, [
            'nombre'       => 'Torneo de varios días',
            'fecha_inicio' => self::INICIO,
            'fecha_fin'    => self::FIN,
        ]);
        $this->partidoRango = Fixtures::partidosPendientes($this->torneoRango)[0];

        [$this->torneoUnaJornada] = Fixtures::torneoArrancado('liga', 4, [
            'nombre'       => 'Torneo de una jornada',
            'fecha_inicio' => self::UNICO,
            'fecha_fin'    => self::UNICO,
        ]);
        $this->partidoUnaJornada = Fixtures::partidosPendientes($this->torneoUnaJornada)[0];
    }

    private function fechaProgramada(int $partidoId): ?string
    {
        $v = $this->db->query(
            "SELECT fecha_programada FROM enfrentamientos WHERE id = $partidoId"
        )->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    private function programar(int $partidoId, ?string $fecha): void
    {
        (new ResultadoService())->programar($partidoId, $fecha, Fixtures::ADMIN);
    }

    // ─── Torneo de varios días ──────────────────────────────────────────────

    public function test_acepta_una_fecha_dentro_del_rango_del_torneo(): void
    {
        foreach ([
            '2026-07-10 10:00' => 'primer día',
            '2026-07-13 18:30' => 'día intermedio',
            '2026-07-15 23:59' => 'último minuto del último día',
        ] as $fecha => $descripcion) {
            $this->assertDoesNotThrow(
                fn() => $this->programar($this->partidoRango, $fecha),
                "debería aceptar el $descripcion ($fecha)"
            );
            $this->assertStringContainsString(
                substr($fecha, 0, 16),
                (string) $this->fechaProgramada($this->partidoRango),
                "quedó guardada la fecha del $descripcion"
            );
        }
    }

    public function test_rechaza_una_fecha_anterior_al_inicio(): void
    {
        $e = $this->assertThrows(
            fn() => $this->programar($this->partidoRango, '2026-07-09 23:59'),
            'dentro del rango del torneo'
        );
        $this->assertStringContainsString('10/07/2026', $e->getMessage(), 'el mensaje dice desde cuándo');
        $this->assertStringContainsString('15/07/2026', $e->getMessage(), 'y hasta cuándo');
        $this->assertSame(null, $this->fechaProgramada($this->partidoRango), 'no se guardó nada');
    }

    public function test_rechaza_una_fecha_posterior_al_cierre(): void
    {
        $this->assertThrows(
            fn() => $this->programar($this->partidoRango, '2026-07-16 00:00'),
            'dentro del rango del torneo'
        );
        $this->assertSame(null, $this->fechaProgramada($this->partidoRango));
    }

    // ─── Torneo de una sola jornada ─────────────────────────────────────────

    public function test_en_un_torneo_de_una_jornada_acepta_cualquier_hora_de_ese_dia(): void
    {
        foreach (['2026-07-15 00:00', '2026-07-15 08:30', '2026-07-15 23:59'] as $fecha) {
            $this->assertDoesNotThrow(
                fn() => $this->programar($this->partidoUnaJornada, $fecha),
                "debería aceptar $fecha"
            );
        }
    }

    public function test_en_un_torneo_de_una_jornada_rechaza_el_dia_anterior_y_el_siguiente(): void
    {
        foreach (['2026-07-14 23:59', '2026-07-16 00:00'] as $fecha) {
            $e = $this->assertThrows(
                fn() => $this->programar($this->partidoUnaJornada, $fecha),
                'una sola jornada',
                "debería rechazar $fecha"
            );
            $this->assertStringContainsString('15/07/2026', $e->getMessage(),
                'el mensaje nombra la única fecha posible');
        }
        $this->assertSame(null, $this->fechaProgramada($this->partidoUnaJornada));
    }

    // ─── Otros bloqueos de programar() ──────────────────────────────────────

    public function test_una_fecha_con_formato_invalido_se_rechaza(): void
    {
        $this->assertThrows(
            fn() => $this->programar($this->partidoRango, 'no es una fecha'),
            'no es válida'
        );
    }

    public function test_pasar_fecha_vacia_desprograma_el_partido(): void
    {
        $this->programar($this->partidoRango, '2026-07-12 20:00');
        $this->assertNotNull($this->fechaProgramada($this->partidoRango));

        $this->programar($this->partidoRango, null);
        $this->assertSame(null, $this->fechaProgramada($this->partidoRango), 'la fecha se limpia');
    }

    public function test_no_se_programa_un_partido_ya_finalizado(): void
    {
        (new ResultadoService())->cargar($this->partidoRango, 2.0, 1.0, Fixtures::ADMIN);

        $this->assertThrows(
            fn() => $this->programar($this->partidoRango, '2026-07-12 20:00'),
            'finalizado o cancelado'
        );
    }

    public function test_no_se_programa_un_bye(): void
    {
        // 5 inscritos en suizo → la ronda 1 deja un bye.
        [$torneoSuizo] = Fixtures::torneoArrancado('suizo', 5, [
            'fecha_inicio' => self::INICIO,
            'fecha_fin'    => self::FIN,
        ]);
        $bye = (int) $this->db->query(
            "SELECT id FROM enfrentamientos WHERE torneo_id = $torneoSuizo AND es_bye = 1 LIMIT 1"
        )->fetchColumn();

        $this->assertGreaterThan(0, $bye, 'hay un bye en la ronda 1');
        $this->assertThrows(
            fn() => $this->programar($bye, '2026-07-12 20:00'),
            'No se puede programar un bye'
        );
    }

    public function test_un_enfrentamiento_inexistente_da_error_claro(): void
    {
        $this->assertThrows(
            fn() => $this->programar(999999, '2026-07-12 20:00'),
            'Enfrentamiento no encontrado'
        );
    }
}

exit(TestCase::ejecutar(MatchScheduleTest::class));
