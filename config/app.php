<?php
declare(strict_types=1);

// --- Configuración general de la aplicación ---
define('APP_NAME',    $_ENV['APP_NAME']    ?? 'FlexArena');
define('APP_ENV',     $_ENV['APP_ENV']     ?? 'development');
define('APP_URL',     $_ENV['APP_URL']     ?? 'http://localhost:8080');

// Modo de error según entorno
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');
