<?php
declare(strict_types=1);

/**
 * Lo que un listado acepta de la dirección (?q=…&estado=…&desde=…).
 *
 * Todo lo que llega por GET es texto de un desconocido: acá se lo reduce a algo seguro de usar
 * en una consulta y de volver a dibujar en un formulario. Un valor que no corresponde
 * (un estado inventado, una fecha imposible, un arreglo en lugar de un texto) no es un error:
 * se descarta y el listado se ve como si ese filtro no estuviera. Así una dirección vieja o
 * escrita a mano nunca da una pantalla de error.
 *
 * Esto NO reemplaza a los parámetros enlazados de PDO: el texto igual viaja por ahí. Lo que
 * hace es acotar el largo, sacar caracteres de control y escapar los comodines del LIKE.
 */
final class Filtros
{
    /** Largo máximo del texto de una búsqueda, en caracteres. */
    public const LARGO_BUSQUEDA = 80;

    /** Carácter de escape de los LIKE (se declara en la consulta con ESCAPE '|'). */
    public const ESCAPE_LIKE = '|';

    /**
     * Un texto de búsqueda: sin espacios sobrantes, sin caracteres de control y de a lo sumo
     * $max caracteres. Todo lo que no sea un texto (un arreglo, null) queda en ''.
     */
    public static function texto(mixed $valor, int $max = self::LARGO_BUSQUEDA): string
    {
        if (!is_string($valor)) {
            return '';
        }
        $limpio = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor);
        if ($limpio === null) {            // no era UTF-8 válido
            return '';
        }
        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio) ?? '');
        return mb_substr($limpio, 0, $max);
    }

    /**
     * Una de las opciones permitidas, o '' si no lo es. Compara como texto y de forma exacta.
     *
     * @param array<int|string, mixed> $validas los valores aceptados
     */
    public static function opcion(mixed $valor, array $validas): string
    {
        if (!is_string($valor)) {
            return '';
        }
        foreach ($validas as $valida) {
            if ($valor === (string) $valida) {
                return $valor;
            }
        }
        return '';
    }

    /** Una fecha real en formato AAAA-MM-DD, o ''. El 2026-02-30 no existe y se descarta. */
    public static function fecha(mixed $valor): string
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return '';
        }
        [$a, $m, $d] = array_map('intval', explode('-', $valor));
        return checkdate($m, $d, $a) ? $valor : '';
    }

    /**
     * El patrón para un LIKE «contiene»: el texto con sus comodines escapados, entre %.
     * Quien busca «50%» o «a_b» busca esos caracteres, no un comodín. Va junto con
     * ESCAPE '|' en la consulta. Devuelve '' si no hay nada que buscar.
     */
    public static function patronLike(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $e = self::ESCAPE_LIKE;
        return '%' . str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $texto) . '%';
    }

    /**
     * Arma los filtros de un listado a partir de la dirección. Devuelve SIEMPRE todas las
     * claves del esquema (con '' los que no vinieron o no valen), así las vistas no preguntan
     * si existen.
     *
     * Cada entrada del esquema es el tipo del filtro:
     *   'texto'            un texto de búsqueda
     *   'fecha'            una fecha AAAA-MM-DD
     *   ['a', 'b', …]      una lista de valores permitidos
     *
     * @param array<string, mixed>                    $query   normalmente $_GET
     * @param array<string, string|array<int,mixed>>  $esquema
     * @return array<string, string>
     */
    public static function desdeQuery(array $query, array $esquema): array
    {
        $filtros = [];
        foreach ($esquema as $nombre => $tipo) {
            $valor = $query[$nombre] ?? null;
            $filtros[$nombre] = match (true) {
                $tipo === 'texto' => self::texto($valor),
                $tipo === 'fecha' => self::fecha($valor),
                is_array($tipo)   => self::opcion($valor, $tipo),
                default           => throw new InvalidArgumentException("Filtro «{$nombre}»: tipo desconocido."),
            };
        }
        return $filtros;
    }
}
