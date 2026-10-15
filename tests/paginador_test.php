<?php
declare(strict_types=1);

/**
 * Test del paginador (core/Paginador.php): la cuenta de las páginas de un listado.
 *
 * Qué pasaba antes: solo la auditoría paginaba, y lo hacía con la cuenta metida en el controlador
 * (un botón por página, sin límite) y sin defensa ante un ?page= raro. Usuarios, participantes,
 * equipos y torneos traían todas las filas. Ahora hay una sola cuenta, probada acá sin tocar la base.
 *
 * Qué se comprueba:
 *   - Desde dónde se lee (OFFSET) y qué filas muestra cada página («Mostrando X–Y de N»).
 *   - Un ?page= inválido o fuera de rango se corrige, nunca falla.
 *   - Las páginas, juntas, cubren todas las filas sin repetir ni saltear ninguna.
 *   - Qué números de página se dibujan, y las direcciones que conservan los filtros.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/paginador_test.php
 */

require __DIR__ . '/bootstrap.php';

class PaginadorTest extends TestCase
{
    public function test_sin_resultados_hay_una_pagina_vacia(): void
    {
        $p = Paginador::de(0, 1, 15);
        $this->assertSame(1, $p->paginas);
        $this->assertSame(1, $p->pagina);
        $this->assertSame(0, $p->desde, 'no hay primera fila');
        $this->assertSame(0, $p->hasta);
        $this->assertSame(0, $p->desplazamiento);
        $this->assertFalse($p->hayAnterior());
        $this->assertFalse($p->hayPosterior());
    }

    public function test_cada_pagina_sabe_desde_donde_se_lee_y_que_filas_muestra(): void
    {
        $p = Paginador::de(31, 1, 15);
        $this->assertSame(3, $p->paginas, '31 filas a 15 por página son 3 páginas');
        $this->assertSame([0, 1, 15], [$p->desplazamiento, $p->desde, $p->hasta]);

        $p = Paginador::de(31, 2, 15);
        $this->assertSame([15, 16, 30], [$p->desplazamiento, $p->desde, $p->hasta]);

        $p = Paginador::de(31, 3, 15);
        $this->assertSame([30, 31, 31], [$p->desplazamiento, $p->desde, $p->hasta], 'la última página es la corta');
        $this->assertTrue($p->hayAnterior());
        $this->assertFalse($p->hayPosterior());
    }

    public function test_un_multiplo_exacto_no_agrega_una_pagina_vacia(): void
    {
        $p = Paginador::de(30, 2, 15);
        $this->assertSame(2, $p->paginas);
        $this->assertSame([16, 30], [$p->desde, $p->hasta]);
        $this->assertSame(1, Paginador::de(15, 1, 15)->paginas);
        $this->assertSame(2, Paginador::de(16, 1, 15)->paginas);
    }

    public function test_un_page_invalido_o_fuera_de_rango_se_corrige(): void
    {
        // Lo que escribe quien toca la dirección a mano, o un enlace viejo.
        foreach (['abc', '-3', '0', '', null, [], '2.5', '1e3', '0x10', '2abc', false, '99999999999999999999'] as $raro) {
            $this->assertSame(1, Paginador::de(100, $raro, 10)->pagina, 'cae en la primera: ' . var_export($raro, true));
        }
        $this->assertSame(10, Paginador::de(100, '999', 10)->pagina, 'más allá de la última se queda en la última');
        $this->assertSame(10, Paginador::de(100, 999999999, 10)->pagina);
        $this->assertSame(4, Paginador::de(100, '4', 10)->pagina, 'un número bien escrito en texto sirve');
        $this->assertSame(4, Paginador::de(100, 4, 10)->pagina);
        $this->assertSame(2, Paginador::de(100, ' 2 ', 10)->pagina, 'los espacios de los costados se toleran');
    }

    public function test_los_valores_absurdos_de_total_y_por_pagina_se_acotan(): void
    {
        $this->assertSame(0, Paginador::de(-5, 1, 15)->total, 'un total negativo es cero');
        $this->assertSame(1, Paginador::de(10, 1, 0)->porPagina, 'por página nunca es cero (dividiría por cero)');
        $this->assertSame(1, Paginador::de(10, 1, -4)->porPagina);
        $this->assertSame(Paginador::POR_PAGINA_MAXIMO, Paginador::de(10000, 1, 100000)->porPagina, 'ningún llamado pide la tabla entera');
    }

    public function test_las_paginas_juntas_cubren_todas_las_filas_sin_repetir(): void
    {
        foreach ([0, 1, 9, 10, 11, 29, 30, 31, 99, 100, 101] as $total) {
            foreach ([1, 7, 10, 15] as $porPagina) {
                $vistas = 0;
                $ultimaHasta = 0;
                $paginas = Paginador::de($total, 1, $porPagina)->paginas;
                for ($n = 1; $n <= $paginas; $n++) {
                    $p = Paginador::de($total, $n, $porPagina);
                    if ($total > 0) {
                        $this->assertSame($ultimaHasta + 1, $p->desde, "total $total / $porPagina: la página $n empieza donde terminó la anterior");
                        $vistas += $p->hasta - $p->desde + 1;
                        $ultimaHasta = $p->hasta;
                    }
                }
                $this->assertSame($total, $vistas, "total $total / $porPagina: ninguna fila se pierde ni se repite");
            }
        }
    }

    public function test_la_ventana_de_numeros(): void
    {
        $this->assertSame([1], Paginador::de(5, 1, 15)->ventana(), 'una sola página');
        $this->assertSame([1, 2, 3, 4, 5], Paginador::de(75, 3, 15)->ventana(), 'pocas páginas: todas');
        $this->assertSame([1, 2, null, 20], Paginador::de(300, 1, 15)->ventana(), 'al principio');
        $this->assertSame([1, null, 9, 10, 11, null, 20], Paginador::de(300, 10, 15)->ventana(), 'en el medio');
        $this->assertSame([1, null, 19, 20], Paginador::de(300, 20, 15)->ventana(), 'al final');
        // Un hueco de UNA página se rellena con su número: «…» ocuparía el mismo lugar.
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], Paginador::de(105, 4, 15)->ventana());
        $this->assertSame([1, 2, null, 7], Paginador::de(105, 1, 15)->ventana());
    }

    public function test_la_ventana_siempre_trae_primera_ultima_y_actual_sin_repetir(): void
    {
        for ($paginas = 1; $paginas <= 30; $paginas++) {
            for ($actual = 1; $actual <= $paginas; $actual++) {
                $p = Paginador::de($paginas * 10, $actual, 10);
                $v = array_values(array_filter($p->ventana(), fn($n) => $n !== null));
                $this->assertTrue(in_array(1, $v, true), "$actual/$paginas: la primera");
                $this->assertTrue(in_array($paginas, $v, true), "$actual/$paginas: la última");
                $this->assertTrue(in_array($actual, $v, true), "$actual/$paginas: la actual");
                $this->assertSame($v, array_values(array_unique($v)), "$actual/$paginas: sin repetidos");
                $ordenada = $v;
                sort($ordenada);
                $this->assertSame($ordenada, $v, "$actual/$paginas: en orden");
            }
        }
    }

    public function test_los_enlaces_conservan_los_filtros_y_omiten_lo_vacio(): void
    {
        $this->assertSame('/admin/torneos', Paginador::enlace('/admin/torneos', ['q' => '', 'tipo' => '', 'estado' => ''], 1));
        $this->assertSame('/admin/torneos?page=3', Paginador::enlace('/admin/torneos', ['q' => ''], 3));
        $this->assertSame('/torneos?q=liga&estado=en_curso', Paginador::enlace('/torneos', ['q' => 'liga', 'tipo' => '', 'estado' => 'en_curso'], 1), 'la página 1 no lleva ?page=');
        $this->assertSame('/torneos?q=liga&estado=en_curso&page=2', Paginador::enlace('/torneos', ['q' => 'liga', 'tipo' => '', 'estado' => 'en_curso'], 2));
        $this->assertSame('/x?q=a', Paginador::enlace('/x', ['q' => 'a', 'otro' => null], 1), 'un null tampoco viaja');
    }

    public function test_el_texto_del_filtro_viaja_codificado(): void
    {
        $url = Paginador::enlace('/torneos', ['q' => 'a&b=c d"<'], 2);
        $this->assertSame('/torneos?q=a%26b%3Dc+d%22%3C&page=2', $url);
        $this->assertStringNotContainsString('<', $url, 'nada de HTML crudo en la dirección');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $vuelta);
        $this->assertSame('a&b=c d"<', $vuelta['q'], 'y al leerla se recupera tal cual');
    }
}

TestCase::correrSiEsScript(PaginadorTest::class);
