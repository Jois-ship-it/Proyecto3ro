<?php
declare(strict_types=1);

/**
 * Test de que `schema.sql` ya contiene todas las migraciones.
 *
 * La promesa que se documenta en `database/migrations/README.md` es concreta:
 * para una instalación nueva alcanza con schema.sql + seed.sql, y las diez
 * migraciones no agregan nada. Docker depende de eso —monta solo esos dos
 * archivos en docker-entrypoint-initdb.d— así que si dejara de ser cierto, una
 * base recién levantada quedaría incompleta sin que nadie se entere.
 *
 * Se comprueba de la forma más directa: se fotografía la estructura de la base
 * (que viene de schema.sql), se aplican las diez migraciones en orden, y se
 * vuelve a fotografiar. Si schema.sql está completo, las dos fotos son iguales.
 *
 * La única diferencia esperada está declarada abajo y es al revés de lo que uno
 * buscaría: la migración de la FK de permisos agrega una restricción que
 * schema.sql ya crea, y queda duplicada.
 *
 * El test deja la base como la encontró.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/migraciones_consolidadas_test.php
 */

require __DIR__ . '/bootstrap.php';

final class MigracionesConsolidadasTest extends TestCase
{
    /**
     * Lo que sí cambia al aplicar las migraciones sobre un esquema actual, y por qué.
     *
     * @var array<string, string>
     */
    private const DIFERENCIAS_ESPERADAS = [
        'indice permisos.fk_permisos_modulo' =>
            '2026_06_fk_permisos_modulos.sql agrega una FK que schema.sql ya crea '
            . '(permisos_ibfk_2): queda duplicada. Sobra, no falta.',
        'indice permisos.modulo_slug' =>
            'La contracara de lo anterior: al crear la FK con nombre, MariaDB '
            . 'renombra el índice automático «modulo_slug» a «fk_permisos_modulo». '
            . 'La columna sigue indexada igual.',
    ];

    private static ?array $antes   = null;
    private static ?array $despues = null;

    /** @return string[] rutas de las migraciones, en orden de aplicación */
    private function migraciones(): array
    {
        $archivos = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
        sort($archivos);   // el prefijo aaaa_mm da el orden cronológico
        return $archivos;
    }

    /**
     * Foto de la estructura: columnas con su tipo exacto, y restricciones.
     *
     * @return array<string, string>
     */
    private function estructura(): array
    {
        $foto = [];

        $columnas = $this->db->query(
            "SELECT table_name, column_name, column_type, is_nullable, column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
              ORDER BY table_name, column_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columnas as $c) {
            $clave = "columna {$c['table_name']}.{$c['column_name']}";
            $foto[$clave] = $c['column_type'] . ' | null=' . $c['is_nullable']
                          . ' | default=' . var_export($c['column_default'], true);
        }

        $indices = $this->db->query(
            "SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, non_unique
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
              GROUP BY table_name, index_name, non_unique
              ORDER BY table_name, index_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indices as $i) {
            $foto["indice {$i['table_name']}.{$i['index_name']}"] =
                $i['cols'] . ' | unico=' . ($i['non_unique'] ? 'no' : 'si');
        }

        return $foto;
    }

    protected function setUp(): void
    {
        if (self::$antes !== null) return;

        // Dejar la base como estaba: sacar la FK duplicada que agrega la
        // migración. Va acá y no en tearDown() porque el trabajo se hace una
        // sola vez para todo el archivo, no por caso.
        register_shutdown_function(static function (): void {
            try {
                Database::getInstance()->exec('ALTER TABLE permisos DROP FOREIGN KEY fk_permisos_modulo');
            } catch (PDOException) {
                // No llegó a crearse: nada que deshacer.
            }
        });

        self::$antes = $this->estructura();

        // Conexión aparte: el singleton de la aplicación usa
        // ATTR_EMULATE_PREPARES => false, y con sentencias preparadas nativas
        // MySQL no admite varias sentencias en una consulta. Una migración son
        // muchas. Esta conexión se comporta como el cliente `mysql`, que es como
        // se aplican de verdad.
        $mig = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        foreach ($this->migraciones() as $archivo) {
            $sql = (string) file_get_contents($archivo);
            try {
                // Algunas migraciones devuelven filas (sus SELECT de
                // verificación): hay que consumirlas todas o la conexión queda
                // en un estado que rechaza la consulta siguiente.
                $stmt = $mig->query($sql);
                if ($stmt !== false) { do { $stmt->fetchAll(); } while ($stmt->nextRowset()); }
            } catch (PDOException $e) {
                $this->fallar('La migración ' . basename($archivo) . ' falló: ' . $e->getMessage());
            }
        }

        self::$despues = $this->estructura();
    }

    // ─── Las migraciones corren ─────────────────────────────────────────────

    public function test_hay_migraciones_para_comprobar(): void
    {
        // Control del escenario: sin archivos, todo lo de abajo pasaría de vacío.
        $this->assertGreaterThan(0, count($this->migraciones()),
            'no se encontró ninguna migración en database/migrations/');
    }

    public function test_todas_se_aplican_sobre_un_esquema_actual(): void
    {
        // Que corran sin error sobre schema.sql recién aplicado es la mitad del
        // asunto: son idempotentes y no asumen una base vieja. Si alguna falla,
        // setUp() ya cortó con el nombre del archivo.
        $this->assertNotNull(self::$despues);
    }

    // ─── Y no cambian nada ──────────────────────────────────────────────────

    /** ¿Esta diferencia está declarada y explicada en DIFERENCIAS_ESPERADAS? */
    private function esConocida(string $clave): bool
    {
        foreach (array_keys(self::DIFERENCIAS_ESPERADAS) as $conocida) {
            if (str_contains($clave, $conocida)) return true;
        }
        return false;
    }

    public function test_ninguna_migracion_agrega_algo_que_falte_en_el_esquema(): void
    {
        $agregado = array_diff_key((array) self::$despues, (array) self::$antes);

        $inesperado = array_values(array_filter(
            array_keys($agregado),
            fn(string $clave) => !$this->esConocida($clave)
        ));

        $this->assertCount(0, $inesperado,
            "las migraciones agregan estructura que schema.sql no tiene:\n        - "
            . implode("\n        - ", $inesperado)
            . "\n        Hay que llevarlo a schema.sql.");
    }

    public function test_ninguna_migracion_quita_ni_modifica_lo_que_hay(): void
    {
        $cambios = [];
        foreach ((array) self::$antes as $clave => $valorAntes) {
            if ($this->esConocida($clave)) continue;
            if (!array_key_exists($clave, (array) self::$despues)) {
                $cambios[] = "{$clave}: desapareció";
                continue;
            }
            $valorDespues = self::$despues[$clave];
            if ($valorAntes !== $valorDespues) {
                $cambios[] = "{$clave}:\n            antes:   {$valorAntes}\n            después: {$valorDespues}";
            }
        }

        // Este lado es el peligroso: una migración vieja que pisa algo nuevo.
        // Ya pasó con `bloqueada`, que 2026_06_registro_participantes.sql saca
        // del ENUM y la de septiembre devuelve. Aplicadas en orden, se compensan;
        // si el resultado final difiere, este test lo muestra.
        $this->assertCount(0, $cambios,
            "las migraciones modifican el esquema que deja schema.sql:\n        - "
            . implode("\n        - ", $cambios));
    }

    public function test_la_diferencia_conocida_sigue_siendo_solo_esa(): void
    {
        // Documentar una excepción sin comprobarla es como no tenerla: si la
        // migración de la FK se arregla o se elimina, este test avisa para que se
        // saque también de DIFERENCIAS_ESPERADAS y del README.
        $agregado = array_keys(array_diff_key((array) self::$despues, (array) self::$antes));

        $duplicada = array_filter($agregado, fn(string $c) => str_contains($c, 'fk_permisos_modulo'));

        $this->assertGreaterThan(0, count($duplicada),
            'ya no aparece la FK duplicada: actualizá DIFERENCIAS_ESPERADAS y database/migrations/README.md');
    }
}

exit(TestCase::ejecutar(MigracionesConsolidadasTest::class));
