<?php
declare(strict_types=1);

/**
 * Test del mínimo privilegio del usuario de la aplicación (database/aplicar_privilegios.php).
 *
 * No se conforma con leer SHOW GRANTS: crea un usuario temporal, le aplica los
 * privilegios, SE CONECTA COMO ESE USUARIO e intenta cosas. Lo que cuenta es qué
 * puede hacer de verdad, no qué dice la tabla de permisos.
 *
 * Cada restricción se prueba con su par permitido (se puede escribir en las
 * tablas de negocio, se puede insertar en la auditoría…), para que no pase por
 * haberle quitado todo.
 *
 * Los casos «denegado» usan tablas de la base de pruebas, que es descartable. Aun
 * así, los que podrían destruir algo si el privilegio estuviera mal puesto (DROP,
 * TRUNCATE) apuntan a tablas que no importan.
 *
 * Ejecutar (con un usuario que pueda crear usuarios, en los tests root):
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/privilegios_test.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/database/aplicar_privilegios.php';

class PrivilegiosTest extends TestCase
{
    private const USUARIO = 'flexarena_dcl_t';
    private const VISTA   = 'zz_vista_dcl';

    private static ?PDO   $app = null;
    private static string $clave = '';

    /** Usuario temporal + privilegios aplicados + conexión como ese usuario, una sola vez. */
    protected function setUp(): void
    {
        if (self::$app !== null) return;

        $base = $_ENV['DB_NAME'];
        self::$clave = 'Dcl-' . bin2hex(random_bytes(8)) . '!';

        register_shutdown_function(static function () use ($base): void {
            try {
                $db = Database::getInstance();
                $db->exec('DROP VIEW IF EXISTS `' . $base . '`.`' . self::VISTA . '`');
                $db->exec("DROP USER IF EXISTS '" . self::USUARIO . "'@'%'");
            } catch (Throwable) {
                // Es un usuario temporal: si no se pudo borrar ya, no hay nada que rescatar.
            }
        });

        $this->db->exec("DROP USER IF EXISTS '" . self::USUARIO . "'@'%'");
        $this->db->exec("CREATE USER '" . self::USUARIO . "'@'%' IDENTIFIED BY " . $this->db->quote(self::$clave));
        // Como lo deja la imagen oficial de MySQL al crear MYSQL_USER: todo.
        $this->db->exec('GRANT ALL PRIVILEGES ON `' . $base . '`.* TO \'' . self::USUARIO . "'@'%'");
        $this->db->exec('DROP VIEW IF EXISTS `' . self::VISTA . '`');
        $this->db->exec('CREATE VIEW `' . self::VISTA . '` AS SELECT id, nombre FROM equipos');

        PrivilegiosDb::aplicar($this->db, $base, self::USUARIO);

        self::$app = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$base};charset=utf8mb4",
            self::USUARIO, self::$clave,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Ejecuta $sql como el usuario de la aplicación, consumiendo lo que devuelva:
     * con exec(), un SELECT deja su resultado sin leer y la sentencia siguiente
     * falla con «unbuffered queries are active».
     */
    private function comoLaApp(string $sql): void
    {
        $st = self::$app->query($sql);
        if ($st->columnCount() > 0) $st->fetchAll();
    }

    /**
     * El motor tiene que negarle $sql al usuario de la aplicación.
     *
     * @param string[] $mensajes con que lo rechace; alguno tiene que aparecer en el error
     */
    private function assertDenegado(string $sql, array $mensajes = ['denied']): void
    {
        $e = $this->assertThrows(fn() => $this->comoLaApp($sql), '', "se esperaba que se denegara: {$sql}");
        $this->assertTrue($e instanceof PDOException, get_class($e) . ': ' . $e->getMessage());

        $dice = array_filter($mensajes, fn(string $m) => str_contains($e->getMessage(), $m));
        $this->assertTrue($dice !== [], "{$sql} fue rechazada, pero no con «" . implode('» ni «', $mensajes)
            . '»: ' . $e->getMessage());
    }

    /**
     * MySQL aplica los cambios de privilegios de TABLA en la sentencia siguiente,
     * pero los de BASE DE DATOS recién con el próximo USE: una conexión abierta
     * conserva los que tenía. La aplicación abre una conexión por pedido, así que
     * no le pasa; un test que reutiliza la suya tiene que refrescarla.
     */
    private function refrescarPrivilegiosDeBase(): void
    {
        self::$app->exec('USE `' . $_ENV['DB_NAME'] . '`');
    }

    /** Y esto tiene que poder hacerlo. */
    private function assertPermitido(string $sql): void
    {
        $this->assertDoesNotThrow(fn() => $this->comoLaApp($sql), "tendría que poder hacer: {$sql}");
    }

    /** @return string[] */
    private function tablas(): array
    {
        return $this->db->query(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─── La matriz ──────────────────────────────────────────────────────────

    public function test_la_matriz_solo_concede_lectura_y_escritura_de_filas(): void
    {
        $permitidos = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
        foreach ($this->tablas() as $tabla) {
            $this->assertSame([], array_values(array_diff(PrivilegiosDb::privilegiosDe($tabla), $permitidos)),
                "«{$tabla}» recibiría algo más que leer y escribir filas");
            $this->assertTrue(in_array('SELECT', PrivilegiosDb::privilegiosDe($tabla), true),
                "la aplicación tiene que poder leer «{$tabla}»");
        }
    }

    public function test_cada_excepcion_de_la_matriz_apunta_a_una_tabla_que_existe(): void
    {
        // Una entrada que quedó apuntando a una tabla renombrada es una regla que
        // ya no protege nada y nadie se entera.
        $tablas = $this->tablas();
        foreach (array_keys(PrivilegiosDb::ESPECIALES) as $tabla) {
            $this->assertTrue(in_array($tabla, $tablas, true), "la matriz nombra «{$tabla}», que no existe en el esquema");
        }
    }

    public function test_las_vistas_solo_se_leen(): void
    {
        $this->assertSame(['SELECT'], PrivilegiosDb::privilegiosDe('cualquiera', true));
    }

    // ─── Lo que SHOW GRANTS dice ────────────────────────────────────────────

    public function test_no_queda_ningun_privilegio_global_ni_de_base_completa(): void
    {
        $base = $_ENV['DB_NAME'];
        $lineas = $this->db->query("SHOW GRANTS FOR '" . self::USUARIO . "'@'%'")->fetchAll(PDO::FETCH_COLUMN);

        $this->assertTrue(in_array("GRANT USAGE ON *.* TO `" . self::USUARIO . "`@`%`", $lineas, true),
            'a nivel global solo USAGE (sin privilegios): ' . implode(' | ', $lineas));

        foreach ($lineas as $l) {
            $this->assertStringNotContainsString('ALL PRIVILEGES', $l, 'quedó un ALL PRIVILEGES');
            $this->assertStringNotContainsString("ON `{$base}`.*", $l, 'quedó un privilegio sobre toda la base');
            $this->assertStringNotContainsString('WITH GRANT OPTION', $l);
        }
    }

    // ─── Lo permitido ───────────────────────────────────────────────────────

    public function test_puede_leer_todas_las_tablas(): void
    {
        foreach ($this->tablas() as $tabla) {
            $this->assertPermitido("SELECT 1 FROM `{$tabla}` LIMIT 1");
        }
    }

    public function test_puede_escribir_en_las_tablas_de_negocio(): void
    {
        $nombre = 'Equipo DCL ' . bin2hex(random_bytes(3));
        $this->assertPermitido("INSERT INTO equipos (nombre) VALUES ('{$nombre}')");
        $this->assertPermitido("UPDATE equipos SET categoria = 'A' WHERE nombre = '{$nombre}'");
        $this->assertPermitido("DELETE FROM equipos WHERE nombre = '{$nombre}'");
    }

    public function test_puede_registrar_en_la_auditoria_y_leerla(): void
    {
        $this->assertPermitido("INSERT INTO auditoria (accion, descripcion) VALUES ('prueba_dcl', 'insertar tiene que poder')");
        $this->assertPermitido('SELECT COUNT(*) FROM auditoria');
        $this->db->exec("DELETE FROM auditoria WHERE accion = 'prueba_dcl'");   // limpieza, como root
    }

    public function test_puede_encender_y_apagar_modulos_y_editar_la_configuracion(): void
    {
        $this->assertPermitido("UPDATE modulos SET estado = estado WHERE slug = 'liga'");
        $this->assertPermitido("UPDATE configuracion_sistema SET valor = valor WHERE clave = 'sitio_nombre'");
        $this->assertPermitido("INSERT INTO configuracion_sistema (clave, valor) VALUES ('zz_prueba_dcl', 'x')");
        $this->db->exec("DELETE FROM configuracion_sistema WHERE clave = 'zz_prueba_dcl'");   // limpieza, como root
    }

    public function test_puede_registrar_y_purgar_intentos_de_login(): void
    {
        $this->assertPermitido("INSERT INTO intentos_login (ip, email) VALUES ('203.0.113.7', 'x@example.com')");
        $this->assertPermitido("DELETE FROM intentos_login WHERE ip = '203.0.113.7'");
    }

    // ─── Lo denegado ────────────────────────────────────────────────────────

    public function test_no_puede_modificar_ni_borrar_la_auditoria(): void
    {
        $this->db->exec("DELETE FROM auditoria WHERE accion = 'prueba_dcl_inmutable'");   // residuo de una corrida cortada
        $this->db->exec("INSERT INTO auditoria (accion) VALUES ('prueba_dcl_inmutable')");

        $this->assertDenegado("UPDATE auditoria SET accion = 'adulterada' WHERE accion = 'prueba_dcl_inmutable'");
        $this->assertDenegado("DELETE FROM auditoria WHERE accion = 'prueba_dcl_inmutable'");
        $this->assertDenegado('TRUNCATE TABLE auditoria');

        $this->assertSame('1', (string) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'prueba_dcl_inmutable'")->fetchColumn(),
            'el registro de auditoría sigue intacto');
        $this->db->exec("DELETE FROM auditoria WHERE accion = 'prueba_dcl_inmutable'");   // limpieza, como root
    }

    public function test_los_catalogos_son_de_solo_lectura(): void
    {
        foreach (['roles', 'tipos_torneo'] as $tabla) {
            $this->assertDenegado("UPDATE `{$tabla}` SET nombre = nombre WHERE id = 1");
            $this->assertDenegado("DELETE FROM `{$tabla}` WHERE id = 99999");
        }
        $this->assertDenegado("INSERT INTO roles (nombre) VALUES ('superadministrador')");
    }

    public function test_los_modulos_no_se_crean_ni_se_borran(): void
    {
        $this->assertDenegado("INSERT INTO modulos (nombre, slug) VALUES ('Falso', 'falso')");
        $this->assertDenegado("DELETE FROM modulos WHERE slug = 'no-existe'");
    }

    public function test_la_configuracion_se_edita_pero_no_se_borra(): void
    {
        $this->assertDenegado("DELETE FROM configuracion_sistema WHERE clave = 'no-existe'");
    }

    public function test_el_historial_se_escribe_aunque_la_cuenta_no_pueda_escribirlo(): void
    {
        // Los triggers corren con los privilegios de quien los creó, no de quien dispara el
        // cambio. Por eso la aplicación no necesita (ni tiene) permiso sobre historial_bd y,
        // aun así, todo lo que hace queda registrado: ni una inyección SQL puede falsificarlo.
        $nombre = 'Equipo DCL H ' . bin2hex(random_bytes(3));
        $this->assertPermitido("INSERT INTO equipos (nombre) VALUES ('{$nombre}')");

        $st = $this->db->prepare(
            "SELECT usuario_bd FROM historial_bd
              WHERE tabla = 'equipos' AND operacion = 'INSERT' AND JSON_UNQUOTE(JSON_EXTRACT(valor_nuevo, '$.nombre')) = ?");
        $st->execute([$nombre]);
        $filas = $st->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(1, $filas, 'el cambio hecho con la cuenta limitada quedó en el historial');
        $this->assertStringContainsString(self::USUARIO . '@', (string) $filas[0],
            'y dice qué cuenta de MySQL lo hizo');

        $this->assertPermitido("DELETE FROM equipos WHERE nombre = '{$nombre}'");
        $this->db->exec("DELETE FROM historial_bd WHERE tabla = 'equipos'");   // limpieza, como root
    }

    public function test_la_aplicacion_solo_puede_leer_el_historial(): void
    {
        $this->assertPermitido('SELECT COUNT(*) FROM historial_bd');

        $this->assertDenegado("INSERT INTO historial_bd (tabla, operacion, registro_id, usuario_bd) VALUES ('equipos', 'INSERT', '1', 'falsificado')");
        $this->assertDenegado("UPDATE historial_bd SET usuario_bd = 'otro' WHERE id = 1");
        $this->assertDenegado('DELETE FROM historial_bd WHERE id = 1');
        $this->assertDenegado('TRUNCATE TABLE historial_bd');
    }

    public function test_las_vistas_reales_se_leen_pero_no_se_escriben(): void
    {
        $vistas = $this->db->query(
            "SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE() AND table_name LIKE 'v\_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertGreaterThan(0, count($vistas), 'control: hay vistas reales en el esquema');

        foreach ($vistas as $vista) {
            $this->assertPermitido("SELECT 1 FROM `{$vista}` LIMIT 1");
        }
        $this->assertDenegado("UPDATE v_participantes_publicos SET nombre = 'x' WHERE id = 1");
        $this->assertDenegado("DELETE FROM v_participantes_publicos WHERE id = 1");
    }

    public function test_no_puede_cambiar_la_estructura_de_la_base(): void
    {
        $this->assertDenegado('CREATE TABLE zz_no_deberia_crearse (id INT)');
        $this->assertDenegado('ALTER TABLE intentos_login ADD COLUMN zz_extra INT');
        $this->assertDenegado('CREATE INDEX zz_idx ON intentos_login (email)');
        $this->assertDenegado('TRUNCATE TABLE intentos_login');
        $this->assertDenegado('DROP TABLE IF EXISTS zz_no_existe');
        // Con el log binario activo, MySQL contesta «necesitás SUPER» antes que «TRIGGER denegado».
        $this->assertDenegado('CREATE TRIGGER zz_trg BEFORE INSERT ON intentos_login FOR EACH ROW SET NEW.ip = NEW.ip',
            ['denied', 'SUPER privilege']);
        $this->assertDenegado('CREATE VIEW zz_vista_nueva AS SELECT 1');
    }

    public function test_no_puede_administrar_usuarios_ni_leer_las_credenciales_del_servidor(): void
    {
        $this->assertDenegado("CREATE USER 'zz_intruso'@'%' IDENTIFIED BY 'x'");
        $this->assertDenegado('GRANT SELECT ON `' . $_ENV['DB_NAME'] . "`.* TO '" . self::USUARIO . "'@'%'");
        $this->assertDenegado('SELECT user FROM mysql.user');
        $this->assertDenegado('LOCK TABLES equipos WRITE');
    }

    public function test_una_vista_se_lee_pero_no_se_escribe(): void
    {
        $this->assertPermitido('SELECT 1 FROM `' . self::VISTA . '` LIMIT 1');
        $this->assertDenegado('INSERT INTO `' . self::VISTA . "` (id, nombre) VALUES (999999, 'x')");
        $this->assertDenegado('UPDATE `' . self::VISTA . "` SET nombre = 'x' WHERE id = 1");
    }

    // ─── El script ──────────────────────────────────────────────────────────

    public function test_volver_a_correrlo_no_cambia_nada(): void
    {
        $r = PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], self::USUARIO);

        $this->assertSame(0, $r['otorgados'], 'no tenía nada que conceder');
        $this->assertSame(0, $r['revocados'], 'no tenía nada que revocar');
        $this->assertGreaterThan(0, $r['tablas']);
    }

    public function test_revoca_lo_que_sobra_y_repone_lo_que_falta(): void
    {
        $base = '`' . $_ENV['DB_NAME'] . '`';
        $u    = "'" . self::USUARIO . "'@'%'";

        // Se desarma el estado a propósito: un permiso de más (DELETE sobre la
        // auditoría, a nivel de tabla) y uno de menos (INSERT sobre equipos).
        $this->db->exec("GRANT DELETE ON {$base}.auditoria TO {$u}");
        $this->db->exec("REVOKE INSERT ON {$base}.equipos FROM {$u}");
        $this->db->exec("GRANT DROP ON {$base}.intentos_login TO {$u}");

        $r = PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], self::USUARIO);

        $this->assertSame(2, $r['revocados'], 'tenía DELETE sobre auditoria y DROP sobre intentos_login de más');
        $this->assertSame(1, $r['otorgados'], 'le faltaba INSERT sobre equipos');

        $this->assertDenegado("DELETE FROM auditoria WHERE accion = 'no-existe'");
        $this->assertDenegado('TRUNCATE TABLE intentos_login');
        $this->assertPermitido("INSERT INTO equipos (nombre) VALUES ('Repuesto DCL')");
        $this->db->exec("DELETE FROM equipos WHERE nombre = 'Repuesto DCL'");
    }

    public function test_quita_el_all_privileges_que_deja_la_imagen_de_mysql(): void
    {
        $base = '`' . $_ENV['DB_NAME'] . '`';
        $this->db->exec("GRANT ALL PRIVILEGES ON {$base}.* TO '" . self::USUARIO . "'@'%'");
        $this->refrescarPrivilegiosDeBase();
        $this->assertPermitido('CREATE TABLE zz_creada_por_error (id INT)');   // con ALL, sí podía
        $this->db->exec('DROP TABLE zz_creada_por_error');

        PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], self::USUARIO);
        $this->refrescarPrivilegiosDeBase();

        $this->assertDenegado('CREATE TABLE zz_creada_por_error (id INT)');
        $this->assertDenegado('DROP TABLE IF EXISTS zz_no_existe');
        $this->assertDenegado("DELETE FROM auditoria WHERE accion = 'no-existe'");
    }

    public function test_no_acepta_root_ni_nombres_raros_ni_usuarios_inexistentes(): void
    {
        $this->assertThrows(fn() => PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], 'root'), 'root');
        $this->assertThrows(fn() => PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], "x'; DROP USER 'y"), 'no permitidos');
        $this->assertThrows(fn() => PrivilegiosDb::aplicar($this->db, 'base`rara', self::USUARIO), 'no permitidos');
        $this->assertThrows(fn() => PrivilegiosDb::aplicar($this->db, $_ENV['DB_NAME'], 'usuario_que_no_existe_zz'), 'no existe');
    }
}

TestCase::correrSiEsScript(PrivilegiosTest::class);
