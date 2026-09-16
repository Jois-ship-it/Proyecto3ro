<?php
declare(strict_types=1);

/**
 * Corre toda la batería de tests y devuelve un resumen.
 *
 * Cada archivo `*_test.php` se ejecuta en su PROPIO proceso: así ninguno hereda
 * el estado (sesión, singletons, datos) que dejó el anterior, y un fatal en uno
 * no tumba la corrida completa.
 *
 * Uso:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/run.php
 *   php tests/run.php suizo          (solo los archivos cuyo nombre contenga "suizo")
 */

$filtro  = $argv[1] ?? '';
$php     = PHP_BINARY;
$dir     = __DIR__;

$archivos = glob($dir . '/*_test.php') ?: [];
sort($archivos);

if ($filtro !== '') {
    $archivos = array_values(array_filter(
        $archivos,
        fn(string $f) => str_contains(basename($f), $filtro)
    ));
}

if ($archivos === []) {
    fwrite(STDERR, "No se encontró ningún test" . ($filtro !== "" ? " que coincida con «{$filtro}»" : "") . ".\n");
    exit(2);
}

$anchoNombre = max(array_map(fn($f) => strlen(basename($f)), $archivos));
$fallidos = [];
$inicio   = microtime(true);

echo str_repeat('=', 72) . "\n";
echo "FlexArena — batería de tests (" . count($archivos) . " archivos)\n";
echo str_repeat('=', 72) . "\n";

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);
    $t0 = microtime(true);

    $salida = [];
    $codigo = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($archivo) . ' 2>&1', $salida, $codigo);

    $ms = (microtime(true) - $t0) * 1000;
    $estado = $codigo === 0 ? 'OK   ' : 'FALLA';
    printf("\n%s  %-{$anchoNombre}s  %6.0f ms\n", $estado, $nombre, $ms);
    echo '  ' . implode("\n  ", $salida) . "\n";

    if ($codigo !== 0) $fallidos[] = $nombre;
}

echo "\n" . str_repeat('=', 72) . "\n";
printf("Total: %d archivos en %.1f s\n", count($archivos), microtime(true) - $inicio);

if ($fallidos !== []) {
    echo "FALLARON " . count($fallidos) . ": " . implode(', ', $fallidos) . "\n";
    exit(1);
}
echo "TODOS LOS TESTS PASARON\n";
exit(0);
