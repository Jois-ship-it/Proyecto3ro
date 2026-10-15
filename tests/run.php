<?php
declare(strict_types=1);

/**
 * Corre toda la batería de tests y devuelve un resumen.
 *
 * Cada archivo `*_test.php` se ejecuta en su PROPIO proceso: así ninguno hereda
 * el estado (sesión, singletons, datos) que dejó el anterior, y un fatal en uno
 * no tumba la corrida completa.
 *
 * También corre los `*_test.sh` (los que prueban los scripts de administración).
 * Si no hay `bash` disponible, se los marca como omitidos en vez de falsear un
 * error: en Windows vienen con Git Bash, pero puede no estar instalado.
 *
 * Con `--phpunit` los mismos archivos corren sobre PHPUnit 11 (tools/phpunit/phpunit-11.phar, configuración en phpunit.xml): sus
 * aserciones, su informe y su manera de contar. Sigue siendo un proceso por archivo. PHPUnit exige que el archivo se llame como su
 * clase (PaginadorTest.php) y estos se llaman paginador_test.php: por cada clase se escribe, en un directorio temporal, un archivo
 * con el nombre que PHPUnit espera que carga el test y lo subclasifica (por eso las clases de test no son `final`).
 *
 * Uso:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/run.php
 *   php tests/run.php suizo          (solo los archivos cuyo nombre contenga "suizo")
 *   php tests/run.php --phpunit      (la misma batería, con PHPUnit)
 *   php tests/run.php --phpunit suizo
 */

$usarPhpunit = in_array('--phpunit', $argv, true);
$posicionales = array_values(array_filter(array_slice($argv, 1), fn(string $a) => $a !== '--phpunit'));
$filtro  = $posicionales[0] ?? '';
$php     = PHP_BINARY;
$dir     = __DIR__;

$phar = null;
$puentes = null;   // directorio temporal con los archivos que PHPUnit carga (solo con --phpunit)
if ($usarPhpunit) {
    // El que dejó scripts/instalar_herramientas_de_prueba.sh (tools/phpunit/phpunit-<versión>.phar); si hay varios, el más nuevo.
    $instalados = glob(dirname($dir) . '/tools/phpunit/phpunit-*.phar') ?: [];
    usort($instalados, fn(string $a, string $b) => version_compare(basename($a, '.phar'), basename($b, '.phar')));
    $phar = end($instalados) ?: null;
    if ($phar === null) {
        fwrite(STDERR, "Falta PHPUnit (tools/phpunit/phpunit-<versión>.phar).\nInstalarlo, con su firma verificada: bash scripts/instalar_herramientas_de_prueba.sh phpunit\n");
        exit(2);
    }
    $puentes = sys_get_temp_dir() . '/flexarena_phpunit_' . getmypid();
    mkdir($puentes, 0700, true);
    register_shutdown_function(function () use ($puentes) {
        foreach (glob($puentes . '/*') ?: [] as $f) @unlink($f);
        @rmdir($puentes);
    });
    // El .phar se ejecuta desde el disco local: leerlo del montaje de Windows en Docker tarda ~15 s por arranque (1,5 s desde acá).
    copy($phar, $puentes . '/phpunit.phar');
    $phar = $puentes . '/phpunit.phar';
}

/**
 * Los archivos que PHPUnit tiene que cargar para un test: uno por cada clase que extiende TestCase (casi siempre una).
 *
 * El archivo lleva el nombre de la clase, que es lo que PHPUnit exige; adentro carga el test de verdad y lo subclasifica.
 * El nombre que se lee en el informe sale de la clase («PaginadorTest» → «Paginador»).
 *
 * @return string[]
 */
function puentesDe(string $archivo, string $puentes): array
{
    preg_match_all('/^(?:abstract\s+)?class\s+(\w+)\s+extends\s+TestCase\b/m', (string) file_get_contents($archivo), $m);
    $rutas = [];
    foreach ($m[1] as $clase) {
        $legible = trim((string) preg_replace('/(?<!^)(?=[A-Z])/', ' ', (string) preg_replace('/Test$/', '', $clase)));
        $ruta = "{$puentes}/{$clase}.php";
        file_put_contents($ruta,
            "<?php\n"
            . 'require_once ' . var_export($archivo, true) . ";\n"
            . '#[\PHPUnit\Framework\Attributes\TestDox(' . var_export($legible, true) . ")]\n"
            . "final class Phpunit_{$clase} extends {$clase} {}\n");
        $rutas[] = $ruta;
    }
    return $rutas;
}

$archivos = array_merge(glob($dir . '/*_test.php') ?: [], glob($dir . '/*_test.sh') ?: []);
sort($archivos);

// ¿Se pueden correr los tests de shell en esta máquina?
exec('bash -c "exit 0" 2>&1', $descarte, $codigoBash);
$hayBash = ($codigoBash === 0);

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
$omitidos = [];
$inicio   = microtime(true);
$totalCasos = 0;
$totalAserciones = 0;

echo str_repeat('=', 72) . "\n";
echo "FlexArena — batería de tests (" . count($archivos) . " archivos)\n";
if ($usarPhpunit) {
    exec(escapeshellarg($php) . ' ' . escapeshellarg($phar) . ' --version 2>&1', $version);
    echo ($version[0] ?? 'PHPUnit') . "\n";
}
echo str_repeat('=', 72) . "\n";

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);
    $t0 = microtime(true);

    $esShell = str_ends_with($archivo, '.sh');

    if ($esShell && !$hayBash) {
        printf("
OMIT   %-{$anchoNombre}s  (no hay bash en esta máquina)
", $nombre);
        $omitidos[] = $nombre;
        continue;
    }

    $salida = [];
    $codigo = 0;
    if ($usarPhpunit && !$esShell) {
        // Una corrida de PHPUnit por clase (casi siempre una por archivo).
        foreach (puentesDe($archivo, $puentes) as $puente) {
            $parcial = [];
            $c = 0;
            exec(escapeshellarg($php) . ' ' . escapeshellarg($phar)
                . ' --configuration ' . escapeshellarg(dirname($dir) . '/phpunit.xml')
                . ' --do-not-cache-result --no-progress --testdox ' . escapeshellarg($puente) . ' 2>&1', $parcial, $c);
            foreach ($parcial as $linea) {
                if (preg_match('/^OK \((\d+) tests?, (\d+) assertions?\)/', $linea, $n)
                    || preg_match('/^Tests: (\d+), Assertions: (\d+)/', $linea, $n)) {
                    $totalCasos += (int) $n[1];
                    $totalAserciones += (int) $n[2];
                }
            }
            // El encabezado de PHPUnit se repetiría en cada archivo: se imprime una sola vez, arriba.
            $salida = array_merge($salida, array_values(array_filter($parcial, fn(string $l) => !str_starts_with($l, 'PHPUnit ') && !str_starts_with($l, 'Runtime:'))));
            $codigo |= $c;
        }
    } else {
        $comando = $esShell
            ? 'bash ' . escapeshellarg($archivo)
            : escapeshellarg($php) . ' ' . escapeshellarg($archivo);
        exec($comando . ' 2>&1', $salida, $codigo);
    }

    $ms = (microtime(true) - $t0) * 1000;
    $estado = $codigo === 0 ? 'OK   ' : 'FALLA';
    printf("\n%s  %-{$anchoNombre}s  %6.0f ms\n", $estado, $nombre, $ms);
    echo '  ' . implode("\n  ", $salida) . "\n";

    if ($codigo !== 0) $fallidos[] = $nombre;
}

echo "\n" . str_repeat('=', 72) . "\n";
printf("Total: %d archivos en %.1f s\n", count($archivos), microtime(true) - $inicio);
if ($usarPhpunit) printf("PHPUnit: %d casos, %d aserciones (los .sh no pasan por PHPUnit)\n", $totalCasos, $totalAserciones);

if ($fallidos !== []) {
    echo "FALLARON " . count($fallidos) . ": " . implode(', ', $fallidos) . "\n";
    exit(1);
}
echo "TODOS LOS TESTS PASARON\n";
exit(0);
