<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('CORE_PATH', BASE_PATH . '/core');

// --- Cargar variables de entorno ---
// Solo las claves que la aplicación web usa. El .env también guarda credenciales
// de ADMINISTRACIÓN (DB_ROOT_PASS) y de arranque (ADMIN_PASS, DEMO_PASS) que el
// código web no debe poder leer, ni siquiera por accidente: si un día se vuelca
// $_ENV en una página de error, no tienen que estar ahí. Esas las lee únicamente
// el arranque del contenedor, que no pasa por acá.
$clavesWeb = ['APP_NAME', 'APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (!in_array($k, $clavesWeb, true)) continue;
        $_ENV[$k] = trim($v, " \t\n\r\0\x0B\"'");
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

// --- Manejo central de errores: lo que nadie atrapa se registra y se responde ---
ManejadorErrores::registrar(APP_ENV === 'development');

// --- Política de seguridad de contenido (con un nonce por pedido; ver core/Csp.php) ---
Csp::enviar();

// --- Iniciar sesión segura ---
// La API pública (/api/…) es de solo lectura y no usa sesión: no se abre una por cada cliente que
// la consulta (ni se le manda una cookie, ni se crea un archivo de sesión por cada pedido).
$rutaPedida = isset($_GET['url']) && is_string($_GET['url']) ? trim($_GET['url'], '/') : '';
if ($rutaPedida !== 'api' && !str_starts_with($rutaPedida, 'api/')) {
    Session::start();
}

// --- Router ---
$router = new Router();
require BASE_PATH . '/config/routes.php';

$url    = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];
// Toda ruta registrada para GET debe responder igual a HEAD (spec HTTP) —
// Apache ya se encarga de no mandar el cuerpo en las respuestas HEAD, acá
// solo hace falta que el router la reconozca como si fuera GET.
if ($method === 'HEAD') {
    $method = 'GET';
}

$router->dispatch($url, $method);
