<?php
declare(strict_types=1);

// --- Configuración general de la aplicación ---
define('APP_NAME',    $_ENV['APP_NAME']    ?? 'FlexArena');
define('APP_ENV',     $_ENV['APP_ENV']     ?? 'development');
define('APP_URL',     $_ENV['APP_URL']     ?? 'http://localhost:8080');

// Los errores SIEMPRE se registran en el log de PHP; lo único que cambia con el entorno es
// si además se muestran en pantalla. Antes, fuera de desarrollo, error_reporting(0) apagaba
// también el registro: un error en producción no dejaba ningún rastro. Las excepciones sin
// atrapar y los errores fatales los atiende ManejadorErrores (se engancha en public/index.php).
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');

// Timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');
