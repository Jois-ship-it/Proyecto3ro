<?php
declare(strict_types=1);

/**
 * Test del historial de la base de datos (historial_bd y sus 51 triggers).
 *
 * Tres cosas distintas, y las tres importan:
 *
 *  1. Que lo versionado sea lo que el generador produce HOY. Los triggers copian columnas
 *     una por una; una columna agregada a una tabla y no regenerada quedaría sin registrar
 *     en silencio. Estos tests lo convierten en un fallo ruidoso.
 *
 *  2. Que registre bien: antes y después de cada cambio, quién, y nada de ruido.
 *
 *  3. Que NO registre lo que no debe. El historial no lo puede modificar la aplicación; lo
 *     que entre queda para siempre. Si guardara contraseñas o datos personales, la
 *     anonimización que exige la ley de datos personales (Habeas Data) no serviría de nada.
 *     El test de más abajo anonimiza a una persona y comprueba que no queda ni rastro.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/historial_bd_test.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/database/generar_historial.php';

class HistorialBdTest extends TestCase
{
    /**
     * Algunos casos cambian ajustes del sistema. Se los devuelve a su valor de fábrica
     * antes de cada caso (por si uno anterior se cortó) y al terminar (para los demás tests).
     */
    private function restaurarAjustes(): void
    {
        $this->db->exec("UPDATE configuracion_sistema SET valor = 'FlexArena' WHERE clave = 'sitio_nombre'");
        $this->db->exec("UPDATE configuracion_sistema SET valor = ''          WHERE clave = 'sitio_contacto'");
    }

    protected function setUp(): void
    {
        $this->restaurarAjustes();
        Fixtures::reset($this->db);      // también vacía lo que acaba de registrar el historial
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    protected function tearDown(): void
    {
        $this->restaurarAjustes();
        Fixtures::cerrarSesion();
    }

    /** @return array<int, array<string, mixed>> filas del historial, con el JSON ya decodificado */
    private function historia(string $tabla, ?string $registroId = null): array
    {
        $sql = 'SELECT * FROM historial_bd WHERE tabla = ?' . ($registroId !== null ? ' AND registro_id = ?' : '') . ' ORDER BY id';
        $st  = $this->db->prepare($sql);
        $st->execute($registroId !== null ? [$tabla, $registroId] : [$tabla]);
        return array_map(function (array $f) {
            $f['valor_anterior'] = $f['valor_anterior'] === null ? null : json_decode((string) $f['valor_anterior'], true);
            $f['valor_nuevo']    = $f['valor_nuevo']    === null ? null : json_decode((string) $f['valor_nuevo'], true);
            return $f;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function contarHistoria(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM historial_bd')->fetchColumn();
    }

    // ─── 1) Lo versionado es lo que el generador produce hoy ────────────────

    public function test_lo_versionado_coincide_con_lo_que_genera_el_script_hoy(): void
    {
        $hoy = GeneradorHistorial::bloque($this->db);

        foreach (['database/schema.sql', 'database/migrations/2026_10_historial_bd.sql'] as $archivo) {
            $versionado = GeneradorHistorial::extraer((string) file_get_contents(dirname(__DIR__) . '/' . $archivo));
            $this->assertNotNull($versionado, "{$archivo} no tiene el bloque del historial");
            $this->assertTrue($versionado === $hoy,
                "{$archivo} no coincide con lo que genera database/generar_historial.php: "
                . 'probablemente una tabla cambió. Volver a correr: php database/generar_historial.php');
        }
    }

    public function test_cada_tabla_de_datos_se_registra_o_esta_excluida_con_su_motivo(): void
    {
        $todas = array_map('strval', $this->db->query(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));

        // Una tabla nueva obliga a decidir: registrarla (el generador la toma sola) o
        // sumarla a EXCLUIDAS con el motivo. No hay tercera opción.
        $registradas = GeneradorHistorial::tablasRegistradas($this->db);
        $clasificadas = array_merge($registradas, array_keys(GeneradorHistorial::EXCLUIDAS));
        sort($clasificadas);
        sort($todas);
        $this->assertSame($todas, $clasificadas);

        foreach (GeneradorHistorial::EXCLUIDAS as $tabla => $motivo) {
            $this->assertTrue(in_array($tabla, $todas, true), "EXCLUIDAS nombra «{$tabla}», que no existe");
            $this->assertTrue(strlen($motivo) > 20, "la exclusión de «{$tabla}» no explica el motivo");
        }
    }

    public function test_cada_tabla_registrada_tiene_exactamente_sus_tres_triggers(): void
    {
        $filas = $this->db->query(
            "SELECT event_object_table AS tabla, action_timing AS momento, event_manipulation AS evento
               FROM information_schema.triggers WHERE trigger_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_ASSOC);

        $porTabla = [];
        foreach ($filas as $f) $porTabla[$f['tabla']][] = $f['momento'] . ' ' . $f['evento'];

        $registradas = GeneradorHistorial::tablasRegistradas($this->db);
        $this->assertSame(array_values($registradas), array_values(array_keys(array_intersect_key($porTabla, array_flip($registradas)))),
            'hay tablas registradas sin triggers');
        $this->assertCount(count($registradas), $porTabla, 'hay triggers de historial en tablas que no deberían tenerlos');

        foreach ($registradas as $tabla) {
            $esperados = ['AFTER DELETE', 'AFTER INSERT', 'AFTER UPDATE'];
            $reales    = $porTabla[$tabla];
            sort($reales);
            $this->assertSame($esperados, $reales, "los triggers de {$tabla}");
        }
    }

    public function test_ninguna_columna_queda_fuera_del_historial_sin_estar_declarada_como_reservada(): void
    {
        $olvidadas = [];
        foreach (GeneradorHistorial::tablasRegistradas($this->db) as $tabla) {
            $columnas   = GeneradorHistorial::columnasDe($this->db, $tabla);
            [$copiadas] = GeneradorHistorial::separarColumnas($tabla, $columnas);

            $cuerpo = (string) $this->db->query(
                "SELECT action_statement FROM information_schema.triggers
                  WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_hist_{$tabla}_ai'")->fetchColumn();

            foreach ($copiadas as $c) {
                if (!str_contains($cuerpo, "'{$c}'")) $olvidadas[] = "{$tabla}.{$c}";
            }
        }

        $this->assertCount(0, $olvidadas,
            'columnas que el trigger no copia ni están en RESERVADAS: ' . implode(', ', $olvidadas));
    }

    public function test_el_generador_se_niega_a_reservar_una_columna_que_no_existe(): void
    {
        $this->assertThrows(
            fn() => GeneradorHistorial::separarColumnas('usuarios', ['id', 'rol_id']),
            'password_hash');
    }

    // ─── 2) Registra bien ───────────────────────────────────────────────────

    public function test_un_insert_un_update_y_un_delete_dejan_una_fila_cada_uno(): void
    {
        $equipos = new EquipoModel();
        $id = $equipos->insert(['nombre' => 'Los Historicos', 'estado' => 'activo', 'categoria' => 'A']);
        $equipos->update($id, ['categoria' => 'B']);
        $this->db->exec("DELETE FROM equipos WHERE id = {$id}");

        $h = $this->historia('equipos', (string) $id);
        $this->assertCount(3, $h);
        $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], array_column($h, 'operacion'));

        $this->assertSame(null, $h[0]['valor_anterior']);
        $this->assertSame('Los Historicos', $h[0]['valor_nuevo']['nombre']);

        $this->assertSame('A', $h[1]['valor_anterior']['categoria'], 'el UPDATE guarda cómo era');
        $this->assertSame('B', $h[1]['valor_nuevo']['categoria'],    'y cómo quedó');

        $this->assertSame('B', $h[2]['valor_anterior']['categoria'], 'el DELETE guarda lo que se borró');
        $this->assertSame(null, $h[2]['valor_nuevo']);
    }

    public function test_cada_fila_dice_quien_lo_hizo(): void
    {
        $id = (new EquipoModel())->insert(['nombre' => 'Atribuido', 'estado' => 'activo']);
        $h  = $this->historia('equipos', (string) $id);

        $this->assertSame(Fixtures::ADMIN, (int) $h[0]['usuario_app_id'], 'el usuario de la aplicación');
        $cuenta = (string) $this->db->query('SELECT USER()')->fetchColumn();
        $this->assertSame($cuenta, $h[0]['usuario_bd'], 'y la cuenta real de MySQL, que no se puede falsear');

        // Sin sesión, el cambio queda sin usuario de la aplicación pero con su cuenta de MySQL.
        Fixtures::cerrarSesion();
        $otro = (new EquipoModel())->insert(['nombre' => 'Anonimo', 'estado' => 'activo']);
        $h2   = $this->historia('equipos', (string) $otro);
        $this->assertSame(null, $h2[0]['usuario_app_id']);
        $this->assertSame($cuenta, $h2[0]['usuario_bd']);
    }

    public function test_un_update_que_no_cambia_nada_no_deja_rastro(): void
    {
        $id = (new EquipoModel())->insert(['nombre' => 'Quieto', 'estado' => 'activo']);
        $antes = $this->contarHistoria();

        $this->db->exec("UPDATE equipos SET nombre = nombre WHERE id = {$id}");
        $this->db->exec("UPDATE equipos SET updated_at = '2031-01-01 00:00:00' WHERE id = {$id}");   // se actualiza sola: no es un cambio

        $this->assertSame($antes, $this->contarHistoria(), 'ni «igual a sí mismo» ni solo updated_at cuentan como cambio');

        $this->db->exec("UPDATE equipos SET nombre = 'Movido' WHERE id = {$id}");
        $this->assertSame($antes + 1, $this->contarHistoria(), 'control: un cambio real sí se registra');
    }

    public function test_los_cambios_hechos_por_la_aplicacion_se_registran_con_su_atribucion(): void
    {
        // Un flujo real de punta a punta: el administrador crea un torneo y lo cancela.
        $id = Fixtures::torneo('liga', ['nombre' => 'Torneo registrado', 'estado' => 'borrador']);
        (new TorneoService())->eliminar($id);   // se cancela

        $h = $this->historia('torneos', (string) $id);
        $this->assertSame(['INSERT', 'UPDATE'], array_column($h, 'operacion'));
        $this->assertSame('borrador',  $h[1]['valor_anterior']['estado']);
        $this->assertSame('cancelado', $h[1]['valor_nuevo']['estado']);
        $this->assertSame(Fixtures::ADMIN, (int) $h[1]['usuario_app_id']);

        $this->assertGreaterThan(0, count($this->historia('torneo_organizadores')), 'y la asignación del organizador');
    }

    public function test_las_claves_compuestas_y_textuales_se_identifican_bien(): void
    {
        $torneo = Fixtures::torneo('liga');
        $filas = $this->historia('torneo_organizadores');
        $this->assertSame("{$torneo}-" . Fixtures::ORGANIZADOR, $filas[0]['registro_id'], 'torneo_organizadores usa «torneo-usuario»');

        $this->db->exec("UPDATE configuracion_sistema SET valor = 'OtroNombre' WHERE clave = 'sitio_nombre'");
        $h = $this->historia('configuracion_sistema', 'sitio_nombre');
        $this->assertCount(1, $h, 'configuracion_sistema se identifica por su clave');
        $this->assertSame('FlexArena', $h[0]['valor_anterior']['valor']);
        $this->assertSame('OtroNombre', $h[0]['valor_nuevo']['valor']);
    }

    public function test_la_tabla_de_posiciones_no_se_registra_pero_su_fuente_si(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        Fixtures::jugarTodo($tid);

        $this->assertCount(0, $this->historia('tabla_posiciones'),
            'es un dato derivado: se recalcula entera con cada resultado y llenaría el historial de ruido');
        $this->assertGreaterThan(0, count($this->historia('resultados')), 'la fuente (los resultados) sí queda');
        $this->assertGreaterThan(0, count($this->historia('enfrentamientos')));
    }

    public function test_el_resultado_corregido_deja_el_antes_y_el_despues(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $partido = Fixtures::partidosPendientes($tid)[0];
        (new ResultadoService())->cargar($partido, 2.0, 1.0, Fixtures::ADMIN);
        (new ResultadoService())->corregir($partido, 0.0, 3.0, 'Se anotó al revés el marcador', Fixtures::ADMIN);

        $h = $this->historia('resultados');
        $correccion = array_values(array_filter($h, fn($f) => $f['operacion'] === 'UPDATE'))[0] ?? null;
        $this->assertNotNull($correccion, 'la corrección es un UPDATE sobre el resultado');
        // Los DECIMAL salen como números JSON.
        $this->assertSame(2.0, $correccion['valor_anterior']['puntos_a']);
        $this->assertSame(0.0, $correccion['valor_nuevo']['puntos_a']);
        $this->assertSame(3.0, $correccion['valor_nuevo']['puntos_b']);
    }

    public function test_el_campo_de_cuenta_de_mysql_cabe_en_el_peor_caso(): void
    {
        // USER() puede medir hasta 32 (usuario) + 1 + 255 (host) = 288. Si la columna fuera más
        // corta, el trigger fallaría y haría fallar el cambio que quería registrar.
        $largo = (int) $this->db->query(
            "SELECT character_maximum_length FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'historial_bd' AND column_name = 'usuario_bd'")->fetchColumn();
        $this->assertTrue($largo >= 288, "usuario_bd mide {$largo}, tiene que alcanzar para cualquier USER()");
    }

    // ─── 3) No registra lo que no debe ──────────────────────────────────────

    public function test_el_historial_no_guarda_contrasenas_ni_datos_personales_ni_despues_de_anonimizar(): void
    {
        // anonimizarDatos() borra la foto con Upload, que ubica la carpeta con BASE_PATH.
        if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

        $uid = Fixtures::usuario('Maria Secreta', 'maria.secreta@test.local', 'Clave-Muy-Secreta1!', 3);
        $this->db->prepare('UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?')
                 ->execute(['Maria Nueva Apellido', 'maria.nueva@test.local', $uid]);
        $nuevoHash = password_hash('Otra-Clave-Secreta2!', PASSWORD_BCRYPT, ['cost' => 4]);
        (new UsuarioModel())->update($uid, ['password_hash' => $nuevoHash]);

        $pid = (new ParticipanteModel())->insert([
            'usuario_id' => $uid, 'estado' => 'activo',
            'nombre' => 'Maria Participante', 'documento' => '4777888', 'nick' => 'maria_nick',
            'email' => 'maria.part@test.local', 'telefono' => '098777666', 'foto' => 'foto_secreta.png',
        ]);
        // El flujo de Habeas Data: la persona pide que se borren sus datos.
        (new ParticipanteService())->anonimizarDatos($pid, $uid);

        $todo = implode("\n", $this->db->query(
            "SELECT CONCAT(COALESCE(valor_anterior, ''), ' ', COALESCE(valor_nuevo, '')) FROM historial_bd"
        )->fetchAll(PDO::FETCH_COLUMN));

        $this->assertGreaterThan(0, strlen($todo), 'control: el historial no está vacío');
        foreach ([
            'Maria Secreta', 'maria.secreta@test.local', 'Maria Nueva Apellido', 'maria.nueva@test.local',
            'Maria Participante', '4777888', 'maria_nick', 'maria.part@test.local', '098777666', 'foto_secreta.png',
            '$2y$', $nuevoHash, 'Clave-Muy-Secreta1!',
        ] as $dato) {
            $this->assertStringNotContainsString($dato, $todo, "el historial guardó «{$dato}»");
        }

        // Pero sí sabe que pasó: quedan los hechos, sin los datos.
        $cambiosDeUsuario = $this->historia('usuarios', (string) $uid);
        $this->assertTrue(count($cambiosDeUsuario) >= 3, 'el alta, el cambio de nombre/email y el de contraseña quedaron registrados');
        $marcas = array_map(fn($f) => $f['valor_nuevo']['datos_reservados_modificados'] ?? null, array_slice($cambiosDeUsuario, 1));
        $this->assertTrue(in_array(true, $marcas, true), 'queda constancia de que cambió algo reservado');
    }

    public function test_un_cambio_que_no_toca_lo_reservado_lo_dice(): void
    {
        $uid = Fixtures::usuario('Persona Comun', 'comun@test.local', 'Clave-Segura1!', 3);
        $this->db->exec("UPDATE usuarios SET estado = 'inactivo' WHERE id = {$uid}");

        $h = $this->historia('usuarios', (string) $uid);
        $ultimo = end($h);
        $this->assertSame('inactivo', $ultimo['valor_nuevo']['estado']);
        $this->assertSame(false, $ultimo['valor_nuevo']['datos_reservados_modificados'],
            'solo cambió el estado, no nada reservado');
    }

    public function test_los_contactos_guardados_como_ajustes_se_ocultan(): void
    {
        $tid = Fixtures::torneo('liga');
        $this->db->exec("INSERT INTO configuraciones_torneo (torneo_id, clave, valor)
                         VALUES ({$tid}, 'contacto', 'organizador@example.com'), ({$tid}, 'sede', 'Gimnasio Central')");
        $this->db->exec("UPDATE configuracion_sistema SET valor = 'soporte@example.com' WHERE clave = 'sitio_contacto'");

        $valores = fn(array $filas) => array_column(array_column($filas, 'valor_nuevo'), 'valor', 'clave');
        $torneo  = $valores($this->historia('configuraciones_torneo'));
        $this->assertSame('(oculto)', $torneo['contacto']);
        $this->assertSame('Gimnasio Central', $torneo['sede'], 'lo que no es un contacto se ve');

        $sistema = $this->historia('configuracion_sistema', 'sitio_contacto');
        $this->assertSame('(oculto)', $sistema[0]['valor_nuevo']['valor']);
        $this->assertSame('(oculto)', $sistema[0]['valor_anterior']['valor']);

        $todo = (string) $this->db->query("SELECT GROUP_CONCAT(valor_nuevo) FROM historial_bd")->fetchColumn();
        $this->assertStringNotContainsString('example.com', $todo);
    }

    // ─── Lo que el motor no permite ─────────────────────────────────────────

    public function test_el_borrado_en_cascada_no_activa_triggers_y_queda_documentado(): void
    {
        // Es una limitación de MySQL, no del historial: las acciones en cascada de una clave
        // foránea no activan triggers. Se fija acá para que el día que cambie se note, y para
        // que la documentación no prometa lo que el motor no hace.
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $this->db->exec('DELETE FROM historial_bd');

        $this->db->exec("DELETE FROM torneos WHERE id = {$tid}");

        $this->assertCount(1, $this->historia('torneos'), 'el torneo borrado sí queda');
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM rondas WHERE torneo_id = {$tid}")->fetchColumn(),
            'control: las rondas desaparecieron por la cascada');
        $this->assertCount(0, $this->historia('rondas'), 'pero la cascada no dejó rastro de las rondas');
        $this->assertCount(0, $this->historia('enfrentamientos'));
    }

    // ─── Cómo sabe la base para quién trabaja la aplicación ─────────────────

    public function test_fijar_el_usuario_de_la_aplicacion_se_refleja_en_la_conexion(): void
    {
        Database::fijarUsuarioApp(42);
        $this->assertSame('42', (string) $this->db->query('SELECT @id_usuario_app')->fetchColumn());

        Database::fijarUsuarioApp(null);
        $this->assertSame(null, $this->db->query('SELECT @id_usuario_app')->fetchColumn());
    }

    public function test_iniciar_sesion_atribuye_los_cambios_siguientes_a_ese_usuario(): void
    {
        Auth::login(['id' => 3, 'nombre' => 'Laura', 'email' => 'org2@flexarena.com', 'rol_nombre' => 'organizador', 'rol_id' => 2]);
        $this->assertSame('3', (string) $this->db->query('SELECT @id_usuario_app')->fetchColumn());

        $id = (new EquipoModel())->insert(['nombre' => 'Hecho por Laura', 'estado' => 'activo']);
        $this->assertSame(3, (int) $this->historia('equipos', (string) $id)[0]['usuario_app_id']);
    }

    public function test_cerrar_sesion_limpia_la_atribucion(): void
    {
        // No se llama Auth::logout() porque destruye la sesión del propio test; se comprueba
        // que lo hace y que lo que llama funciona (test de arriba).
        $metodo = new ReflectionMethod(Auth::class, 'logout');
        $fuente = implode('', array_slice(
            (array) file((string) $metodo->getFileName()),
            $metodo->getStartLine() - 1, $metodo->getEndLine() - $metodo->getStartLine() + 1));

        $this->assertStringContainsString('Database::fijarUsuarioApp(null)', $fuente);
    }

    public function test_una_conexion_nueva_toma_el_usuario_de_la_sesion(): void
    {
        $_SESSION['user_id'] = 9;
        Database::reset();
        $nueva = Database::getInstance();

        $this->assertSame('9', (string) $nueva->query('SELECT @id_usuario_app')->fetchColumn());

        $_SESSION = [];
        Database::reset();
        $otra = Database::getInstance();
        $this->assertSame(null, $otra->query('SELECT @id_usuario_app')->fetchColumn(), 'sin sesión no se atribuye a nadie');
    }
}

TestCase::correrSiEsScript(HistorialBdTest::class);
