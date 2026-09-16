<?php
declare(strict_types=1);

/**
 * Arranque común de los tests de integración.
 *
 * No toca el .env del proyecto: las variables de entorno REALES del proceso
 * tienen prioridad, y el nombre de la base se deriva con sufijo "_test" para
 * que una corrida accidental no pise datos de desarrollo.
 *
 * Uso típico (Windows/PowerShell):
 *   $env:DB_HOST="127.0.0.1"; $env:DB_USER="root"; $env:DB_PASS=""
 *   php tests/run.php
 *
 * Uso típico (Linux/Docker):
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=secreto php tests/run.php
 *
 * Deliberadamente NO define BASE_PATH ni carga config/app.php: algunos tests
 * incluyen database/seed_demo.php, que define esas constantes por su cuenta.
 */

$raizProyecto = dirname(__DIR__);

// ── 1) Configuración de conexión ────────────────────────────────────────────
$desdeEnvFile = [];
$archivoEnv   = $raizProyecto . '/.env';
if (is_readable($archivoEnv)) {
    foreach (file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
        [$k, $v] = explode('=', $linea, 2);
        $desdeEnvFile[trim($k)] = trim($v, " \t\"'");
    }
}

/** Valor efectivo: variable de entorno real > .env > default. */
$cfg = function (string $clave, string $default = '') use ($desdeEnvFile): string {
    $real = getenv($clave);
    if ($real !== false && $real !== '') return $real;
    return $desdeEnvFile[$clave] ?? $default;
};

$baseTests = getenv('DB_NAME');
if ($baseTests === false || $baseTests === '') {
    // Derivada de la base de la app, con sufijo explícito.
    $baseTests = ($desdeEnvFile['DB_NAME'] ?? 'flexarena') . '_test';
}

$_ENV['DB_HOST'] = $cfg('DB_HOST', '127.0.0.1');
$_ENV['DB_PORT'] = $cfg('DB_PORT', '3306');
$_ENV['DB_NAME'] = $baseTests;
$_ENV['DB_USER'] = $cfg('DB_USER', 'root');
$_ENV['DB_PASS'] = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($desdeEnvFile['DB_PASS'] ?? '');

// ── 2) Salvaguarda: los tests borran datos, nunca contra una base productiva ──
if (!str_ends_with($_ENV['DB_NAME'], '_test') && getenv('ALLOW_UNSAFE_DB') !== '1') {
    fwrite(STDERR, "ABORTADO: los tests truncan tablas y DB_NAME='{$_ENV['DB_NAME']}' no termina en '_test'.\n"
                 . "Usá una base descartable o exportá ALLOW_UNSAFE_DB=1 si sabés lo que hacés.\n");
    exit(2);
}

// ── 3) Autoload y entorno mínimo ────────────────────────────────────────────
spl_autoload_register(function (string $clase) use ($raizProyecto) {
    foreach ([
        "$raizProyecto/core",
        "$raizProyecto/app/models",
        "$raizProyecto/app/services",
        "$raizProyecto/app/controllers",
    ] as $dir) {
        $archivo = "$dir/$clase.php";
        if (is_file($archivo)) { require_once $archivo; return; }
    }
});

date_default_timezone_set('America/Argentina/Buenos_Aires');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ── 4) Fija el singleton de Database contra la base de pruebas ──────────────
// Debe ocurrir ANTES de incluir database/seed_demo.php, que recarga el .env real
// en $_ENV: como la conexión ya quedó abierta y cacheada, sigue apuntando acá.
// Se prueba primero con un PDO crudo porque Database::getInstance() atrapa el
// PDOException y termina con die() y un mensaje pensado para HTTP, no para CLI.
try {
    new PDO(
        "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR,
        "No se pudo conectar a '{$_ENV['DB_NAME']}' en {$_ENV['DB_HOST']}:{$_ENV['DB_PORT']} "
        . "como '{$_ENV['DB_USER']}'.\n" . $e->getMessage() . "\n\n"
        . "Preparar la base de pruebas:\n"
        . "  CREATE DATABASE {$_ENV['DB_NAME']} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "  mysql {$_ENV['DB_NAME']} < database/schema.sql\n"
        . "  mysql {$_ENV['DB_NAME']} < database/seed.sql\n"
    );
    exit(2);
}

Database::getInstance();

// ── 5) Clases de apoyo de los tests ─────────────────────────────────────────
require_once __DIR__ . '/lib/TestCase.php';
require_once __DIR__ . '/lib/Fixtures.php';

// Varios servicios leen y escriben la sesión (Auth::id(), Session::flash()) y
// AuthService::login() llama a session_regenerate_id(). En CLI no hay cookies,
// pero sí se puede abrir una sesión de archivos, y así no salen warnings.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_cookies', '0'); // en CLI no hay a quién mandarle la cookie
    @session_start();
}

// AuthService::login() llama a Session::regenerate() → session_regenerate_id().
// En CLI, apenas un test imprime su primera línea PHP considera que "los headers
// ya se enviaron" y el regenerado emite un warning. Es un artefacto del entorno,
// no del código bajo prueba: se silencia SOLO ese caso y todo lo demás sigue
// llegando a la salida.
set_error_handler(static function (int $nivel, string $mensaje): bool {
    return str_contains($mensaje, 'session_regenerate_id');
});
if (!isset($_SESSION)) {
    $_SESSION = [];
}
