<?php
declare(strict_types=1);

/**
 * Configuración de los scripts de arranque (los de database/ que corren desde la
 * línea de comandos): variables de entorno reales > archivo .env > nada.
 *
 * Las variables reales ganan porque en Docker la configuración llega por el
 * entorno del contenedor y el .env puede ni existir. Devuelve únicamente las
 * claves pedidas, para que un script no cargue por accidente credenciales que no
 * necesita. Nunca imprime valores.
 *
 * @param  string[] $claves
 * @return array<string, string>
 */
function entornoCli(string $raiz, array $claves): array
{
    $desdeArchivo = [];
    $archivo = $raiz . '/.env';
    if (is_readable($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
            [$k, $v] = explode('=', $linea, 2);
            $desdeArchivo[trim($k)] = trim($v, " \t\"'");
        }
    }

    $valores = [];
    foreach ($claves as $clave) {
        $real = getenv($clave);
        if ($real !== false && $real !== '') {
            $valores[$clave] = $real;
        } elseif (isset($desdeArchivo[$clave])) {
            $valores[$clave] = $desdeArchivo[$clave];
        }
    }
    return $valores;
}
