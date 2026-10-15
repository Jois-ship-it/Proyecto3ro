<?php
declare(strict_types=1);

/**
 * Test de la exportación a CSV (core/Csv.php).
 *
 * Qué pasaba antes: el sistema no exportaba nada (la letra lo pide como opcional, §14). Ahora el
 * contenido sale de lo que escribieron los usuarios —nombres de participantes y equipos— y va a
 * parar a una planilla, así que además de «que abra bien en Excel» hay una cuestión de seguridad:
 * una celda que empieza con = + - @ la planilla la ejecuta como FÓRMULA (CSV injection, OWASP A03).
 *
 * Qué se comprueba:
 *   - Formato: separador «;», BOM, decimales con coma, comillas y saltos de línea dentro de una celda.
 *   - Que ninguna celda de texto pueda llegar a la planilla como una fórmula.
 *   - Que el nombre del archivo nunca lleve texto de usuarios.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/csv_test.php
 */

require __DIR__ . '/bootstrap.php';

class CsvTest extends TestCase
{
    public function test_los_valores_simples(): void
    {
        $this->assertSame('', Csv::celda(null));
        $this->assertSame('1', Csv::celda(true));
        $this->assertSame('0', Csv::celda(false));
        $this->assertSame('42', Csv::celda(42));
        $this->assertSame('-7', Csv::celda(-7), 'un entero negativo es un número, no una fórmula');
        $this->assertSame('Ana Pérez', Csv::celda('Ana Pérez'));
        $this->assertSame('', Csv::celda(''));
    }

    public function test_los_decimales_llevan_coma_y_no_arrastran_ceros(): void
    {
        $this->assertSame('2,5', Csv::celda(2.5));
        $this->assertSame('3', Csv::celda(3.0));
        $this->assertSame('0', Csv::celda(0.0));
        $this->assertSame('-1,5', Csv::celda(-1.5));
        $this->assertSame('0,33', Csv::celda(1 / 3), 'dos decimales como mucho');
        $this->assertSame('1234,5', Csv::celda(1234.5), 'sin separador de miles');
    }

    public function test_una_celda_con_separador_comillas_o_salto_va_entre_comillas(): void
    {
        $this->assertSame('"Torneo; de verano"', Csv::celda('Torneo; de verano'));
        $this->assertSame('"Dijo ""hola"""', Csv::celda('Dijo "hola"'), 'las comillas internas se duplican');
        $this->assertSame("\"linea1\nlinea2\"", Csv::celda("linea1\nlinea2"));
        $this->assertSame("\"a\rb\"", Csv::celda("a\rb"));
        $this->assertSame('Torneo, con coma', Csv::celda('Torneo, con coma'), 'la coma no separa: el separador es «;»');
    }

    public function test_ninguna_celda_de_texto_llega_a_la_planilla_como_formula(): void
    {
        foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1+1", "\r=1+1", '=HYPERLINK("http://x","clic")', '-', '+', '='] as $peligrosa) {
            $celda = Csv::celda($peligrosa);
            $dentro = str_starts_with($celda, '"') ? substr($celda, 1) : $celda;
            $this->assertTrue(str_starts_with($dentro, "'"), 'neutralizada: ' . var_export($peligrosa, true) . ' → ' . var_export($celda, true));
        }
        // Y la que además lleva «;» o comillas conserva el apóstrofo ADENTRO de las comillas.
        $this->assertSame('"\'=A1;B1"', Csv::celda('=A1;B1'));
    }

    public function test_un_texto_que_es_un_numero_con_signo_no_se_toca(): void
    {
        foreach (['-3', '+2', '-2,5', '+0,5', '10', '-0'] as $numero) {
            $this->assertSame($numero, Csv::celda($numero), "«{$numero}» es un número");
        }
    }

    public function test_lo_que_solo_contiene_un_signo_en_el_medio_no_se_toca(): void
    {
        $this->assertSame('a=b', Csv::celda('a=b'));
        $this->assertSame('Equipo - Norte', Csv::celda('Equipo - Norte'));
        $this->assertSame('x@y.com', Csv::celda('x@y.com'));
        $this->assertSame('1+1', Csv::celda('1+1'), 'no empieza con signo');
    }

    public function test_el_archivo_lleva_bom_encabezados_y_fin_de_linea_de_windows(): void
    {
        $csv = Csv::generar(['Nombre', 'Puntos'], [['Ana', 3], ['Luis; Jr.', 2.5]]);
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM de UTF-8: sin él Excel rompe las tildes');
        $this->assertSame("\xEF\xBB\xBFNombre;Puntos\r\nAna;3\r\n\"Luis; Jr.\";2,5\r\n", $csv);
    }

    public function test_sin_filas_queda_solo_el_encabezado(): void
    {
        $this->assertSame("\xEF\xBB\xBFA;B\r\n", Csv::generar(['A', 'B'], []));
    }

    public function test_acepta_cualquier_iterable_de_filas(): void
    {
        $filas = (function () { yield ['x', 1]; yield ['y', 2]; })();
        $this->assertSame("\xEF\xBB\xBFk;v\r\nx;1\r\ny;2\r\n", Csv::generar(['k', 'v'], $filas));
    }

    public function test_los_encabezados_tambien_se_escapan(): void
    {
        $this->assertSame("\xEF\xBB\xBF\"a;b\";'=c\r\n", Csv::generar(['a;b', '=c'], []));
    }

    public function test_el_nombre_del_archivo_es_ascii_y_lleva_la_fecha(): void
    {
        $ts = mktime(12, 0, 0, 10, 11, 2026);
        $this->assertSame('torneo-12-posiciones-20261011.csv', Csv::nombreArchivo('torneo-12-posiciones', $ts));
        $this->assertSame('torneos-20261011.csv', Csv::nombreArchivo('torneos', $ts));
        $this->assertSame('reporte-20261011.csv', Csv::nombreArchivo('', $ts), 'sin base, un nombre por defecto');
        $this->assertSame('reporte-20261011.csv', Csv::nombreArchivo('???', $ts));
    }

    public function test_el_nombre_del_archivo_no_puede_romper_la_cabecera(): void
    {
        // Si alguna vez se le pasara el nombre de un torneo: comillas, saltos de línea y rutas no sobreviven.
        $hostil = "Copa \"X\"\r\nSet-Cookie: a=b; ../../etc/passwd";
        $nombre = Csv::nombreArchivo($hostil, 0);
        $this->assertTrue((bool) preg_match('/^[a-z0-9-]+-\d{8}\.csv$/', $nombre), 'solo letras, números y guiones: ' . $nombre);
        $this->assertStringNotContainsString("\n", $nombre);
        $this->assertStringNotContainsString('"', $nombre);
        $this->assertStringNotContainsString('/', $nombre);
    }
}

TestCase::correrSiEsScript(CsvTest::class);
