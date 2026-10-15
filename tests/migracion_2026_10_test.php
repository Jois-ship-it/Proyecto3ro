<?php
declare(strict_types=1);

/**
 * Test de las migraciones de octubre aplicadas sobre una base CON DATOS.
 *
 * `migraciones_consolidadas_test.php` prueba la estructura sobre bases vacías.
 * Acá se prueba lo que solo pasa con filas: una instalación de septiembre que ya
 * tiene usuarios, participantes y equipos y se actualiza.
 *
 *   - Las cuentas con contraseña publicada quedan sin clave; la que alguien
 *     cambió desde la aplicación, no se toca.
 *   - Los documentos vacíos pasan a NULL (si no, el índice único no se puede crear).
 *   - Si hay datos que violan una regla, la migración FALLA diciendo cuál, no los
 *     borra ni los arregla por su cuenta, y vuelve a correr una vez corregidos.
 *   - Es idempotente y no pisa lo que el administrador ya cambió.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/migracion_2026_10_test.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/BasesDescartables.php';

class Migracion202610Test extends TestCase
{
    /** La foto del esquema de septiembre, antes de que existieran estas migraciones. */
    private const FOTO = __DIR__ . '/fixtures/schema_2026_09_17.sql';

    /** @return string[] todas las migraciones de octubre, en el orden en que se aplican */
    private function migracionesDeOctubre(): array
    {
        $archivos = glob(dirname(__DIR__) . '/database/migrations/2026_10_*.sql') ?: [];
        sort($archivos);
        return $archivos;
    }

    private const SIN_CLAVE = '!sin-clave-definida';

    /** Hashes que el seed de septiembre traía publicados (con su contraseña en un comentario). */
    private const HASH_ADMIN_DEL_SEED = '$2y$12$cVKpHtHbJHPytOM0SZtX2ua24uvjAMSmXehWHzptO6j4Qe8UGYEXi';
    private const HASH_DEMO_DEL_SEED  = '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG';

    /** Una base como la de septiembre, con datos de los tres tipos que importan. */
    private function baseDeSeptiembre(string $sufijo): PDO
    {
        $pdo = BasesDescartables::crear($sufijo, self::FOTO);

        $pdo->exec("INSERT INTO roles (id, nombre) VALUES (1,'administrador'),(2,'organizador'),(3,'participante')");

        $propio = password_hash('Mi-Clave-Propia1!', PASSWORD_BCRYPT, ['cost' => 4]);
        $usuario = $pdo->prepare(
            "INSERT INTO usuarios (id, rol_id, nombre, email, password_hash) VALUES (:id, :rol, :n, :e, :h)");
        foreach ([
            [1, 1, 'Admin del seed',    'admin@x.test', self::HASH_ADMIN_DEL_SEED],
            [2, 2, 'Org del seed',      'org@x.test',   self::HASH_DEMO_DEL_SEED],
            [3, 3, 'Part del seed',     'part@x.test',  self::HASH_DEMO_DEL_SEED],
            [4, 2, 'Org que cambió su clave', 'otra@x.test', $propio],
        ] as [$id, $rol, $nombre, $email, $hash]) {
            $usuario->execute([':id' => $id, ':rol' => $rol, ':n' => $nombre, ':e' => $email, ':h' => $hash]);
        }

        $part = $pdo->prepare("INSERT INTO participantes (id, nombre, documento) VALUES (:id, :n, :d)");
        foreach ([[1, 'Vacío', ''], [2, 'Espacios', '   '], [3, 'Con documento', '4001'], [4, 'Sin dato', null]] as [$id, $n, $d]) {
            $part->execute([':id' => $id, ':n' => $n, ':d' => $d]);
        }

        $pdo->exec("INSERT INTO equipos (id, nombre) VALUES (1,'Halcones'),(2,'Pumas')");

        // Los tres formatos y sus módulos, como los trae el seed. En septiembre el formato y
        // el módulo se relacionaban solo por tener el mismo slug.
        $pdo->exec("INSERT INTO modulos (id, nombre, slug) VALUES
                    (1,'Liga','liga'), (2,'Eliminación Directa','eliminacion_directa'), (3,'Sistema Suizo','suizo')");
        $pdo->exec("INSERT INTO tipos_torneo (id, nombre, slug) VALUES
                    (1,'Liga','liga'), (2,'Eliminación Directa','eliminacion_directa'), (3,'Sistema Suizo','suizo')");
        return $pdo;
    }

    /**
     * La base de septiembre ya migrada, creada UNA vez para todo el archivo.
     * Crear y borrar una base de ~20 tablas cuesta varios segundos, así que las
     * comprobaciones que solo leen comparten esta; la que necesita datos
     * incompatibles con las reglas crea la suya.
     */
    private static ?PDO $migrada = null;

    /** Filas que tenía historial_bd justo al terminar de migrar (antes de que otros casos escriban). */
    private static int $historiaAlMigrar = -1;

    private function migrada(): PDO
    {
        if (self::$migrada === null) {
            $pdo = $this->baseDeSeptiembre('mig_datos');
            $this->migrar($pdo);
            self::$historiaAlMigrar = (int) $pdo->query('SELECT COUNT(*) FROM historial_bd')->fetchColumn();
            self::$migrada = $pdo;
        }
        return self::$migrada;
    }

    private function migrar(PDO $pdo): void
    {
        foreach ($this->migracionesDeOctubre() as $archivo) {
            BasesDescartables::ejecutarArchivo($pdo, $archivo);
        }
    }

    private function valor(PDO $pdo, string $sql): mixed
    {
        return $pdo->query($sql)->fetchColumn();
    }

    // ─── Las cuentas ────────────────────────────────────────────────────────

    public function test_las_cuentas_con_clave_publicada_quedan_sin_clave(): void
    {
        $pdo = $this->migrada();

        foreach ([1, 2, 3] as $id) {
            $this->assertSame(self::SIN_CLAVE,
                $this->valor($pdo, "SELECT password_hash FROM usuarios WHERE id = {$id}"),
                "la cuenta {$id} tenía una contraseña publicada en el repositorio");
        }
    }

    public function test_la_cuenta_que_cambio_su_clave_no_se_toca(): void
    {
        $pdo  = $this->migrada();
        $hash = (string) $this->valor($pdo, 'SELECT password_hash FROM usuarios WHERE id = 4');

        $this->assertTrue(password_verify('Mi-Clave-Propia1!', $hash),
            'la clave propia sigue funcionando después de migrar');
        $this->assertNotSame(self::SIN_CLAVE, $hash);
    }

    // ─── Los datos ──────────────────────────────────────────────────────────

    public function test_los_documentos_vacios_pasan_a_null_y_los_demas_quedan_igual(): void
    {
        $pdo = $this->migrada();

        $docs = $pdo->query('SELECT id, documento FROM participantes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([1 => null, 2 => null, 3 => '4001', 4 => null], $docs);
    }

    public function test_la_configuracion_del_sistema_queda_cargada_y_no_se_pisa(): void
    {
        $pdo = $this->migrada();

        $this->assertSame(7, (int) $this->valor($pdo, 'SELECT COUNT(*) FROM configuracion_sistema'));
        $this->assertSame('5', $this->valor($pdo, "SELECT valor FROM configuracion_sistema WHERE clave = 'login_max_intentos'"));

        // El administrador cambia un ajuste; volver a migrar no puede deshacerlo.
        $pdo->exec("UPDATE configuracion_sistema SET valor = '3' WHERE clave = 'login_max_intentos'");
        $this->migrar($pdo);
        $this->assertSame('3', $this->valor($pdo, "SELECT valor FROM configuracion_sistema WHERE clave = 'login_max_intentos'"),
            'INSERT IGNORE: un valor ya cargado no se pisa');

        // Se deja como estaba: las demás comprobaciones comparten esta base.
        $pdo->exec("UPDATE configuracion_sistema SET valor = '5' WHERE clave = 'login_max_intentos'");
    }

    // ─── La estructura ──────────────────────────────────────────────────────

    public function test_quedan_las_restricciones_nuevas_y_se_van_las_que_reemplazan(): void
    {
        $pdo = $this->migrada();

        $checks = $pdo->query(
            "SELECT constraint_name FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'"
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($checks);

        $this->assertSame([
            'chk_inscripcion_un_competidor', 'chk_resultados_puntos', 'chk_solicitudes_puntos',
            'chk_tabla_un_competidor', 'chk_torneos_fechas',
        ], $checks, 'chk_inscripcion y chk_tabla (las viejas, «participante O equipo») ya no tienen que estar');

        $indices = $pdo->query(
            "SELECT index_name FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'equipos'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertTrue(in_array('uq_equipos_nombre', $indices, true), 'falta el índice único de nombre');
        $this->assertFalse(in_array('idx_nombre', $indices, true), 'el índice común sobra una vez que hay uno único');
    }

    // ─── Formato → módulo, vistas e historial ───────────────────────────────

    public function test_cada_formato_queda_asociado_a_su_modulo(): void
    {
        $pdo = $this->migrada();

        $this->assertSame(
            ['liga' => 'liga', 'eliminacion_directa' => 'eliminacion_directa', 'suizo' => 'suizo'],
            $pdo->query('SELECT slug, modulo_slug FROM tipos_torneo ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
            'cada formato queda en el módulo que ya llevaba su mismo slug');

        $this->assertSame(1, (int) $this->valor($pdo,
            "SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND constraint_name = 'fk_tipos_torneo_modulo'
                AND constraint_type = 'FOREIGN KEY'"));
        $this->assertSame('NO', $this->valor($pdo,
            "SELECT is_nullable FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'tipos_torneo' AND column_name = 'modulo_slug'"),
            'y la columna es obligatoria');
    }

    public function test_quedan_las_cinco_vistas_y_los_triggers_del_historial(): void
    {
        $pdo = $this->migrada();

        $this->assertSame(5, (int) $this->valor($pdo,
            "SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()"));
        $this->assertSame(51, (int) $this->valor($pdo,
            "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()"));
    }

    public function test_los_datos_de_septiembre_siguen_ahi_y_lo_nuevo_se_registra_desde_ahora(): void
    {
        $pdo = $this->migrada();

        // Lo que ya existía no se pierde ni se reescribe…
        $this->assertSame(2, (int) $this->valor($pdo, 'SELECT COUNT(*) FROM equipos'));
        // …y el historial no es retroactivo: arrancó vacío (otros casos de este archivo
        // escriben después, y ya quedan registrados) y registra desde que se instala.
        $this->assertSame(0, self::$historiaAlMigrar, 'migrar no inventa historia de lo que ya existía');
        $this->assertSame(0, (int) $this->valor($pdo, "SELECT COUNT(*) FROM historial_bd WHERE tabla = 'equipos'"));

        $pdo->exec("INSERT INTO equipos (id, nombre) VALUES (99, 'Recien Llegado')");
        $pdo->exec("UPDATE equipos SET nombre = 'Recien Llegado II' WHERE id = 99");
        $filas = $pdo->query("SELECT operacion FROM historial_bd WHERE tabla = 'equipos' AND registro_id = '99' ORDER BY id")
                     ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['INSERT', 'UPDATE'], $filas);

        $pdo->exec('DELETE FROM equipos WHERE id = 99');
        $pdo->exec('DELETE FROM historial_bd');
    }

    public function test_es_idempotente(): void
    {
        $pdo = $this->migrada();
        $foto = [
            $pdo->query('SELECT id, password_hash FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
            $pdo->query('SELECT id, documento FROM participantes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
            $pdo->query('SELECT clave, valor FROM configuracion_sistema ORDER BY clave')->fetchAll(PDO::FETCH_KEY_PAIR),
        ];

        $this->assertDoesNotThrow(function () use ($pdo) {
            $this->migrar($pdo);
            $this->migrar($pdo);
        }, 'correr las migraciones otra vez no puede fallar');

        $despues = [
            $pdo->query('SELECT id, password_hash FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
            $pdo->query('SELECT id, documento FROM participantes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
            $pdo->query('SELECT clave, valor FROM configuracion_sistema ORDER BY clave')->fetchAll(PDO::FETCH_KEY_PAIR),
        ];
        $this->assertSame($foto, $despues);
    }

    // ─── Cuando los datos no cumplen la regla ───────────────────────────────

    public function test_con_equipos_repetidos_falla_diciendo_cual_y_se_arregla_a_mano(): void
    {
        $pdo = $this->baseDeSeptiembre('mig_dup');
        // En septiembre nada lo impedía: dos equipos con el mismo nombre.
        $pdo->exec("INSERT INTO equipos (id, nombre) VALUES (3, 'Halcones')");

        $e = $this->assertThrows(fn() => $this->migrar($pdo), 'Duplicate entry');
        $this->assertStringContainsString('uq_equipos_nombre', $e->getMessage(),
            'el error tiene que nombrar el índice que no pudo crearse');

        // Nada se borró por cuenta de la migración.
        $this->assertSame(3, (int) $this->valor($pdo, 'SELECT COUNT(*) FROM equipos'));

        // Se corrige el dato a mano y se vuelve a correr: ahora termina.
        $pdo->exec("UPDATE equipos SET nombre = 'Halcones II' WHERE id = 3");

        // Otro dato que septiembre dejaba pasar: un formato cargado sin un módulo que lo implemente.
        $pdo->exec("INSERT INTO tipos_torneo (id, nombre, slug) VALUES (4, 'Ajedrez raro', 'ajedrez_raro')");
        $e2 = $this->assertThrows(fn() => $this->migrar($pdo), 'foreign key');
        $this->assertStringContainsString('fk_tipos_torneo_modulo', $e2->getMessage(),
            'el error nombra la clave foránea: hace falta crear el módulo (o corregir el slug)');

        $pdo->exec("INSERT INTO modulos (id, nombre, slug) VALUES (10, 'Ajedrez raro', 'ajedrez_raro')");
        $this->assertDoesNotThrow(fn() => $this->migrar($pdo));
        $this->assertSame(1, (int) $this->valor($pdo,
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'equipos' AND index_name = 'uq_equipos_nombre'"));
    }
}

TestCase::correrSiEsScript(Migracion202610Test::class);
