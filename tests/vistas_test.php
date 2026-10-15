<?php
declare(strict_types=1);

/**
 * Test de las vistas SQL (database/schema.sql, migración 2026_10_vistas.sql).
 *
 * Una vista que reemplaza una consulta tiene que devolver lo mismo que ella. Por eso
 * acá cada vista se compara contra LA CONSULTA ANTERIOR, copiada tal cual como
 * referencia: si difieren, o la vista está mal o el cambio de comportamiento hay que
 * decirlo en voz alta. Las dos que corrigen un defecto (qué torneos son públicos y
 * cuántos inscritos tienen) tienen casos propios que dicen qué cambió.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/vistas_test.php
 */

require __DIR__ . '/bootstrap.php';

class VistasTest extends TestCase
{
    private const VISTAS = [
        'v_torneos_publicos', 'v_calendario', 'v_posiciones',
        'v_estadisticas_sistema', 'v_participantes_publicos',
    ];

    /** Las consultas que las vistas reemplazaron, tal como estaban en los modelos. */
    private const CONSULTA_ANTERIOR_PARTIDOS =
        "SELECT enf.*,
                pa.nombre AS participante_a_nombre, pa.nick AS nick_a,
                pb.nombre AS participante_b_nombre, pb.nick AS nick_b,
                ea.nombre AS equipo_a_nombre,
                eb.nombre AS equipo_b_nombre
           FROM enfrentamientos enf
           LEFT JOIN participantes pa ON pa.id = enf.participante_a_id
           LEFT JOIN participantes pb ON pb.id = enf.participante_b_id
           LEFT JOIN equipos ea ON ea.id = enf.equipo_a_id
           LEFT JOIN equipos eb ON eb.id = enf.equipo_b_id
          WHERE enf.ronda_id = :rid
          ORDER BY enf.orden ASC";

    private const CONSULTA_ANTERIOR_POSICIONES =
        "SELECT tp.*,
                p.nombre AS participante_nombre, p.nick,
                e.nombre AS equipo_nombre
           FROM tabla_posiciones tp
           LEFT JOIN participantes p ON p.id = tp.participante_id
           LEFT JOIN equipos e ON e.id = tp.equipo_id
          WHERE tp.torneo_id = :tid
          ORDER BY tp.posicion ASC";

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    /** @return string[] los nombres de las columnas de una tabla o vista, en orden */
    private function columnas(string $objeto): array
    {
        $st = $this->db->prepare(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$objeto]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Cada fila nueva tiene que traer, con el mismo valor, todo lo que traía la
     * consulta anterior. Puede tener MÁS columnas (las vistas dan más), no menos.
     *
     * @param array<int, array<string, mixed>> $anteriores
     * @param array<int, array<string, mixed>> $nuevas
     */
    private function assertReemplazoDirecto(array $anteriores, array $nuevas, string $que): void
    {
        $this->assertCount(count($anteriores), $nuevas, "{$que}: distinta cantidad de filas");
        foreach ($anteriores as $i => $vieja) {
            foreach ($vieja as $columna => $valor) {
                $this->assertTrue(array_key_exists($columna, $nuevas[$i]),
                    "{$que}, fila {$i}: falta la columna «{$columna}»");
                $this->assertSame($valor, $nuevas[$i][$columna],
                    "{$que}, fila {$i}, columna «{$columna}»");
            }
        }
    }

    // ─── Que existan, y que el esquema y la migración digan lo mismo ────────

    public function test_las_cinco_vistas_existen(): void
    {
        $hay = $this->db->query(
            "SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE() ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $esperadas = self::VISTAS;
        sort($esperadas);

        $this->assertSame($esperadas, array_map('strval', $hay));
    }

    public function test_el_bloque_de_vistas_es_el_mismo_en_el_esquema_y_en_la_migracion(): void
    {
        $bloque = function (string $archivo): string {
            $sql = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/' . $archivo));
            $this->assertTrue((bool) preg_match('/-- >>> VISTAS.*?-- <<< VISTAS/s', $sql, $m), "{$archivo} no tiene el bloque de vistas");
            return $m[0];
        };

        $this->assertSame($bloque('database/migrations/2026_10_vistas.sql'), $bloque('database/schema.sql'),
            'el bloque de vistas de schema.sql y el de la migración tienen que ser idénticos');
    }

    public function test_las_vistas_con_select_estrella_no_se_quedaron_atras_de_su_tabla(): void
    {
        // «SELECT t.*» en una vista fija las columnas que la tabla tenía al crearla. Si
        // la tabla gana una, la vista no la trae hasta que se la recree: sin este test
        // la columna nueva faltaría en silencio.
        foreach ([
            'v_torneos_publicos' => 'torneos',
            'v_calendario'       => 'enfrentamientos',
            'v_posiciones'       => 'tabla_posiciones',
        ] as $vista => $tabla) {
            $faltan = array_diff($this->columnas($tabla), $this->columnas($vista));
            $this->assertCount(0, array_values($faltan),
                "{$vista} no trae las columnas de {$tabla}: " . implode(', ', $faltan) . ' (hay que recrearla)');
        }
    }

    // ─── v_torneos_publicos ─────────────────────────────────────────────────

    /** Crea un torneo con el estado y la visibilidad indicados. */
    private function torneo(string $nombre, string $estado, bool $publico): int
    {
        return Fixtures::torneo('liga', [
            'nombre'  => $nombre,
            'estado'  => $estado,
            'publico' => $publico ? '1' : null,
        ]);
    }

    public function test_un_torneo_en_borrador_o_privado_no_es_publico(): void
    {
        $borrador = $this->torneo('Borrador publico', 'borrador', true);
        $privado  = $this->torneo('Privado',          'inscripcion', false);
        $visible  = $this->torneo('Visible',          'inscripcion', true);
        $enCurso  = $this->torneo('En curso',         'en_curso', true);
        $cerrado  = $this->torneo('Finalizado',       'finalizado', true);

        $enLaVista = array_map('intval', $this->db->query('SELECT id FROM v_torneos_publicos')->fetchAll(PDO::FETCH_COLUMN));
        sort($enLaVista);
        $this->assertSame([$visible, $enCurso, $cerrado], $enLaVista,
            'un torneo en borrador (todavía se está armando) o privado no se ve');

        $model = new TorneoModel();
        $this->assertFalse($model->esPublico($borrador), 'ni por enlace directo: antes el detalle mostraba los borradores');
        $this->assertFalse($model->esPublico($privado));
        $this->assertTrue($model->esPublico($visible));
    }

    public function test_el_listado_omite_los_cancelados_pero_el_enlace_directo_sigue_andando(): void
    {
        $visible   = $this->torneo('Visible',   'inscripcion', true);
        $cancelado = $this->torneo('Cancelado', 'cancelado',   true);

        $listados = array_map(fn($t) => (int) $t['id'], (new TorneoModel())->findPublicos());
        $this->assertSame([$visible], $listados, 'el listado no muestra los cancelados');

        // Como antes: quien tiene el enlace de un torneo cancelado lo sigue viendo,
        // con su estado, en vez de un 404.
        $this->assertTrue((new TorneoModel())->esPublico($cancelado));
    }

    public function test_el_listado_publico_conserva_todas_las_columnas_que_traia(): void
    {
        $this->torneo('Visible', 'inscripcion', true);

        $fila = (new TorneoModel())->findPublicos()[0];
        foreach (array_merge($this->columnas('torneos'), ['tipo_nombre', 'tipo_slug']) as $columna) {
            $this->assertTrue(array_key_exists($columna, $fila), "findPublicos() ya no trae «{$columna}»");
        }
        $this->assertSame('liga', $fila['tipo_slug']);
    }

    public function test_los_inscritos_cuentan_solo_las_inscripciones_activas(): void
    {
        // Antes se contaban todas las filas de inscripciones, incluidas las retiradas:
        // la lista pública decía «4 participantes» con uno que ya se había ido.
        $tid  = $this->torneo('Con retirados', 'inscripcion', true);
        $pids = Fixtures::participantes(4);
        Fixtures::inscribir($tid, $pids);
        (new InscripcionService())->desinscribir($tid, $pids[3], null);

        $this->assertSame(4, (int) $this->db->query("SELECT COUNT(*) FROM inscripciones WHERE torneo_id = {$tid}")->fetchColumn(),
            'control: la fila retirada sigue existiendo');

        $enLaVista = (int) $this->db->query("SELECT inscritos FROM v_torneos_publicos WHERE id = {$tid}")->fetchColumn();
        $this->assertSame(3, $enLaVista, 'la vista cuenta las activas');
        $this->assertSame(3, (new TorneoModel())->contarInscritos($tid));

        $fila = array_values(array_filter((new TorneoModel())->findAllConTipo(), fn($t) => (int)$t['id'] === $tid))[0];
        $this->assertSame(3, (int) $fila['inscritos'], 'el listado del administrador también');
    }

    // ─── v_calendario ───────────────────────────────────────────────────────

    /** @return int[] los ids de las rondas de un torneo */
    private function rondasDe(int $torneoId): array
    {
        return array_map('intval', $this->db->query("SELECT id FROM rondas WHERE torneo_id = {$torneoId} ORDER BY numero")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function filasAnterioresDePartidos(int $rondaId): array
    {
        $st = $this->db->prepare(self::CONSULTA_ANTERIOR_PARTIDOS);
        $st->execute([':rid' => $rondaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_v_calendario_reemplaza_la_consulta_de_partidos_sin_cambiar_nada(): void
    {
        // Liga individual, un bracket con byes y una liga por equipos: cubre los dos
        // tipos de lado y los partidos sin rival.
        [$liga]    = Fixtures::torneoArrancado('liga', 4);
        [$bracket] = Fixtures::torneoArrancado('eliminacion_directa', 5);   // 3 byes
        Fixtures::jugarTodo($bracket);                                       // rondas con resultado

        $pids = Fixtures::participantes(6, 'Eq');
        $equipos = Fixtures::equipos(4, $pids);
        $porEquipos = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'nombre' => 'Por equipos']);
        Fixtures::inscribir($porEquipos, $equipos, 'equipos');
        (new LigaService())->generarFixture($porEquipos);

        foreach ([$liga, $bracket, $porEquipos] as $torneo) {
            foreach ($this->rondasDe($torneo) as $rondaId) {
                $this->assertReemplazoDirecto(
                    $this->filasAnterioresDePartidos($rondaId),
                    (new EnfrentamientoModel())->getByRonda($rondaId),
                    "torneo {$torneo}, ronda {$rondaId}");
            }
        }
    }

    public function test_v_calendario_trae_ademas_el_resultado_el_ganador_y_los_nombres(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $partido = Fixtures::partidosPendientes($tid)[0];
        (new ResultadoService())->cargar($partido, 3.0, 1.0, Fixtures::ADMIN);

        $fila = $this->db->query("SELECT * FROM v_calendario WHERE id = {$partido}")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($fila['participante_a_nombre'], $fila['lado_a']);
        $this->assertSame($fila['participante_b_nombre'], $fila['lado_b']);
        $this->assertSame($fila['lado_a'], $fila['ganador'], 'con 3-1 gana el lado A');
        $this->assertSame('3.00', $fila['puntos_a']);
        $this->assertSame('1.00', $fila['puntos_b']);
        $this->assertSame('cargado', $fila['resultado_estado']);
        $this->assertSame('1', (string) $fila['ronda_numero']);
        $this->assertStringContainsString('Torneo de prueba', $fila['torneo_nombre']);

        // Un partido sin jugar no tiene resultado ni ganador.
        $otro = Fixtures::partidosPendientes($tid)[0];
        $sinJugar = $this->db->query("SELECT * FROM v_calendario WHERE id = {$otro}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(null, $sinJugar['puntos_a']);
        $this->assertSame(null, $sinJugar['ganador']);
        $this->assertSame(null, $sinJugar['resultado_estado']);
    }

    public function test_un_partido_con_bye_no_tiene_rival_en_el_calendario(): void
    {
        [$tid] = Fixtures::torneoArrancado('eliminacion_directa', 5);

        $byes = $this->db->query("SELECT lado_a, lado_b FROM v_calendario WHERE torneo_id = {$tid} AND es_bye = 1")
                         ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $byes);
        foreach ($byes as $bye) {
            $this->assertNotNull($bye['lado_a'], 'quien pasa por bye tiene nombre');
            $this->assertSame(null, $bye['lado_b'], 'y no tiene rival');
        }
    }

    // ─── v_posiciones ───────────────────────────────────────────────────────

    public function test_v_posiciones_reemplaza_la_consulta_de_la_tabla_sin_cambiar_nada(): void
    {
        [$individual] = Fixtures::torneoArrancado('liga', 4);
        Fixtures::jugarTodo($individual);

        $pids = Fixtures::participantes(6, 'Eq');
        $equipos = Fixtures::equipos(3, $pids);
        $porEquipos = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'nombre' => 'Por equipos']);
        Fixtures::inscribir($porEquipos, $equipos, 'equipos');
        (new LigaService())->generarFixture($porEquipos);
        Fixtures::jugarTodo($porEquipos);

        foreach ([$individual, $porEquipos] as $tid) {
            $st = $this->db->prepare(self::CONSULTA_ANTERIOR_POSICIONES);
            $st->execute([':tid' => $tid]);
            $anteriores = $st->fetchAll(PDO::FETCH_ASSOC);
            $this->assertGreaterThan(0, count($anteriores), 'control: hay posiciones que comparar');

            $this->assertReemplazoDirecto($anteriores, (new TablaPosicionesModel())->getByTorneo($tid), "torneo {$tid}");
        }
    }

    public function test_v_posiciones_trae_el_nombre_del_competidor_sea_participante_o_equipo(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $fila = $this->db->query("SELECT * FROM v_posiciones WHERE torneo_id = {$tid} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($fila['participante_nombre'], $fila['competidor']);
        $this->assertSame(null, $fila['equipo_nombre']);

        $pids = Fixtures::participantes(3, 'Eq');
        $equipos = Fixtures::equipos(2, $pids);
        $porEquipos = Fixtures::torneo('liga', ['modalidad' => 'equipos', 'min_integrantes_equipo' => 1, 'nombre' => 'Por equipos']);
        Fixtures::inscribir($porEquipos, $equipos, 'equipos');
        (new LigaService())->generarFixture($porEquipos);

        $fila = $this->db->query("SELECT * FROM v_posiciones WHERE torneo_id = {$porEquipos} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($fila['equipo_nombre'], $fila['competidor']);
        $this->assertSame(null, $fila['participante_nombre']);
    }

    // ─── v_estadisticas_sistema ─────────────────────────────────────────────

    public function test_las_estadisticas_coinciden_con_contar_directamente(): void
    {
        $this->torneo('Uno', 'borrador', true);
        $this->torneo('Dos', 'inscripcion', true);
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        Fixtures::jugarTodo($tid);
        Fixtures::equipos(2, Fixtures::participantes(3, 'Es'));

        $fila = (new EstadisticasModel())->get();

        $directo = fn(string $sql): int => (int) $this->db->query($sql)->fetchColumn();
        $this->assertSame($directo('SELECT COUNT(*) FROM torneos'), $fila['torneos_total']);
        $this->assertSame($directo("SELECT COUNT(*) FROM torneos WHERE estado = 'borrador'"), $fila['torneos_borrador']);
        $this->assertSame($directo("SELECT COUNT(*) FROM torneos WHERE estado = 'finalizado'"), $fila['torneos_finalizados']);
        $this->assertSame($directo('SELECT COUNT(*) FROM participantes'), $fila['participantes_total']);
        $this->assertSame($directo("SELECT COUNT(*) FROM participantes WHERE estado = 'activo'"), $fila['participantes_activos']);
        $this->assertSame($directo('SELECT COUNT(*) FROM equipos'), $fila['equipos_total']);
        $this->assertSame($directo("SELECT COUNT(*) FROM enfrentamientos WHERE estado = 'finalizado'"), $fila['partidos_jugados']);
        $this->assertGreaterThan(0, $fila['partidos_jugados'], 'control: hubo partidos jugados');

        // Y es lo que muestra el panel del administrador.
        $this->assertSame($fila['torneos_total'], $directo('SELECT COUNT(*) FROM torneos'));
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM v_estadisticas_sistema')->fetchColumn(), 'una sola fila');
    }

    // ─── v_participantes_publicos ───────────────────────────────────────────

    public function test_la_vista_publica_de_participantes_no_tiene_datos_de_contacto(): void
    {
        $this->assertSame(['id', 'nombre', 'nick', 'foto', 'estado', 'created_at'], $this->columnas('v_participantes_publicos'));
    }

    public function test_el_perfil_publico_no_recibe_documento_email_ni_telefono(): void
    {
        $model = new ParticipanteModel();
        $id = $model->insert([
            'nombre' => 'Persona Publica', 'nick' => 'pub', 'estado' => 'activo',
            'documento' => '4999111', 'email' => 'privado@example.com', 'telefono' => '099123456',
        ]);

        $perfil = $model->findPublicoConEquipos($id);

        $this->assertNotNull($perfil);
        $this->assertSame('Persona Publica', $perfil['nombre']);
        foreach (['documento', 'email', 'telefono', 'usuario_id'] as $reservado) {
            $this->assertFalse(array_key_exists($reservado, $perfil), "el perfil público no debería traer «{$reservado}»");
        }
        $this->assertTrue(array_key_exists('email', $model->findByIdConEquipos($id) ?? []),
            'control: el perfil de uso interno sí lo trae');
    }

    public function test_un_participante_pendiente_o_rechazado_no_tiene_perfil_publico(): void
    {
        $model = new ParticipanteModel();
        foreach (['pendiente', 'rechazado'] as $estado) {
            $id = $model->insert(['nombre' => "Persona {$estado}", 'estado' => $estado]);
            $this->assertSame(null, $model->findPublicoConEquipos($id), "un perfil «{$estado}» no es público");
        }
        $this->assertNotNull($model->findPublicoConEquipos($model->insert(['nombre' => 'Activa', 'estado' => 'activo'])));
    }

    public function test_el_equipo_publico_muestra_sus_integrantes_sin_datos_de_contacto(): void
    {
        $model = new ParticipanteModel();
        $a = $model->insert(['nombre' => 'Integrante A', 'estado' => 'activo',    'email' => 'a@example.com', 'documento' => '4111']);
        $b = $model->insert(['nombre' => 'Integrante B', 'estado' => 'pendiente', 'email' => 'b@example.com', 'documento' => '4222']);
        $equipoModel = new EquipoModel();
        $eid = $equipoModel->insert(['nombre' => 'Equipo publico', 'estado' => 'activo']);
        $equipoModel->agregarParticipante($eid, $a, 'capitan');
        $equipoModel->agregarParticipante($eid, $b, 'jugador');

        $equipo = $equipoModel->findPublicoConParticipantes($eid);

        $this->assertCount(1, $equipo['participantes'], 'el integrante pendiente de aprobación no se muestra');
        $this->assertSame('Integrante A', $equipo['participantes'][0]['nombre']);
        $this->assertSame('capitan', $equipo['participantes'][0]['rol_en_equipo']);
        $this->assertFalse(array_key_exists('email', $equipo['participantes'][0]));
        $this->assertFalse(array_key_exists('documento', $equipo['participantes'][0]));
    }

    public function test_los_inscritos_de_la_pagina_publica_no_traen_el_email(): void
    {
        $tid = $this->torneo('Con inscritos', 'inscripcion', true);
        $pid = (new ParticipanteModel())->insert(['nombre' => 'Inscrito', 'estado' => 'activo', 'email' => 'inscrito@example.com']);
        Fixtures::inscribir($tid, [$pid]);

        $publico = (new InscripcionModel())->getByTorneoPublico($tid);
        $this->assertCount(1, $publico);
        $this->assertSame('Inscrito', $publico[0]['participante_nombre']);
        $this->assertFalse(array_key_exists('participante_email', $publico[0]), 'el visitante no necesita el email');

        $interno = (new InscripcionModel())->getByTorneo($tid);
        $this->assertSame('inscrito@example.com', $interno[0]['participante_email'], 'control: la gestión interna sí lo usa');
    }
}

TestCase::correrSiEsScript(VistasTest::class);
