<?php
declare(strict_types=1);

/**
 * Test de que `schema.sql` y las migraciones cuentan la misma historia.
 *
 * La promesa que se documenta en `database/migrations/README.md` es concreta:
 * para una instalación nueva alcanza con schema.sql + seed.sql, y las
 * migraciones no agregan nada. Docker depende de eso —monta solo esos archivos
 * en docker-entrypoint-initdb.d— así que si dejara de ser cierto, una base
 * recién levantada quedaría incompleta sin que nadie se entere.
 *
 * Se comprueba en dos escenarios, los dos sobre bases DESCARTABLES que crea el
 * propio test (nunca sobre la base de pruebas compartida):
 *
 *  1. Esquema actual + todas las migraciones. Son idempotentes, así que no
 *     deberían cambiar nada. Lo que sí cambie es estructura que schema.sql no
 *     tiene, o una migración vieja que pisa algo nuevo.
 *
 *  2. La foto del esquema de septiembre (tests/fixtures/schema_2026_09_17.sql) +
 *     las migraciones POSTERIORES a esa foto. Es el camino de actualización
 *     real de una instalación que ya existe, y tiene que terminar idéntica a una
 *     base recién creada con schema.sql. Es el único escenario donde las
 *     migraciones nuevas hacen algo de verdad.
 *
 * Por qué bases descartables y no la de pruebas: esa ya trae los datos de
 * seed.sql, y en MySQL 8.0 (modo estricto) la migración
 * 2026_06_registro_participantes.sql falla contra ellos. Redefine el ENUM de
 * usuarios.estado sin 'bloqueada' y el seed tiene una cuenta bloqueada: «Data
 * truncated». En MariaDB sin modo estricto el valor se perdía en silencio, que es
 * la trampa que cuenta el README. Sobre una base sin filas el ALTER no tiene qué
 * truncar, y lo que se compara es la estructura, que es lo que este test promete.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/migraciones_consolidadas_test.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/BasesDescartables.php';

class MigracionesConsolidadasTest extends TestCase
{
    /**
     * Lo que sí cambia al aplicar las migraciones sobre un esquema actual, y por qué.
     *
     * @var array<string, string>
     */
    private const DIFERENCIAS_ESPERADAS = [
        'fk permisos.fk_permisos_modulo' =>
            '2026_06_fk_permisos_modulos.sql agrega una FK que schema.sql ya crea '
            . '(permisos_ibfk_2): queda duplicada. Sobra, no falta.',
        'indice permisos.fk_permisos_modulo' =>
            'La FK duplicada se apoya en un índice propio.',
        'indice permisos.modulo_slug' =>
            'La contracara de lo anterior: al crear la FK con nombre, el motor '
            . 'renombra el índice automático «modulo_slug» a «fk_permisos_modulo». '
            . 'La columna sigue indexada igual.',
    ];

    /** Última migración que la foto de septiembre ya trae aplicada. */
    private const ULTIMA_EN_LA_FOTO = '2026_09_rondas_estado.sql';

    private static bool $listo = false;

    private static array $actual         = [];  // schema.sql recién aplicado
    private static array $conMigraciones = [];  // …y después de todas las migraciones
    private static array $foto           = [];  // la foto de septiembre, sin migrar
    private static array $fotoMigrada    = [];  // …y después de las migraciones posteriores

    /** @return string[] rutas de las migraciones, en orden de aplicación */
    private function migraciones(): array
    {
        $archivos = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
        sort($archivos);   // el prefijo aaaa_mm da el orden cronológico
        return $archivos;
    }

    /** @return string[] las que la foto de septiembre todavía no tiene */
    private function migracionesPosteriores(): array
    {
        return array_values(array_filter(
            $this->migraciones(),
            fn(string $f) => strcmp(basename($f), self::ULTIMA_EN_LA_FOTO) > 0
        ));
    }

    private function aplicarMigracion(PDO $pdo, string $ruta): void
    {
        try {
            BasesDescartables::ejecutarArchivo($pdo, $ruta);
        } catch (PDOException $e) {
            $this->fallar('La migración ' . basename($ruta) . ' falló: ' . $e->getMessage());
        }
    }

    /**
     * Foto de la estructura: columnas con su tipo exacto, índices, claves
     * foráneas, restricciones CHECK, vistas y triggers.
     *
     * Todas las columnas se piden con alias en minúscula: MySQL 8.0 devuelve las
     * de information_schema en mayúscula (TABLE_NAME) y MariaDB en minúscula.
     *
     * @return array<string, string>
     */
    private function estructura(PDO $pdo): array
    {
        $foto = [];

        $columnas = $pdo->query(
            "SELECT table_name AS table_name, column_name AS column_name,
                    column_type AS column_type, is_nullable AS is_nullable,
                    column_default AS column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
              ORDER BY table_name, column_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columnas as $c) {
            $clave = "columna {$c['table_name']}.{$c['column_name']}";
            $foto[$clave] = $c['column_type'] . ' | null=' . $c['is_nullable']
                          . ' | default=' . var_export($c['column_default'], true);
        }

        $indices = $pdo->query(
            "SELECT table_name AS table_name, index_name AS index_name,
                    GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols,
                    non_unique AS non_unique
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
              GROUP BY table_name, index_name, non_unique
              ORDER BY table_name, index_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indices as $i) {
            $foto["indice {$i['table_name']}.{$i['index_name']}"] =
                $i['cols'] . ' | unico=' . ($i['non_unique'] ? 'no' : 'si');
        }

        $claves = $pdo->query(
            "SELECT k.table_name AS table_name, k.constraint_name AS constraint_name,
                    GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position) AS cols,
                    k.referenced_table_name AS ref_tabla,
                    GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position) AS ref_cols,
                    r.update_rule AS al_actualizar, r.delete_rule AS al_borrar
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema = k.constraint_schema
                AND r.constraint_name   = k.constraint_name
                AND r.table_name        = k.table_name
              WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
              GROUP BY k.table_name, k.constraint_name, k.referenced_table_name,
                       r.update_rule, r.delete_rule
              ORDER BY k.table_name, k.constraint_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($claves as $k) {
            $foto["fk {$k['table_name']}.{$k['constraint_name']}"] =
                "{$k['cols']} -> {$k['ref_tabla']}({$k['ref_cols']})"
                . " | al actualizar={$k['al_actualizar']} | al borrar={$k['al_borrar']}";
        }

        $checks = $pdo->query(
            "SELECT t.table_name AS table_name, t.constraint_name AS constraint_name,
                    c.check_clause AS check_clause
               FROM information_schema.table_constraints t
               JOIN information_schema.check_constraints c
                 ON c.constraint_schema = t.constraint_schema
                AND c.constraint_name   = t.constraint_name
              WHERE t.table_schema = DATABASE() AND t.constraint_type = 'CHECK'
              ORDER BY t.table_name, t.constraint_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($checks as $c) {
            $foto["check {$c['table_name']}.{$c['constraint_name']}"] = $c['check_clause'];
        }

        // Las definiciones de vistas traen el nombre de la base entre comillas
        // (`flexarena_test_mig_a`.`torneos`), y las dos bases descartables se llaman
        // distinto: se lo saca para comparar el contenido y no el nombre.
        $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        $vistas = $pdo->query(
            "SELECT table_name AS table_name, view_definition AS view_definition
               FROM information_schema.views
              WHERE table_schema = DATABASE()
              ORDER BY table_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($vistas as $v) {
            $foto["vista {$v['table_name']}"] = str_replace('`' . $base . '`.', '', $v['view_definition']);
        }

        $triggers = $pdo->query(
            "SELECT trigger_name AS trigger_name, event_object_table AS tabla,
                    action_timing AS momento, event_manipulation AS evento,
                    action_statement AS cuerpo
               FROM information_schema.triggers
              WHERE trigger_schema = DATABASE()
              ORDER BY trigger_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($triggers as $t) {
            $foto["trigger {$t['trigger_name']}"] =
                "{$t['momento']} {$t['evento']} ON {$t['tabla']} | " . str_replace('`' . $base . '`.', '', $t['cuerpo']);
        }

        return $foto;
    }

    /**
     * Diferencias entre dos fotos, en frases legibles.
     *
     * @param  array<string,string> $a
     * @param  array<string,string> $b
     * @return string[]
     */
    private function diferencias(array $a, array $b, string $nombreA, string $nombreB, bool $ignorarConocidas = false): array
    {
        $dif = [];
        foreach ($a as $clave => $valor) {
            if ($ignorarConocidas && $this->esConocida($clave)) continue;
            if (!array_key_exists($clave, $b)) {
                $dif[] = "{$clave}: está en {$nombreA} y no en {$nombreB}";
            } elseif ($valor !== $b[$clave]) {
                $dif[] = "{$clave}:\n            {$nombreA}: {$valor}\n            {$nombreB}: {$b[$clave]}";
            }
        }
        foreach ($b as $clave => $_) {
            if ($ignorarConocidas && $this->esConocida($clave)) continue;
            if (!array_key_exists($clave, $a)) {
                $dif[] = "{$clave}: está en {$nombreB} y no en {$nombreA}";
            }
        }
        return $dif;
    }

    /** ¿Esta diferencia está declarada y explicada en DIFERENCIAS_ESPERADAS? */
    private function esConocida(string $clave): bool
    {
        foreach (array_keys(self::DIFERENCIAS_ESPERADAS) as $conocida) {
            if (str_contains($clave, $conocida)) return true;
        }
        return false;
    }

    protected function setUp(): void
    {
        if (self::$listo) return;

        $raiz = dirname(__DIR__);

        // Escenario 1: el esquema actual, antes y después de TODAS las migraciones.
        $a = BasesDescartables::crear('mig_a', $raiz . '/database/schema.sql');
        self::$actual = $this->estructura($a);
        foreach ($this->migraciones() as $archivo) {
            $this->aplicarMigracion($a, $archivo);
        }
        self::$conMigraciones = $this->estructura($a);

        // Escenario 2: la foto de septiembre, antes y después de las posteriores.
        $b = BasesDescartables::crear('mig_b', __DIR__ . '/fixtures/schema_2026_09_17.sql');
        self::$foto = $this->estructura($b);
        foreach ($this->migracionesPosteriores() as $archivo) {
            $this->aplicarMigracion($b, $archivo);
        }
        self::$fotoMigrada = $this->estructura($b);

        self::$listo = true;
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
        $this->assertTrue(self::$listo);
        $this->assertGreaterThan(0, count(self::$conMigraciones));
    }

    // ─── Escenario 1: y no cambian nada ─────────────────────────────────────

    public function test_ninguna_migracion_agrega_algo_que_falte_en_el_esquema(): void
    {
        $agregado = array_diff_key(self::$conMigraciones, self::$actual);

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
        foreach (self::$actual as $clave => $valorAntes) {
            if ($this->esConocida($clave)) continue;
            if (!array_key_exists($clave, self::$conMigraciones)) {
                $cambios[] = "{$clave}: desapareció";
                continue;
            }
            $valorDespues = self::$conMigraciones[$clave];
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
        $agregado = array_keys(array_diff_key(self::$conMigraciones, self::$actual));

        $duplicada = array_filter($agregado, fn(string $c) => str_contains($c, 'fk_permisos_modulo'));

        $this->assertGreaterThan(0, count($duplicada),
            'ya no aparece la FK duplicada: actualizá DIFERENCIAS_ESPERADAS y database/migrations/README.md');
    }

    // ─── Escenario 2: actualizar una base de septiembre ─────────────────────

    public function test_la_foto_de_septiembre_es_de_verdad_anterior_al_esquema_actual(): void
    {
        // Control del escenario. Si la foto fuera igual al esquema actual, el
        // test de abajo pasaría de vacío: nada que migrar, nada que comparar.
        if ($this->migracionesPosteriores() === []) {
            $this->assertSame([], $this->diferencias(self::$foto, self::$actual, 'la foto', 'schema.sql'),
                'no hay migraciones posteriores a la foto, así que schema.sql y la foto deberían coincidir');
            return;
        }

        $this->assertGreaterThan(0, count($this->diferencias(self::$foto, self::$actual, 'la foto', 'schema.sql')),
            'hay migraciones posteriores a la foto de septiembre, pero schema.sql no difiere de ella: '
            . 'la foto ya no sirve para probar la actualización (hay que actualizarla o retirarla)');
    }

    public function test_una_base_de_septiembre_migrada_queda_igual_que_schema_sql(): void
    {
        $dif = $this->diferencias(self::$fotoMigrada, self::$actual, 'base migrada', 'schema.sql');

        $this->assertCount(0, $dif,
            "actualizar una base de septiembre con las migraciones nuevas no deja el esquema de schema.sql:\n        - "
            . implode("\n        - ", $dif));
    }
}

TestCase::correrSiEsScript(MigracionesConsolidadasTest::class);
