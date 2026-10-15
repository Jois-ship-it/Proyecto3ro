<?php
declare(strict_types=1);

/**
 * Archivos CSV para abrir en una planilla (Excel, LibreOffice).
 *
 * Tres decisiones, todas pensadas para quien lo abre con doble clic en un Windows en español:
 *   - El separador es «;». Excel en español no parte las columnas por coma (usa la coma como
 *     separador decimal), y con «;» abre bien en cualquier configuración regional.
 *   - El archivo empieza con la marca BOM de UTF-8, o Excel lee las tildes y las ñ como basura.
 *   - Los números decimales salen con coma («2,5») por la misma razón.
 *
 * Y una de seguridad: una celda de texto que empiece con =, +, - o @ la interpreta la planilla
 * como una FÓRMULA. Como el contenido sale de lo que escribieron usuarios (nombres de
 * participantes y equipos), alguien podría anotarse como «=HYPERLINK(...)» y ejecutarlo en la
 * computadora de quien exporte el reporte (CSV injection, OWASP A03). Por eso a esas celdas se
 * les antepone un apóstrofo, que la planilla muestra como texto.
 */
final class Csv
{
    public const SEPARADOR = ';';
    public const BOM       = "\xEF\xBB\xBF";
    public const FIN_LINEA = "\r\n";

    /** Una celda lista para escribir: formateada y, si hace falta, entre comillas. */
    public static function celda(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            return self::numero($valor);
        }

        $texto = (string) $valor;
        // Lo que pidió la planilla como fórmula pasa a ser texto. Un número escrito con signo
        // («-3», «+2,5») no es una fórmula y se deja como está.
        if (preg_match('/^[=+\-@\t\r]/', $texto) && !preg_match('/^[+-]?\d+([.,]\d+)?$/', $texto)) {
            $texto = "'" . $texto;
        }

        if (strpbrk($texto, self::SEPARADOR . "\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $texto) . '"';
        }
        return $texto;
    }

    /** Un decimal con coma y sin ceros de más: 2.5 → «2,5», 3.0 → «3». */
    private static function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    /**
     * El archivo completo como texto: BOM, encabezados y una línea por fila.
     *
     * @param string[]                    $encabezados
     * @param iterable<array<int,mixed>>  $filas
     */
    public static function generar(array $encabezados, iterable $filas): string
    {
        $salida = self::BOM . self::linea($encabezados);
        foreach ($filas as $fila) {
            $salida .= self::linea($fila);
        }
        return $salida;
    }

    /** @param array<int,mixed> $celdas */
    private static function linea(array $celdas): string
    {
        return implode(self::SEPARADOR, array_map([self::class, 'celda'], $celdas)) . self::FIN_LINEA;
    }

    /**
     * El nombre del archivo a descargar: solo letras ASCII, números y guiones, y la fecha de
     * hoy. Nunca lleva texto de usuarios (el nombre de un torneo, por ejemplo): un nombre con
     * comillas o saltos de línea rompería la cabecera Content-Disposition.
     */
    public static function nombreArchivo(string $base, ?int $timestamp = null): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $base) ?? '');
        $base = trim($base, '-');
        return ($base !== '' ? $base : 'reporte') . '-' . date('Ymd', $timestamp ?? time()) . '.csv';
    }
}
