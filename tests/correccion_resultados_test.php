<?php
declare(strict_types=1);

/**
 * Test de integración del flujo de corrección de resultados.
 *
 * Ejercita CorreccionService y ResultadoService::corregir() de verdad contra una
 * base de prueba, recorriendo el circuito completo:
 *   solicitar → (aprobar | rechazar) → efecto sobre el resultado y la tabla.
 *
 * Cubre además los bloqueos por formato: en Eliminación Directa no se corrige un
 * partido cuyo ganador ya avanzó, y en Sistema Suizo no se corrige una ronda
 * anterior a la última generada.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/correccion_resultados_test.php
 */

require __DIR__ . '/bootstrap.php';

final class CorreccionResultadosTest extends TestCase
{
    private const MOTIVO = 'El acta del partido tenía el marcador invertido';

    private int $torneoId = 0;
    private int $partidoId = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);

        // Liga de 4: formato sin restricciones de bracket ni de rondas, así el
        // circuito de corrección se puede probar aislado.
        [$this->torneoId] = Fixtures::torneoArrancado('liga', 4);
        $this->partidoId  = Fixtures::partidosPendientes($this->torneoId)[0];
    }

    private function cargarPartido(float $a = 3.0, float $b = 1.0): void
    {
        (new ResultadoService())->cargar($this->partidoId, $a, $b, Fixtures::ADMIN);
    }

    private function resultadoActual(): array
    {
        return (new ResultadoModel())->getByEnfrentamiento($this->partidoId);
    }

    private function solicitudes(string $estado = ''): array
    {
        $sql = "SELECT * FROM solicitudes_correccion WHERE torneo_id = {$this->torneoId}";
        if ($estado !== '') $sql .= " AND estado = '" . $estado . "'";
        return $this->db->query($sql . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── solicitar() ────────────────────────────────────────────────────────

    public function test_no_se_solicita_correccion_de_un_partido_sin_jugar(): void
    {
        $this->assertThrows(
            fn() => (new CorreccionService())->solicitar($this->partidoId, 1.0, 0.0, self::MOTIVO, Fixtures::ADMIN),
            'partidos finalizados'
        );
        $this->assertCount(0, $this->solicitudes(), 'no quedó ninguna solicitud registrada');
    }

    public function test_el_motivo_es_obligatorio_y_de_al_menos_10_caracteres(): void
    {
        $this->cargarPartido();
        $svc = new CorreccionService();

        $this->assertThrows(
            fn() => $svc->solicitar($this->partidoId, 1.0, 0.0, '', Fixtures::ADMIN),
            'motivo es obligatorio'
        );
        $this->assertThrows(
            fn() => $svc->solicitar($this->partidoId, 1.0, 0.0, 'error', Fixtures::ADMIN),
            'motivo es obligatorio',
            'un motivo de 5 caracteres no alcanza'
        );
        $this->assertCount(0, $this->solicitudes());
    }

    public function test_no_se_admiten_puntos_negativos(): void
    {
        $this->cargarPartido();
        $this->assertThrows(
            fn() => (new CorreccionService())->solicitar($this->partidoId, -1.0, 2.0, self::MOTIVO, Fixtures::ADMIN),
            'no pueden ser negativos'
        );
    }

    public function test_una_solicitud_valida_queda_pendiente_sin_tocar_el_resultado(): void
    {
        $this->cargarPartido(3.0, 1.0);
        (new CorreccionService())->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);

        $pendientes = $this->solicitudes('pendiente');
        $this->assertCount(1, $pendientes);
        $this->assertSame('1.00', $pendientes[0]['puntos_a'], 'guarda el marcador propuesto');
        $this->assertSame('3.00', $pendientes[0]['puntos_b']);

        $res = $this->resultadoActual();
        $this->assertSame('3.00', $res['puntos_a'], 'el resultado original no se toca al solicitar');
        $this->assertSame('cargado', $res['estado']);
    }

    public function test_no_puede_haber_dos_solicitudes_pendientes_para_el_mismo_partido(): void
    {
        $this->cargarPartido();
        $svc = new CorreccionService();
        $svc->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);

        $this->assertThrows(
            fn() => $svc->solicitar($this->partidoId, 2.0, 2.0, self::MOTIVO, Fixtures::ADMIN),
            'Ya existe una solicitud'
        );
        $this->assertCount(1, $this->solicitudes());
    }

    // ─── aprobar() ──────────────────────────────────────────────────────────

    public function test_aprobar_aplica_la_correccion_y_recalcula_la_tabla(): void
    {
        $this->cargarPartido(3.0, 1.0);

        $enf = (new EnfrentamientoModel())->findById($this->partidoId);
        $ganadorOriginal = (int)$enf['ganador_participante_id'];
        $perdedor        = (int)$enf['participante_a_id'] === $ganadorOriginal
            ? (int)$enf['participante_b_id']
            : (int)$enf['participante_a_id'];

        $svc = new CorreccionService();
        $svc->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);
        $solicitud = $this->solicitudes('pendiente')[0];

        $svc->aprobar((int)$solicitud['id'], Fixtures::ADMIN);

        // El resultado cambió y quedó marcado como corregido.
        $res = $this->resultadoActual();
        $this->assertSame('1.00', $res['puntos_a']);
        $this->assertSame('3.00', $res['puntos_b']);
        $this->assertSame('corregido', $res['estado']);

        // El ganador es el otro.
        $enfDespues = (new EnfrentamientoModel())->findById($this->partidoId);
        $this->assertSame($perdedor, (int)$enfDespues['ganador_participante_id'],
            'la corrección invierte el ganador del partido');

        // La solicitud quedó resuelta.
        $aprobadas = $this->solicitudes('aprobada');
        $this->assertCount(1, $aprobadas);
        $this->assertSame(Fixtures::ADMIN, (int)$aprobadas[0]['revisado_por']);
        $this->assertNotNull($aprobadas[0]['resuelto_at']);

        // Y la tabla refleja el cambio: el nuevo ganador tiene los 3 puntos.
        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);
        $puntosPorId = [];
        foreach ($tabla as $fila) $puntosPorId[(int)$fila['participante_id']] = (int)$fila['puntos'];
        $this->assertSame(3, $puntosPorId[$perdedor], 'el nuevo ganador suma la victoria');
        $this->assertSame(0, $puntosPorId[$ganadorOriginal], 'el anterior ganador pierde los puntos');
    }

    public function test_una_solicitud_ya_resuelta_no_se_puede_volver_a_resolver(): void
    {
        $this->cargarPartido();
        $svc = new CorreccionService();
        $svc->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);
        $id = (int) $this->solicitudes('pendiente')[0]['id'];

        $svc->aprobar($id, Fixtures::ADMIN);

        $this->assertThrows(fn() => $svc->aprobar($id, Fixtures::ADMIN), 'ya fue resuelta');
        $this->assertThrows(fn() => $svc->rechazar($id, Fixtures::ADMIN, 'tarde'), 'ya fue resuelta');
    }

    // ─── rechazar() ─────────────────────────────────────────────────────────

    public function test_rechazar_exige_motivo_y_deja_el_resultado_intacto(): void
    {
        $this->cargarPartido(3.0, 1.0);
        $svc = new CorreccionService();
        $svc->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);
        $id = (int) $this->solicitudes('pendiente')[0]['id'];

        $this->assertThrows(
            fn() => $svc->rechazar($id, Fixtures::ADMIN, '   '),
            'motivo de rechazo'
        );

        $svc->rechazar($id, Fixtures::ADMIN, 'El acta original coincide con la planilla del árbitro');

        $rechazadas = $this->solicitudes('rechazada');
        $this->assertCount(1, $rechazadas);
        $this->assertStringContainsString('planilla del árbitro', (string)$rechazadas[0]['motivo_rechazo']);

        $res = $this->resultadoActual();
        $this->assertSame('3.00', $res['puntos_a'], 'rechazar no modifica el resultado');
        $this->assertSame('cargado', $res['estado']);
    }

    public function test_tras_rechazar_se_puede_volver_a_solicitar(): void
    {
        $this->cargarPartido();
        $svc = new CorreccionService();
        $svc->solicitar($this->partidoId, 1.0, 3.0, self::MOTIVO, Fixtures::ADMIN);
        $id = (int) $this->solicitudes('pendiente')[0]['id'];
        $svc->rechazar($id, Fixtures::ADMIN, 'No corresponde');

        $this->assertDoesNotThrow(
            fn() => $svc->solicitar($this->partidoId, 2.0, 5.0, 'Se adjunta el acta firmada por ambos', Fixtures::ADMIN),
            'al no quedar ninguna pendiente, se puede solicitar de nuevo'
        );
        $this->assertCount(1, $this->solicitudes('pendiente'));
    }

    // ─── Bloqueos por formato ───────────────────────────────────────────────

    public function test_en_eliminacion_directa_no_se_corrige_si_el_ganador_ya_avanzo(): void
    {
        // El administrador corrige directo, sin pasar por una solicitud. El
        // bloqueo también tiene que frenarlo a él: en Eliminación Directa el
        // ganador se ubica en la ronda siguiente apenas se carga el resultado,
        // así que no hay ventana en la que la corrección sea inocua.
        [$torneoElim] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $partido = Fixtures::partidosPendientes($torneoElim)[0];
        (new ResultadoService())->cargar($partido, 2.0, 1.0, Fixtures::ADMIN);

        $this->assertThrows(
            fn() => (new ResultadoService())->corregir(
                $partido, 1.0, 2.0, self::MOTIVO, Fixtures::ADMIN
            ),
            'ya generó una ronda posterior'
        );
    }

    public function test_en_suizo_no_se_corrige_una_ronda_anterior_a_la_ultima(): void
    {
        [$torneoSuizo] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);

        $ronda1 = Fixtures::partidosPendientes($torneoSuizo, 1);
        foreach ($ronda1 as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }
        (new SistemaSuizoService())->generarSiguienteRonda($torneoSuizo);

        $this->assertThrows(
            fn() => (new ResultadoService())->corregir(
                $ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ADMIN
            ),
            'ya se generó una ronda posterior'
        );
    }

    // ─── La solicitud no puede nacer imposible ──────────────────────────────
    //
    // El bloqueo por formato lo miraba solo quien APLICA la corrección. El que
    // la PIDE no lo miraba, así que el organizador registraba una solicitud que
    // nunca se iba a poder aprobar y se enteraba recién un admin, al intentarlo.
    // La regla ahora la consultan los dos caminos.

    /** @return int id de la solicitud pendiente del torneo */
    private function pendienteDe(int $torneoId): int
    {
        return (int) $this->db->query(
            "SELECT id FROM solicitudes_correccion WHERE torneo_id = {$torneoId} AND estado = 'pendiente'"
        )->fetchColumn();
    }

    private function contarSolicitudesDe(int $torneoId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM solicitudes_correccion WHERE torneo_id = {$torneoId}"
        )->fetchColumn();
    }

    public function test_en_eliminacion_directa_no_se_solicita_si_el_ganador_ya_avanzo(): void
    {
        [$torneoElim] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $partido = Fixtures::partidosPendientes($torneoElim)[0];
        (new ResultadoService())->cargar($partido, 2.0, 1.0, Fixtures::ADMIN);

        $this->assertThrows(
            fn() => (new CorreccionService())->solicitar($partido, 1.0, 2.0, self::MOTIVO, Fixtures::ORGANIZADOR),
            'ya generó una ronda posterior',
            'el organizador se entera al pedirla, no un admin tres días después'
        );
        $this->assertSame(0, $this->contarSolicitudesDe($torneoElim),
            'una solicitud imposible no queda registrada');
    }

    public function test_en_suizo_no_se_solicita_sobre_una_ronda_ya_superada(): void
    {
        [$torneoSuizo] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);

        $ronda1 = Fixtures::partidosPendientes($torneoSuizo, 1);
        foreach ($ronda1 as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }
        (new SistemaSuizoService())->generarSiguienteRonda($torneoSuizo);

        $this->assertThrows(
            fn() => (new CorreccionService())->solicitar($ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ORGANIZADOR),
            'ya se generó una ronda posterior'
        );
        $this->assertSame(0, $this->contarSolicitudesDe($torneoSuizo));
    }

    public function test_solicitar_y_aprobar_usan_exactamente_la_misma_regla(): void
    {
        // Si las dos puntas no consultaran la misma regla, volvería a haber
        // solicitudes que se aceptan y no se pueden aplicar.
        [$torneoSuizo] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);
        $ronda1 = Fixtures::partidosPendientes($torneoSuizo, 1);
        foreach ($ronda1 as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }

        $resultados = new ResultadoService();
        $svc        = new CorreccionService();

        // Antes de generar la ronda 2: las dos puntas dicen que sí.
        $this->assertSame(null, $resultados->motivoBloqueoCorreccion($ronda1[0]));
        $this->assertDoesNotThrow(
            fn() => $svc->solicitar($ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ORGANIZADOR)
        );
        $svc->rechazar($this->pendienteDe($torneoSuizo), Fixtures::ADMIN, 'se prueba el otro lado');

        // Después: las dos dicen que no, con el mismo motivo.
        (new SistemaSuizoService())->generarSiguienteRonda($torneoSuizo);
        $motivo = $resultados->motivoBloqueoCorreccion($ronda1[0]);
        $this->assertNotNull($motivo);

        $eSolicitar = $this->assertThrows(
            fn() => $svc->solicitar($ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ORGANIZADOR)
        );
        $eCorregir = $this->assertThrows(
            fn() => $resultados->corregir($ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ADMIN)
        );
        $this->assertSame($motivo, $eSolicitar->getMessage());
        $this->assertSame($motivo, $eCorregir->getMessage(),
            'pedir y aplicar tienen que dar el mismo motivo, o son dos reglas distintas');
    }

    public function test_si_la_ronda_avanza_despues_de_pedirla_el_admin_la_puede_rechazar(): void
    {
        // La única forma que queda de tener una solicitud inaplicable: era válida
        // cuando se pidió y el torneo avanzó mientras esperaba. No se resuelve
        // sola —el sistema no decide por el admin— pero tampoco queda trabada.
        [$torneoSuizo] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);
        $ronda1 = Fixtures::partidosPendientes($torneoSuizo, 1);
        foreach ($ronda1 as $eid) {
            (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        }

        $svc = new CorreccionService();
        $svc->solicitar($ronda1[0], 1.0, 2.0, self::MOTIVO, Fixtures::ORGANIZADOR);
        $id = $this->pendienteDe($torneoSuizo);

        (new SistemaSuizoService())->generarSiguienteRonda($torneoSuizo);

        $this->assertThrows(fn() => $svc->aprobar($id, Fixtures::ADMIN), 'ya se generó una ronda posterior');
        $this->assertSame('pendiente',
            $this->db->query("SELECT estado FROM solicitudes_correccion WHERE id = {$id}")->fetchColumn());

        $svc->rechazar($id, Fixtures::ADMIN, 'La ronda siguiente ya se generó: no se puede revertir');
        $this->assertSame('rechazada',
            $this->db->query("SELECT estado FROM solicitudes_correccion WHERE id = {$id}")->fetchColumn());
    }
}

exit(TestCase::ejecutar(CorreccionResultadosTest::class));
