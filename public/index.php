<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('CORE_PATH', BASE_PATH . '/core');

// --- Cargar variables de entorno ---
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

// --- Autoloader plano (sin Composer) ---
spl_autoload_register(function (string $class): void {
    $paths = [
        CORE_PATH,
        APP_PATH . '/controllers',
        APP_PATH . '/models',
        APP_PATH . '/services',
    ];
    foreach ($paths as $dir) {
        $file = $dir . '/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// --- Config de la app ---
require BASE_PATH . '/config/app.php';

// --- Iniciar sesión segura ---
Session::start();

// --- Router ---
$router = new Router();
require BASE_PATH . '/config/routes.php';

$url    = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

$router->dispatch($url, $method);
