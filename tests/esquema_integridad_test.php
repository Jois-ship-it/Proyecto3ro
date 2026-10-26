<?php
declare(strict_types=1);

/**
 * Test de las restricciones de integridad del esquema.
 *
 * Hay reglas que el servicio ya validaba (dos equipos no se llaman igual, un
 * resultado no es negativo…). Eso protege la aplicación, pero no los datos: un
 * script, un phpMyAdmin o un servicio nuevo que se olvide de validar escriben
 * igual. Acá se comprueba que, además, el MOTOR las rechaza, y que el servicio
 * sigue avisando antes con un mensaje que entiende una persona.
 *
 * Cada caso intenta la escritura prohibida y exige un PDOException que nombre la
 * restricción, y la permitida del mismo tipo, para que no pase por rechazarlo
 * todo.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/esquema_integridad_test.php
 */

require __DIR__ . '/bootstrap.php';

class EsquemaIntegridadTest extends TestCase
{
    /** Valores por omisión de configuracion_sistema (los mismos de seed.sql y de la migración). */
    private const CONFIG_POR_OMISION = [
        'sitio_nombre'          => 'FlexArena',
        'sitio_contacto'        => '',
        'perfiles_publicos'     => '1',
        'login_max_intentos'    => '5',
        'login_minutos_bloqueo' => '15',
        'login_max_intentos_ip' => '20',
        'login_ventana_minutos' => '15',
    ];

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    /** El motor tiene que rechazar $fn, y el error tiene que nombrar $restriccion. */
    private function assertLaBaseRechaza(callable $fn, string $restriccion): void
    {
        $e = $this->assertThrows($fn);
        $this->assertTrue($e instanceof PDOException,
            'lo rechazó ' . get_class($e) . ', no el motor: ' . $e->getMessage());
        $this->assertStringContainsString($restriccion, $e->getMessage());
    }

    // ─── equipos.nombre ─────────────────────────────────────────────────────

    public function test_dos_equipos_no_pueden_llamarse_igual(): void
    {
        $equipos = new EquipoModel();
        $equipos->insert(['nombre' => 'Los Halcones', 'estado' => 'activo']);

        $this->assertLaBaseRechaza(
            fn() => $equipos->insert(['nombre' => 'Los Halcones', 'estado' => 'activo']),
            'uq_equipos_nombre');

        // El índice usa la misma comparación que el resto de la base: no distingue
        // mayúsculas ni acentos, igual que EquipoModel::nombreExiste().
        $this->assertLaBaseRechaza(
            fn() => $equipos->insert(['nombre' => 'LOS HALCONES', 'estado' => 'activo']),
            'uq_equipos_nombre');

        $this->assertDoesNotThrow(
            fn() => $equipos->insert(['nombre' => 'Los Halcones del Sur', 'estado' => 'activo']),
            'un nombre distinto tiene que poder usarse');
    }

    public function test_el_servicio_de_equipos_avisa_antes_que_el_motor(): void
    {
        $svc = new EquipoService();
        $svc->crear(['nombre' => 'Los Pumas']);

        $e = $this->assertThrows(fn() => $svc->crear(['nombre' => 'Los Pumas']), 'Ya existe un equipo');
        $this->assertTrue($e instanceof RuntimeException, 'el aviso al usuario es un RuntimeException, no un error de SQL');
    }

    // ─── participantes.documento ────────────────────────────────────────────

    public function test_dos_participantes_no_pueden_tener_el_mismo_documento(): void
    {
        $model = new ParticipanteModel();
        $model->insert(['nombre' => 'Ana', 'documento' => '4000111', 'estado' => 'activo']);

        $this->assertLaBaseRechaza(
            fn() => $model->insert(['nombre' => 'Otra Ana', 'documento' => '4000111', 'estado' => 'activo']),
            'uq_participantes_documento');
    }

    public function test_varios_participantes_pueden_no_tener_documento(): void
    {
        // «Sin documento» es NULL, y el índice único admite cualquier cantidad de NULL.
        $model = new ParticipanteModel();
        $this->assertDoesNotThrow(function () use ($model) {
            $model->insert(['nombre' => 'Sin documento 1', 'estado' => 'activo']);
            $model->insert(['nombre' => 'Sin documento 2', 'estado' => 'activo']);
            $model->insert(['nombre' => 'Sin documento 3', 'estado' => 'activo']);
        });
    }

    public function test_editar_un_participante_sin_documento_guarda_null_y_no_cadena_vacia(): void
    {
        // Este es el caso que rompía con el índice único: el formulario manda el
        // campo vacío, el servicio guardaba '' y el SEGUNDO participante editado
        // chocaba con el primero.
        [$a, $b] = Fixtures::participantes(2);
        $svc = new ParticipanteService();

        $this->assertDoesNotThrow(function () use ($svc, $a, $b) {
            $svc->editar($a, ['nombre' => 'Editado A', 'documento' => '',    'estado' => 'activo']);
            $svc->editar($b, ['nombre' => 'Editado B', 'documento' => '   ', 'estado' => 'activo']);
        }, 'dos participantes sin documento tienen que poder editarse');

        foreach ([$a, $b] as $id) {
            // fetchColumn() devuelve null para un NULL de SQL y '' para una cadena
            // vacía: assertSame (===) distingue una cosa de la otra.
            $this->assertSame(null,
                $this->db->query("SELECT documento FROM participantes WHERE id = {$id}")->fetchColumn(),
                'sin documento se guarda NULL, no una cadena vacía');
        }
    }

    public function test_el_servicio_de_participantes_avisa_antes_que_el_motor(): void
    {
        [$a, $b] = Fixtures::participantes(2);
        $svc = new ParticipanteService();
        $svc->editar($a, ['nombre' => 'Ana', 'documento' => '4000222', 'estado' => 'activo']);

        $e = $this->assertThrows(
            fn() => $svc->editar($b, ['nombre' => 'Bea', 'documento' => '4000222', 'estado' => 'activo']),
            'Ya existe otro participante con ese documento');
        $this->assertTrue($e instanceof RuntimeException);
    }

    public function test_el_autorregistro_rechaza_un_documento_repetido_con_un_mensaje_claro(): void
    {
        // Antes del índice único el registro dejaba pasar el documento repetido en
        // silencio; con el índice y sin esta validación, lo rechazaría el motor con
        // un error de SQL que la persona no entiende.
        $registro = new RegistroService();
        $base = ['nombre' => 'Persona', 'password' => 'Clave-Segura1!', 'password_confirm' => 'Clave-Segura1!',
                 'documento' => '4000333', 'acepto_politicas' => '1'];

        $registro->registrar($base + ['email' => 'uno' . Fixtures::DOMINIO_TEST]);

        $e = $this->assertThrows(
            fn() => $registro->registrar($base + ['email' => 'dos' . Fixtures::DOMINIO_TEST]),
            'ese documento');
        $this->assertTrue($e instanceof RuntimeException);
    }

    // ─── puntos no negativos ────────────────────────────────────────────────

    private function unPartido(): int
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        return Fixtures::partidosPendientes($torneoId)[0];
    }

    private function insertarResultado(int $partido, float $a, float $b): void
    {
        $this->db->prepare(
            'INSERT INTO resultados (enfrentamiento_id, puntos_a, puntos_b) VALUES (:e, :a, :b)'
        )->execute([':e' => $partido, ':a' => $a, ':b' => $b]);
    }

    public function test_un_resultado_no_puede_tener_puntos_negativos(): void
    {
        $partido = $this->unPartido();

        $this->assertLaBaseRechaza(fn() => $this->insertarResultado($partido, -1, 2), 'chk_resultados_puntos');
        $this->assertLaBaseRechaza(fn() => $this->insertarResultado($partido, 2, -0.5), 'chk_resultados_puntos');

        // Cero es un marcador válido (0-0 en un partido donde se permiten empates).
        $this->assertDoesNotThrow(fn() => $this->insertarResultado($partido, 0, 0));
    }

    public function test_una_solicitud_de_correccion_no_puede_traer_puntos_negativos(): void
    {
        $partido  = $this->unPartido();
        $torneoId = (int) $this->db->query("SELECT torneo_id FROM enfrentamientos WHERE id = {$partido}")->fetchColumn();

        $solicitar = fn(float $a, float $b) => $this->db->prepare(
            'INSERT INTO solicitudes_correccion (enfrentamiento_id, torneo_id, puntos_a, puntos_b, motivo, solicitado_por)
             VALUES (:e, :t, :a, :b, :m, :u)'
        )->execute([':e' => $partido, ':t' => $torneoId, ':a' => $a, ':b' => $b,
                    ':m' => 'Motivo de prueba', ':u' => Fixtures::ADMIN]);

        $this->assertLaBaseRechaza(fn() => $solicitar(-3, 1), 'chk_solicitudes_puntos');
        $this->assertDoesNotThrow(fn() => $solicitar(3, 1));
    }

    // ─── fechas del torneo ──────────────────────────────────────────────────

    public function test_un_torneo_no_puede_terminar_antes_de_empezar(): void
    {
        $id = Fixtures::torneo('liga');
        $fechas = $this->db->prepare('UPDATE torneos SET fecha_inicio = :i, fecha_fin = :f WHERE id = :id');

        $this->assertLaBaseRechaza(
            fn() => $fechas->execute([':i' => '2026-12-10', ':f' => '2026-12-01', ':id' => $id]),
            'chk_torneos_fechas');

        // Empezar y terminar el mismo día es un torneo de un día: válido.
        $this->assertDoesNotThrow(fn() => $fechas->execute([':i' => '2026-12-10', ':f' => '2026-12-10', ':id' => $id]));

        // Sin fechas todavía (borrador) tampoco es un error.
        $this->assertDoesNotThrow(fn() => $fechas->execute([':i' => null, ':f' => null, ':id' => $id]));
    }

    // ─── un solo competidor por fila ────────────────────────────────────────

    public function test_una_inscripcion_es_de_un_solo_competidor(): void
    {
        [$pid]  = Fixtures::participantes(1);
        [$eid]  = Fixtures::equipos(1, [$pid]);
        $tid    = Fixtures::torneo('liga');
        $insertar = fn(?int $p, ?int $e) => $this->db->prepare(
            'INSERT INTO inscripciones (torneo_id, participante_id, equipo_id) VALUES (:t, :p, :e)'
        )->execute([':t' => $tid, ':p' => $p, ':e' => $e]);

        $this->assertLaBaseRechaza(fn() => $insertar($pid, $eid),   'chk_inscripcion_un_competidor');
        $this->assertLaBaseRechaza(fn() => $insertar(null, null),   'chk_inscripcion_un_competidor');
        $this->assertDoesNotThrow(fn() => $insertar($pid, null));
        $this->assertDoesNotThrow(fn() => $insertar(null, $eid));
    }

    public function test_una_fila_de_posiciones_es_de_un_solo_competidor(): void
    {
        [$pid]  = Fixtures::participantes(1);
        [$eid]  = Fixtures::equipos(1, [$pid]);
        $tid    = Fixtures::torneo('liga');
        $insertar = fn(?int $p, ?int $e) => $this->db->prepare(
            'INSERT INTO tabla_posiciones (torneo_id, participante_id, equipo_id) VALUES (:t, :p, :e)'
        )->execute([':t' => $tid, ':p' => $p, ':e' => $e]);

        $this->assertLaBaseRechaza(fn() => $insertar($pid, $eid), 'chk_tabla_un_competidor');
        $this->assertLaBaseRechaza(fn() => $insertar(null, null), 'chk_tabla_un_competidor');
        $this->assertDoesNotThrow(fn() => $insertar($pid, null));
        $this->assertDoesNotThrow(fn() => $insertar(null, $eid));
    }

    // ─── formato → módulo ───────────────────────────────────────────────────

    public function test_cada_formato_pertenece_a_un_modulo_y_lo_cuida_el_motor(): void
    {
        $filas = $this->db->query('SELECT slug, modulo_slug FROM tipos_torneo ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([
            'liga' => 'liga', 'eliminacion_directa' => 'eliminacion_directa', 'suizo' => 'suizo',
        ], $filas, 'los tres formatos del seed apuntan al módulo que los implementa');

        // No existe un formato sin módulo…
        $this->assertLaBaseRechaza(
            fn() => $this->db->exec("INSERT INTO tipos_torneo (nombre, slug, modulo_slug) VALUES ('Falso', 'falso', 'no_existe')"),
            'fk_tipos_torneo_modulo');

        // …ni se puede borrar un módulo que todavía tiene formatos.
        $this->assertLaBaseRechaza(
            fn() => $this->db->exec("DELETE FROM modulos WHERE slug = 'liga'"),
            'fk_tipos_torneo_modulo');
    }

    public function test_si_un_modulo_cambia_de_slug_sus_formatos_y_permisos_lo_siguen(): void
    {
        // ON UPDATE CASCADE. Todo dentro de una transacción que se deshace.
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE modulos SET slug = 'liga_v2' WHERE slug = 'liga'");

            $this->assertSame('liga_v2',
                $this->db->query("SELECT modulo_slug FROM tipos_torneo WHERE slug = 'liga'")->fetchColumn(),
                'el formato sigue a su módulo');
            $this->assertSame('liga',
                $this->db->query("SELECT slug FROM tipos_torneo WHERE id = 1")->fetchColumn(),
                'el slug del formato no cambia: es otra cosa que el del módulo');
            $this->assertSame('0',
                (string) $this->db->query("SELECT COUNT(*) FROM permisos WHERE modulo_slug = 'liga'")->fetchColumn(),
                'los permisos también lo siguen');
        } finally {
            $this->db->rollBack();
        }
    }

    public function test_solo_se_ofrecen_los_formatos_cuyo_modulo_esta_encendido(): void
    {
        $this->db->exec("UPDATE modulos SET estado = 'inactivo' WHERE slug = 'suizo'");
        try {
            $ofrecidos = array_column((new TipoTorneoModel())->findDisponibles(), 'slug');
            $this->assertSame(['liga', 'eliminacion_directa'], $ofrecidos);

            // Un torneo que ya tenía ese formato lo conserva en su formulario, marcado.
            $conActual = (new TipoTorneoModel())->findDisponibles(3);
            $suizo = array_values(array_filter($conActual, fn($t) => $t['slug'] === 'suizo'))[0] ?? null;
            $this->assertNotNull($suizo, 'el formato actual del torneo sigue apareciendo');
            $this->assertSame(0, (int) $suizo['modulo_activo']);
        } finally {
            $this->db->exec("UPDATE modulos SET estado = 'activo'");
        }
    }

    // ─── tablas y columnas nuevas ───────────────────────────────────────────

    public function test_la_configuracion_del_sistema_trae_sus_valores_por_omision(): void
    {
        $filas = $this->db->query('SELECT clave, valor FROM configuracion_sistema')->fetchAll(PDO::FETCH_KEY_PAIR);
        ksort($filas);
        $esperado = self::CONFIG_POR_OMISION;
        ksort($esperado);

        $this->assertSame($esperado, $filas);

        $sinDescripcion = (int) $this->db->query(
            "SELECT COUNT(*) FROM configuracion_sistema WHERE descripcion IS NULL OR descripcion = ''"
        )->fetchColumn();
        $this->assertSame(0, $sinDescripcion, 'cada ajuste tiene que explicar para qué sirve');
    }

    public function test_un_bloqueo_puede_tener_fecha_de_vencimiento(): void
    {
        // Por omisión no vence: NULL con estado 'bloqueada' es un bloqueo manual.
        $this->assertSame(null,
            $this->db->query('SELECT bloqueada_hasta FROM usuarios WHERE id = ' . Fixtures::ADMIN)->fetchColumn(),
            'una cuenta sin bloquear no tiene fecha de vencimiento');

        $id = Fixtures::usuario('Con bloqueo', 'bloqueo' . Fixtures::DOMINIO_TEST, 'Clave-Segura1!', 3);
        $this->db->prepare('UPDATE usuarios SET bloqueada_hasta = :h WHERE id = :id')
                 ->execute([':h' => '2031-05-04 10:30:00', ':id' => $id]);

        $this->assertSame('2031-05-04 10:30:00',
            $this->db->query("SELECT bloqueada_hasta FROM usuarios WHERE id = {$id}")->fetchColumn());
    }

    public function test_los_intentos_de_login_guardan_ip_email_y_momento(): void
    {
        $this->db->exec("DELETE FROM intentos_login");
        $this->db->prepare('INSERT INTO intentos_login (ip, email) VALUES (:ip, :e), (:ip6, NULL)')
                 ->execute([':ip' => '203.0.113.9', ':e' => 'alguien@example.com',
                            ':ip6' => '2001:db8:85a3:8d3:1319:8a2e:370:7348']);

        $filas = $this->db->query('SELECT ip, email, created_at FROM intentos_login ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $filas);
        $this->assertSame('alguien@example.com', $filas[0]['email']);
        $this->assertSame(null, $filas[1]['email'], 'el email es opcional');
        $this->assertSame('2001:db8:85a3:8d3:1319:8a2e:370:7348', $filas[1]['ip'], 'cabe una IPv6 completa');
        $this->assertNotNull($filas[0]['created_at'], 'el momento lo pone la base');

        $this->db->exec("DELETE FROM intentos_login");
    }
}

TestCase::correrSiEsScript(EsquemaIntegridadTest::class);
