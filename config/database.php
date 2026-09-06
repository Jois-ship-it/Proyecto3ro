<?php
declare(strict_types=1);

// Los parámetros de conexión se toman desde $_ENV (cargado en index.php)
// Este archivo existe como referencia y punto de extensión.
// La conexión real se gestiona en core/Database.php

return [
    'host'    => $_ENV['DB_HOST']    ?? 'db',
    'port'    => $_ENV['DB_PORT']    ?? '3306',
    'dbname'  => $_ENV['DB_NAME']    ?? 'flexarena',
    'user'    => $_ENV['DB_USER']    ?? 'flexarena_user',
    'pass'    => $_ENV['DB_PASS']    ?? '',
    'charset' => 'utf8mb4',
];
