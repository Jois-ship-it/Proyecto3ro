<?php
declare(strict_types=1);

$docRoot = realpath(__DIR__);
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file    = realpath($docRoot . $uri);

if ($uri !== '/' && $file && str_starts_with($file, $docRoot) && is_file($file)) {
    return false;
}

$_GET['url'] = trim($uri, '/');
require $docRoot . '/index.php';
