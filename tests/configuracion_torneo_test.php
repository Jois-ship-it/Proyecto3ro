<?php
declare(strict_types=1);

/**
 * Test de los datos del evento por torneo (tabla `configuraciones_torneo`).
 *
 * La tabla existía en el esquema desde el principio y ningún código la leía:
 * `seed_demo.php` escribía 174 filas con una sentencia preparada suelta y ahí
 * quedaban. Ahora hay modelo, servicio con catálogo de claves declarado, y un
 * uso que cambia el comportamiento del sistema: `cierre_inscripcion` cierra las
 * inscripciones de verdad.
 *
 * Lo que se verifica:
 *   1. Guardado, lectura y borrado, con el catálogo rechazando lo que no declara.
 *   2. Las validaciones por tipo (email, fecha) y la regla cruzada contra la
 *      fecha de inicio del torneo.
 *   3. Que el cierre de inscripción REALMENTE impida inscribirse, en las dos
 *      modalidades. Esta es la parte que convierte la tabla en algo que gobierna
 *      y no en metadata decorativa.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/configuracion_torneo_test.php
 */

require __DIR__ . '/bootstrap.php';

final class ConfiguracionTorneoTest extends TestCase
{
    private ConfiguracionTorneoService $servicio;
    private ConfiguracionTorneoModel   $modelo;
    private InscripcionService         $inscripciones;

    protected function setUp(): void
    {
        $this->servicio      = new ConfiguracionTorneoService();
        $this->modelo        = new ConfiguracionTorneoModel();
        $this->inscripciones = new InscripcionService();
        Fixtures::reset($this->db);
    }

    /** Crea un torneo individual abierto a inscripción, con fecha de inicio futura. */
    private function torneoAbierto(): int
    {
        return Fixtures::torneo('liga', [
            'estado'       => 'inscripcion',
            'fecha_inicio' => date('Y-m-d', strtotime('+30 days')),
            'fecha_fin'    => date('Y-m-d', strtotime('+60 days')),
        ]);
    }

    // ─── Guardado y lectura ─────────────────────────────────────────────────

    public function test_guarda_y_devuelve_las_claves_del_catalogo(): void
    {
        $torneoId = $this->torneoAbierto();

        $this->servicio->guardar($torneoId, [
            'sede'          => 'Gimnasio Municipal',
            'contacto'      => 'torneos@flexarena.uy',
            'observaciones' => 'Llevar documento.',
        ]);

        $config = $this->servicio->getPorTorneo($torneoId);

        $this->assertSame('Gimnasio Municipal', $config['sede']);
        $this->assertSame('torneos@flexarena.uy', $config['contacto']);
        $this->assertSame('Llevar documento.', $config['observaciones']);
        // La clave no enviada vuelve vacía, no ausente: las vistas no tienen que
        // preguntarse si existe.
        $this->assertSame('', $config['cierre_inscripcion']);
        $this->assertCount(4, $config);
    }

    public function test_una_clave_vacia_se_borra_en_vez_de_guardarse_en_blanco(): void
    {
        $torneoId = $this->torneoAbierto();

        $this->servicio->guardar($torneoId, ['sede' => 'Club Progreso']);
        $this->assertSame('Club Progreso', $this->modelo->get($torneoId, 'sede'));

        $this->servicio->guardar($torneoId, ['sede' => '   ']);
        $this->assertSame(null, $this->modelo->get($torneoId, 'sede'),
            'sin sede y con sede vacía son lo mismo: no deberían quedar filas sin información');
    }

    public function test_las_claves_ajenas_al_catalogo_se_ignoran(): void
    {
        $torneoId = $this->torneoAbierto();

        $this->servicio->guardar($torneoId, [
            'sede'            => 'Arena Pocitos',
            'clave_inventada' => 'lo que sea',
            'puntos_victoria' => '99',   // esto tiene columna propia en torneos
        ]);

        $guardadas = $this->modelo->getPorTorneo($torneoId);
        $this->assertSame(['sede'], array_keys($guardadas),
            'la tabla clave/valor no puede ser un cajón donde entre cualquier cosa');

        // Y la columna real del torneo no se tocó.
        $torneo = (new TorneoModel())->findById($torneoId);
        $this->assertSame(3, (int)$torneo['puntos_victoria']);
    }

    public function test_el_borrado_del_torneo_se_lleva_su_configuracion(): void
    {
        $torneoId = $this->torneoAbierto();
        $this->servicio->guardar($torneoId, ['sede' => 'Sede de prueba']);

        // La FK es ON DELETE CASCADE: se comprueba, no se supone.
        $this->db->exec("DELETE FROM torneos WHERE id = {$torneoId}");

        $quedan = (int) $this->db->query(
            "SELECT COUNT(*) FROM configuraciones_torneo WHERE torneo_id = {$torneoId}"
        )->fetchColumn();
        $this->assertSame(0, $quedan);
    }

    public function test_lee_varios_torneos_en_una_sola_consulta(): void
    {
        $a = $this->torneoAbierto();
        $b = $this->torneoAbierto();

        $this->servicio->guardar($a, ['sede' => 'Sede A']);
        $this->servicio->guardar($b, ['sede' => 'Sede B']);

        $mapa = $this->servicio->getPorTorneos([$a, $b]);

        $this->assertSame('Sede A', $mapa[$a]['sede']);
        $this->assertSame('Sede B', $mapa[$b]['sede']);
        $this->assertSame('', $mapa[$a]['contacto']);

        // Sin ids no se consulta nada.
        $this->assertCount(0, $this->servicio->getPorTorneos([]));
    }

    // ─── Validaciones ───────────────────────────────────────────────────────

    public function test_el_contacto_tiene_que_ser_un_email(): void
    {
        $torneoId = $this->torneoAbierto();

        $this->assertThrows(
            fn() => $this->servicio->guardar($torneoId, ['contacto' => 'no-es-un-email']),
            'correo electrónico válido'
        );
        $this->assertSame(null, $this->modelo->get($torneoId, 'contacto'),
            'un valor rechazado no puede quedar guardado');
    }

    public function test_la_fecha_de_cierre_tiene_que_existir_de_verdad(): void
    {
        $torneoId = $this->torneoAbierto();

        // 31 de febrero: strtotime lo aceptaría y lo correría al 2 o 3 de marzo.
        $this->assertThrows(
            fn() => $this->servicio->guardar($torneoId, ['cierre_inscripcion' => '2027-02-31']),
            'fecha válida'
        );
        $this->assertThrows(
            fn() => $this->servicio->guardar($torneoId, ['cierre_inscripcion' => '15/03/2027']),
            'fecha válida'
        );
    }

    public function test_el_cierre_no_puede_ser_posterior_al_inicio_del_torneo(): void
    {
        $torneoId = $this->torneoAbierto();
        $torneo   = (new TorneoModel())->findById($torneoId);

        $despues = date('Y-m-d', strtotime($torneo['fecha_inicio'] . ' +1 day'));

        $this->assertThrows(
            fn() => $this->servicio->guardar($torneoId, ['cierre_inscripcion' => $despues], $torneo),
            'no puede ser posterior a la fecha de inicio'
        );

        // El mismo día del arranque sí se acepta.
        $this->assertDoesNotThrow(
            fn() => $this->servicio->guardar($torneoId, ['cierre_inscripcion' => $torneo['fecha_inicio']], $torneo)
        );
    }

    public function test_el_texto_no_puede_pasarse_del_maximo_declarado(): void
    {
        $torneoId = $this->torneoAbierto();
        $max      = ConfiguracionTorneoService::CLAVES['sede']['max'];

        $this->assertThrows(
            fn() => $this->servicio->guardar($torneoId, ['sede' => str_repeat('a', $max + 1)]),
            'caracteres'
        );
        $this->assertDoesNotThrow(
            fn() => $this->servicio->guardar($torneoId, ['sede' => str_repeat('a', $max)])
        );
    }

    public function test_cambiar_los_datos_del_evento_queda_auditado(): void
    {
        $torneoId = $this->torneoAbierto();

        $antes = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'editar_configuracion_torneo'"
        )->fetchColumn();

        $this->servicio->guardar($torneoId, ['sede' => 'Complejo UTU']);

        $despues = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'editar_configuracion_torneo'"
        )->fetchColumn();
        $this->assertSame($antes + 1, $despues);

        // Guardar lo mismo otra vez no es un cambio y no debería dejar rastro.
        $this->servicio->guardar($torneoId, ['sede' => 'Complejo UTU']);
        $sinCambio = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'editar_configuracion_torneo'"
        )->fetchColumn();
        $this->assertSame($despues, $sinCambio);
    }

    public function test_validar_no_escribe_nada(): void
    {
        // El controlador valida antes de guardar el torneo, para que el
        // formulario no quede a medias. Esa validacion no puede tener efectos.
        $torneoId = $this->torneoAbierto();
        $this->servicio->guardar($torneoId, ['sede' => 'Sede original']);

        $this->assertThrows(
            fn() => $this->servicio->validar(['sede' => 'Sede nueva', 'contacto' => 'roto']),
            'correo'
        );
        $this->assertSame('Sede original', $this->modelo->get($torneoId, 'sede'),
            'validar() no debe tocar la base');

        // Y con datos buenos devuelve el juego normalizado, tambien sin escribir.
        $limpios = $this->servicio->validar(['sede' => '  Sede con espacios  ']);
        $this->assertSame('Sede con espacios', $limpios['sede']);
        $this->assertSame('', $limpios['contacto']);
        $this->assertSame('Sede original', $this->modelo->get($torneoId, 'sede'));
    }

    // ─── El cierre de inscripción hace algo ─────────────────────────────────

    public function test_sin_fecha_de_cierre_no_hay_plazo(): void
    {
        $torneoId = $this->torneoAbierto();
        $ids      = Fixtures::participantes(2, 'Sin plazo');

        $this->assertFalse($this->servicio->inscripcionVencida($torneoId));
        $this->assertDoesNotThrow(
            fn() => $this->inscripciones->inscribirParticipante($torneoId, $ids[0])
        );
    }

    public function test_con_el_plazo_abierto_se_puede_inscribir(): void
    {
        $torneoId = $this->torneoAbierto();
        $ids      = Fixtures::participantes(2, 'Plazo abierto');

        $this->servicio->guardar($torneoId, [
            'cierre_inscripcion' => date('Y-m-d', strtotime('+5 days')),
        ]);

        $this->assertFalse($this->servicio->inscripcionVencida($torneoId));
        $this->assertDoesNotThrow(
            fn() => $this->inscripciones->inscribirParticipante($torneoId, $ids[0])
        );
    }

    public function test_el_ultimo_dia_del_plazo_todavia_cuenta(): void
    {
        $torneoId = $this->torneoAbierto();
        $ids      = Fixtures::participantes(2, 'Ultimo dia');

        // Cierra HOY: el plazo incluye su propia fecha.
        $this->servicio->guardar($torneoId, ['cierre_inscripcion' => date('Y-m-d')]);

        $this->assertFalse($this->servicio->inscripcionVencida($torneoId),
            'un torneo que cierra hoy acepta inscripciones durante todo el día');
        $this->assertDoesNotThrow(
            fn() => $this->inscripciones->inscribirParticipante($torneoId, $ids[0])
        );
    }

    public function test_pasado_el_plazo_no_se_puede_inscribir_un_participante(): void
    {
        $torneoId = $this->torneoAbierto();
        $ids      = Fixtures::participantes(2, 'Plazo vencido');
        $ayer     = date('Y-m-d', strtotime('-1 day'));

        // Un cierre en el pasado es valido: la regla es que no sea POSTERIOR al
        // inicio del torneo. Un torneo que ya cerro inscripciones es normal.
        $this->servicio->guardar($torneoId, ['cierre_inscripcion' => $ayer]);

        $this->assertTrue($this->servicio->inscripcionVencida($torneoId));

        $e = $this->assertThrows(
            fn() => $this->inscripciones->inscribirParticipante($torneoId, $ids[0]),
            'plazo de inscripción'
        );
        $this->assertStringContainsString($ayer, $e->getMessage(),
            'el mensaje tiene que decir cuándo cerró');

        $inscritos = (new InscripcionModel())->getByTorneo($torneoId);
        $this->assertCount(0, $inscritos, 'no puede haber quedado inscrito');
    }

    public function test_pasado_el_plazo_tampoco_se_puede_inscribir_un_equipo(): void
    {
        $torneoId = Fixtures::torneo('liga', [
            'modalidad'              => 'equipos',
            'estado'                 => 'inscripcion',
            'min_integrantes_equipo' => 3,
            'fecha_inicio'           => date('Y-m-d', strtotime('+30 days')),
            'fecha_fin'              => date('Y-m-d', strtotime('+60 days')),
        ]);
        $participantes = Fixtures::participantes(3, 'Integrante');
        $equipos       = Fixtures::equipos(1, $participantes);

        $this->servicio->guardar($torneoId, [
            'cierre_inscripcion' => date('Y-m-d', strtotime('-1 day')),
        ]);

        $this->assertThrows(
            fn() => $this->inscripciones->inscribirEquipo($torneoId, $equipos[0]),
            'plazo de inscripción'
        );
    }

    public function test_en_borrador_el_plazo_no_se_aplica(): void
    {
        // Un torneo en borrador todavía se está armando y no es público: una
        // fecha de cierre ahí no significa nada.
        $torneoId = Fixtures::torneo('liga', [
            'estado'       => 'borrador',
            'fecha_inicio' => date('Y-m-d', strtotime('+30 days')),
            'fecha_fin'    => date('Y-m-d', strtotime('+60 days')),
        ]);
        $ids = Fixtures::participantes(2, 'Borrador');

        $this->servicio->guardar($torneoId, [
            'cierre_inscripcion' => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertDoesNotThrow(
            fn() => $this->inscripciones->inscribirParticipante($torneoId, $ids[0])
        );
    }

    public function test_el_plazo_vencido_no_impide_retirar_una_inscripcion(): void
    {
        $torneoId = $this->torneoAbierto();
        $ids      = Fixtures::participantes(2, 'Retiro');

        $this->inscripciones->inscribirParticipante($torneoId, $ids[0]);
        $this->servicio->guardar($torneoId, [
            'cierre_inscripcion' => date('Y-m-d', strtotime('-1 day')),
        ]);

        // Cerrar el plazo impide ANOTARSE, no bajarse: alguien que ya no puede
        // competir tiene que poder salir de la lista.
        $this->assertDoesNotThrow(
            fn() => $this->inscripciones->desinscribir($torneoId, $ids[0], null)
        );
        $this->assertCount(0, (new InscripcionModel())->getByTorneo($torneoId));
    }
}

exit(TestCase::ejecutar(ConfiguracionTorneoTest::class));
