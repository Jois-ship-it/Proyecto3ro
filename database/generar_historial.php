<?php
declare(strict_types=1);

require_once __DIR__ . '/entorno_cli.php';

/**
 * Genera los triggers del historial de la base de datos (tabla `historial_bd`).
 *
 * La letra pide que quede registrado cualquier cambio en la información de la base. La
 * auditoría de la aplicación (`auditoria`) cubre lo que hace una persona desde la
 * aplicación; esto cubre TODO lo demás: un script, phpMyAdmin, el cliente mysql. Cada
 * INSERT, UPDATE o DELETE sobre una tabla de datos deja una fila con cómo era el registro
 * antes y cómo quedó, quién lo hizo en la base (USER()) y, si lo hizo la aplicación,
 * para qué usuario (@id_usuario_app, que fija core/Database.php).
 *
 * Los 51 triggers son iguales salvo por las columnas de cada tabla, así que se generan
 * en vez de escribirse a mano. El bloque resultante vive, idéntico, en database/schema.sql
 * y en database/migrations/2026_10_historial_bd.sql; tests/historial_bd_test.php verifica
 * que ambos coincidan con lo que este script produce hoy. Si una tabla de datos gana una
 * columna, el test falla hasta que se vuelva a correr:
 *
 *   php database/generar_historial.php            reescribe schema.sql y la migración
 *   php database/generar_historial.php --verificar    no escribe; sale con 1 si difieren
 *
 * Qué NO se copia al historial:
 *   - Contraseñas ni datos personales. El historial no lo puede modificar la aplicación, así
 *     que lo que entre queda para siempre; si guardara nombres, documentos o emails, la
 *     anonimización que pide la ley de datos personales (ParticipanteService::anonimizarDatos)
 *     no serviría de nada. De esas columnas solo queda constancia de que cambiaron.
 *   - Las tablas de EXCLUIDAS, cada una con su motivo.
 *
 * Limitaciones del motor, que no son de este script: TRUNCATE, DROP y los borrados en
 * cascada por clave foránea no activan triggers. La aplicación no puede hacer ninguna de las
 * dos primeras (no tiene el privilegio) y nunca borra torneos: los cancela.
 */
final class GeneradorHistorial
{
    /** Tablas que NO se registran, y por qué. */
    public const EXCLUIDAS = [
        'auditoria'        => 'es el registro de la aplicación: registrarla sería duplicarla',
        'historial_bd'     => 'es el propio historial',
        'intentos_login'   => 'registro operativo que se purga solo: sería puro ruido',
        'tabla_posiciones' => 'dato DERIVADO: se recalcula entera con cada resultado y llenaría el historial de ruido; la fuente (enfrentamientos y resultados) sí se registra',
    ];

    /**
     * Columnas que nunca se copian, por tabla. De ellas solo se deja constancia de que
     * alguna cambió (la clave `datos_reservados_modificados` del valor nuevo).
     *
     * @var array<string, array<string, string>> tabla => [columna => motivo]
     */
    public const RESERVADAS = [
        'usuarios' => [
            'password_hash' => 'secreto',
            'nombre'        => 'dato personal',
            'email'         => 'dato personal',
        ],
        'participantes' => [
            'nombre'    => 'dato personal',
            'documento' => 'dato personal',
            'nick'      => 'dato personal',
            'email'     => 'dato personal',
            'telefono'  => 'dato personal',
            'foto'      => 'dato personal',
        ],
    ];

    /**
     * Columnas que se copian pero con el valor tapado según la fila. `{r}` es NEW u OLD.
     * Son ajustes genéricos (clave/valor) cuyo valor, para ciertas claves, es un contacto.
     *
     * @var array<string, array<string, string>> tabla => [columna => expresión SQL]
     */
    public const OCULTAS_SEGUN_FILA = [
        'configuraciones_torneo' => ['valor' => "IF({r}.clave = 'contacto', '(oculto)', {r}.valor)"],
        'configuracion_sistema'  => ['valor' => "IF({r}.clave = 'sitio_contacto', '(oculto)', {r}.valor)"],
    ];

    /**
     * Clave primaria de las tablas que no usan una columna `id`. El resto usa `id`.
     *
     * @var array<string, string[]>
     */
    public const CLAVES = [
        'configuracion_sistema' => ['clave'],
        'torneo_organizadores'  => ['torneo_id', 'usuario_id'],
    ];

    public const MARCA_INICIO = '-- >>> HISTORIAL';
    public const MARCA_FIN    = '-- <<< HISTORIAL';

    /** @return string[] las tablas de datos que se registran, ordenadas */
    public static function tablasRegistradas(PDO $db): array
    {
        $todas = $db->query(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
              ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            array_map('strval', $todas),
            fn(string $t) => !isset(self::EXCLUIDAS[$t])
        ));
    }

    /** @return string[] */
    public static function columnasDe(PDO $db, string $tabla): array
    {
        $st = $db->prepare(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$tabla]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return string[] */
    public static function claveDe(string $tabla): array
    {
        return self::CLAVES[$tabla] ?? ['id'];
    }

    /**
     * Las columnas que se copian a JSON y las reservadas, para una tabla.
     *
     * @param  string[] $columnas todas las columnas de la tabla
     * @return array{0: string[], 1: string[]} [copiadas, reservadas]
     */
    public static function separarColumnas(string $tabla, array $columnas): array
    {
        $reservadas = array_keys(self::RESERVADAS[$tabla] ?? []);
        foreach ($reservadas as $r) {
            if (!in_array($r, $columnas, true)) {
                throw new RuntimeException("RESERVADAS nombra {$tabla}.{$r}, que no existe.");
            }
        }
        foreach (array_keys(self::OCULTAS_SEGUN_FILA[$tabla] ?? []) as $o) {
            if (!in_array($o, $columnas, true)) {
                throw new RuntimeException("OCULTAS_SEGUN_FILA nombra {$tabla}.{$o}, que no existe.");
            }
        }
        foreach (self::claveDe($tabla) as $k) {
            if (!in_array($k, $columnas, true)) {
                throw new RuntimeException("La clave de {$tabla} nombra la columna {$k}, que no existe.");
            }
        }
        return [array_values(array_diff($columnas, $reservadas)), $reservadas];
    }

    /** Parte una lista en renglones de a lo sumo $ancho caracteres, indentados. */
    private static function envolver(array $items, string $sangria, int $ancho = 92, string $separador = ', '): string
    {
        $lineas = [];
        $actual = '';
        foreach ($items as $i => $item) {
            $pieza = $item . ($i < count($items) - 1 ? $separador : '');
            if ($actual !== '' && strlen($sangria . $actual . $pieza) > $ancho) {
                $lineas[] = rtrim($sangria . $actual);
                $actual = '';
            }
            $actual .= $pieza;
        }
        if ($actual !== '') $lineas[] = $sangria . $actual;
        return implode("\n", $lineas);
    }

    /** JSON_OBJECT(...) de una fila (prefijo NEW u OLD). */
    private static function json(string $tabla, array $copiadas, string $fila, string $extra = ''): string
    {
        $pares = [];
        foreach ($copiadas as $c) {
            $expr = isset(self::OCULTAS_SEGUN_FILA[$tabla][$c])
                ? str_replace('{r}', $fila, self::OCULTAS_SEGUN_FILA[$tabla][$c])
                : "{$fila}.{$c}";
            $pares[] = "'{$c}', {$expr}";
        }
        if ($extra !== '') $pares[] = $extra;
        return "JSON_OBJECT(\n" . self::envolver($pares, '            ') . ')';
    }

    private static function registroId(string $tabla, string $fila): string
    {
        $k = array_map(fn($c) => "{$fila}.{$c}", self::claveDe($tabla));
        return count($k) === 1 ? $k[0] : 'CONCAT_WS(\'-\', ' . implode(', ', $k) . ')';
    }

    /** Los tres triggers de una tabla. */
    public static function triggersDe(PDO $db, string $tabla): string
    {
        [$copiadas, $reservadas] = self::separarColumnas($tabla, self::columnasDe($db, $tabla));
        $todas = array_merge($copiadas, $reservadas);

        $notas = [];
        if ($reservadas) {
            $notas[] = 'no copia: ' . implode(', ', $reservadas);
        }
        $nota = $notas ? '  (' . implode('; ', $notas) . ')' : '';

        $cabecera = 'INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)';

        // Para saber si hubo un cambio real, se comparan TODAS las columnas, también las
        // reservadas: cambiar solo un nombre es un cambio aunque el historial no guarde cuál.
        // `updated_at` queda afuera: se actualiza sola y no es un cambio de los datos.
        $comparables = array_values(array_filter($todas, fn($c) => $c !== 'updated_at'));
        $igual = self::envolver(
            array_map(fn($c) => "OLD.{$c} <=> NEW.{$c}", $comparables), '          ', 88, ' AND ');
        $extraNuevo = $reservadas
            ? "'datos_reservados_modificados', NOT (" . implode(' AND ', array_map(fn($c) => "OLD.{$c} <=> NEW.{$c}", $reservadas)) . ')'
            : '';

        $ai = "DROP TRIGGER IF EXISTS trg_hist_{$tabla}_ai;\n"
            . "CREATE TRIGGER trg_hist_{$tabla}_ai AFTER INSERT ON {$tabla} FOR EACH ROW\n"
            . "  {$cabecera}\n"
            . "  VALUES ('{$tabla}', 'INSERT', " . self::registroId($tabla, 'NEW') . ", NULL,\n"
            . '          ' . self::json($tabla, $copiadas, 'NEW') . ",\n"
            . "          @id_usuario_app, USER());\n";

        $au = "DROP TRIGGER IF EXISTS trg_hist_{$tabla}_au;\n"
            . "CREATE TRIGGER trg_hist_{$tabla}_au AFTER UPDATE ON {$tabla} FOR EACH ROW\n"
            . "  {$cabecera}\n"
            . "  SELECT '{$tabla}', 'UPDATE', " . self::registroId($tabla, 'NEW') . ",\n"
            . '         ' . self::json($tabla, $copiadas, 'OLD') . ",\n"
            . '         ' . self::json($tabla, $copiadas, 'NEW', $extraNuevo) . ",\n"
            . "         @id_usuario_app, USER()\n"
            . "  FROM DUAL\n"
            . "  WHERE NOT (\n" . $igual . ');' . "\n";

        $ad = "DROP TRIGGER IF EXISTS trg_hist_{$tabla}_ad;\n"
            . "CREATE TRIGGER trg_hist_{$tabla}_ad AFTER DELETE ON {$tabla} FOR EACH ROW\n"
            . "  {$cabecera}\n"
            . "  VALUES ('{$tabla}', 'DELETE', " . self::registroId($tabla, 'OLD') . ",\n"
            . '          ' . self::json($tabla, $copiadas, 'OLD') . ",\n"
            . "          NULL, @id_usuario_app, USER());\n";

        return "-- {$tabla}{$nota}\n" . $ai . "\n" . $au . "\n" . $ad;
    }

    /** El bloque completo, con sus marcas, tal como va en schema.sql y en la migración. */
    public static function bloque(PDO $db): string
    {
        $partes = [self::MARCA_INICIO . ' (generado por database/generar_historial.php; no editar a mano)', ''];
        foreach (self::tablasRegistradas($db) as $tabla) {
            $partes[] = self::triggersDe($db, $tabla);
        }
        $partes[] = self::MARCA_FIN;
        return implode("\n", $partes);
    }

    /** Extrae el bloque marcado de un archivo SQL, o null si no lo tiene. */
    public static function extraer(string $sql): ?string
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $a = strpos($sql, self::MARCA_INICIO);
        $b = strpos($sql, self::MARCA_FIN);
        if ($a === false || $b === false || $b < $a) return null;
        return substr($sql, $a, $b + strlen(self::MARCA_FIN) - $a);
    }

    /** La migración entera: la tabla (si falta) más el bloque. */
    public static function migracion(string $bloque): string
    {
        return <<<SQL
-- ============================================================
-- Migración: historial de la base de datos (tabla `historial_bd` y sus triggers)
--
-- La letra pide que quede registrado cualquier cambio en la información de la base.
-- `auditoria` registra lo que hace una persona desde la aplicación; esto registra TODO lo
-- que cambie en las tablas de datos, venga de donde venga: un script, phpMyAdmin, el cliente
-- mysql. Cada INSERT, UPDATE o DELETE deja una fila con el registro antes y después.
--
-- Los triggers los genera database/generar_historial.php: son iguales salvo por las columnas
-- de cada tabla. NO se editan a mano; si una tabla gana una columna se vuelve a generar
-- (tests/historial_bd_test.php avisa). El mismo bloque está en database/schema.sql.
--
-- No guarda contraseñas ni datos personales (nombre, documento, email, teléfono…): el
-- historial no lo puede modificar la aplicación y la anonimización que pide la ley de datos
-- personales no serviría de nada si ahí quedaran. De esas columnas solo deja constancia de
-- que cambiaron.
--
-- La aplicación solo puede LEER la tabla; la escriben los triggers con los privilegios de
-- quien los creó, así que ni una inyección SQL puede falsificarla.
--
-- Idempotente: la tabla se crea si falta y los triggers se recrean.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"\$DB_ROOT_PASS" flexarena < database/migrations/2026_10_historial_bd.sql
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS historial_bd (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tabla          VARCHAR(64)  NOT NULL,
    operacion      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    registro_id    VARCHAR(100) NOT NULL,
    valor_anterior JSON DEFAULT NULL,
    valor_nuevo    JSON DEFAULT NULL,
    usuario_app_id INT UNSIGNED DEFAULT NULL,
    usuario_bd     VARCHAR(300) NOT NULL,
    created_at     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_tabla_registro (tabla, registro_id),
    INDEX idx_created (created_at),
    INDEX idx_usuario_app (usuario_app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

{$bloque}

SELECT 'Historial de la base de datos instalado.' AS resultado;

SQL;
    }

    /** Reemplaza el bloque marcado de un archivo, o lo inserta antes de $antesDe. */
    private static function colocar(string $archivo, string $bloque, string $antesDe): void
    {
        $sql = str_replace("\r\n", "\n", (string) file_get_contents($archivo));
        $actual = self::extraer($sql);
        if ($actual !== null) {
            $sql = str_replace($actual, $bloque, $sql);
        } else {
            if (substr_count($sql, $antesDe) !== 1) {
                throw new RuntimeException("No se encontró un único «{$antesDe}» en {$archivo}.");
            }
            $sql = str_replace($antesDe, $bloque . "\n\n" . $antesDe, $sql);
        }
        file_put_contents($archivo, $sql);
    }

    /** Punto de entrada de la línea de comandos. Devuelve el código de salida. */
    public static function ejecutarCli(array $argv): int
    {
        $raiz = dirname(__DIR__);
        $e = entornoCli($raiz, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_ROOT_USER', 'DB_ROOT_PASS']);
        if (($e['DB_ROOT_PASS'] ?? '') === '') {
            fwrite(STDERR, "Falta DB_ROOT_PASS (hay que leer el esquema de una base que ya lo tenga cargado).\n");
            return 2;
        }

        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $e['DB_HOST'] ?? 'db', $e['DB_PORT'] ?? '3306', $e['DB_NAME'] ?? 'flexarena'),
            $e['DB_ROOT_USER'] ?? 'root', $e['DB_ROOT_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $bloque    = self::bloque($db);
        $esquema   = $raiz . '/database/schema.sql';
        $migracion = $raiz . '/database/migrations/2026_10_historial_bd.sql';

        if (in_array('--verificar', $argv, true)) {
            $enEsquema   = self::extraer((string) file_get_contents($esquema));
            $enMigracion = self::extraer((string) @file_get_contents($migracion));
            if ($enEsquema === $bloque && $enMigracion === $bloque) {
                echo "El historial de schema.sql y de la migración está al día.\n";
                return 0;
            }
            fwrite(STDERR, "El historial versionado no coincide con las tablas actuales. Correr: php database/generar_historial.php\n");
            return 1;
        }

        self::colocar($esquema, $bloque, '-- >>> VISTAS');
        file_put_contents($migracion, self::migracion($bloque));
        echo "Historial regenerado: " . count(self::tablasRegistradas($db)) . " tablas, "
           . (count(self::tablasRegistradas($db)) * 3) . " triggers.\n";
        echo "  - database/schema.sql\n  - database/migrations/2026_10_historial_bd.sql\n";
        return 0;
    }
}

// Solo corre cuando se invoca como script, no cuando un test lo incluye.
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(GeneradorHistorial::ejecutarCli($argv));
}
