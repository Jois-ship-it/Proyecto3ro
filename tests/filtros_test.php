<?php
declare(strict_types=1);

/**
 * Test de los filtros de los listados (core/Filtros.php): lo que se acepta de la dirección.
 *
 * Qué pasaba antes: la lista pública leía ?tipo= y ?estado= y comparaba en PHP; no había búsqueda
 * por nombre y ningún valor se acotaba. Ahora todo listado pasa por acá, y la regla es una sola:
 * un valor que no corresponde se DESCARTA (nunca da un error), y el texto llega acotado y con sus
 * comodines de LIKE escapados.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/filtros_test.php
 */

require __DIR__ . '/bootstrap.php';

class FiltrosTest extends TestCase
{
    public function test_el_texto_se_limpia_y_se_acota(): void
    {
        $this->assertSame('copa verano', Filtros::texto("  copa   verano \t"));
        $this->assertSame('a b c', Filtros::texto("a\nb\r\nc"), 'los saltos de línea son espacios');
        $this->assertSame('a b', Filtros::texto("a\x00b\x07"), 'los caracteres de control se vuelven espacios');
        $this->assertSame('', Filtros::texto("   \t\n "));
        $this->assertSame('liga', Filtros::texto('liga'));
        $this->assertSame('Ñandú Ávila', Filtros::texto('Ñandú Ávila'), 'las tildes y la ñ no se tocan');
    }

    public function test_el_largo_se_cuenta_en_caracteres_no_en_bytes(): void
    {
        $this->assertSame(Filtros::LARGO_BUSQUEDA, mb_strlen(Filtros::texto(str_repeat('x', 500))));
        $largo = Filtros::texto(str_repeat('ñ', 500));
        $this->assertSame(Filtros::LARGO_BUSQUEDA, mb_strlen($largo), 'una ñ cuenta uno');
        $this->assertTrue(mb_check_encoding($largo, 'UTF-8'), 'y nunca se corta a mitad de un carácter');
        $this->assertSame('abc', Filtros::texto('abcdef', 3), 'el máximo se puede pasar');
    }

    public function test_lo_que_no_es_un_texto_queda_vacio(): void
    {
        // ?q[]=x llega como arreglo; si se lo tratara como texto, PHP avisaría o fallaría.
        foreach ([['x'], null, 5, 1.5, true, new stdClass()] as $raro) {
            $this->assertSame('', Filtros::texto($raro), 'descartado: ' . gettype($raro));
        }
        $this->assertSame('', Filtros::texto("\xFF\xFE rotos"), 'un texto que no es UTF-8 se descarta');
    }

    public function test_una_opcion_es_una_de_las_permitidas_o_nada(): void
    {
        $validas = ['liga', 'suizo'];
        $this->assertSame('liga', Filtros::opcion('liga', $validas));
        $this->assertSame('', Filtros::opcion('LIGA', $validas), 'es exacta: no hay mayúsculas que se perdonen');
        $this->assertSame('', Filtros::opcion('liga ', $validas));
        $this->assertSame('', Filtros::opcion('inventado', $validas));
        $this->assertSame('', Filtros::opcion('', $validas));
        $this->assertSame('', Filtros::opcion(['liga'], $validas), 'un arreglo no es una opción');
        $this->assertSame('', Filtros::opcion(null, $validas));
        $this->assertSame('', Filtros::opcion('liga', []), 'sin opciones permitidas no hay nada que aceptar');
    }

    public function test_las_opciones_numericas_se_comparan_como_texto(): void
    {
        $this->assertSame('2', Filtros::opcion('2', [1, 2, 3]));
        $this->assertSame('', Filtros::opcion('2.0', [1, 2, 3]));
        $this->assertSame('', Filtros::opcion('02', [1, 2, 3]));
        $this->assertSame('', Filtros::opcion('0', ['a', 'b']), 'el «0» no es igual a un texto cualquiera (como pasaría con ==)');
    }

    public function test_una_fecha_tiene_que_existir(): void
    {
        $this->assertSame('2026-10-11', Filtros::fecha('2026-10-11'));
        $this->assertSame('2028-02-29', Filtros::fecha('2028-02-29'), 'año bisiesto');
        foreach (['2026-02-30', '2027-02-29', '2026-13-01', '2026-00-10', '2026-1-5', '11/10/2026', 'hoy', '', '2026-10-11 10:00', ['2026-10-11'], null] as $mala) {
            $this->assertSame('', Filtros::fecha($mala), 'descartada: ' . var_export($mala, true));
        }
    }

    public function test_los_comodines_del_like_se_escapan(): void
    {
        $this->assertSame('', Filtros::patronLike(''), 'sin texto no hay patrón');
        $this->assertSame('%liga%', Filtros::patronLike('liga'));
        $this->assertSame('%50|%%', Filtros::patronLike('50%'), 'el % se busca como carácter');
        $this->assertSame('%a|_b%', Filtros::patronLike('a_b'), 'el _ también');
        $this->assertSame('%a||b%', Filtros::patronLike('a|b'), 'y el propio carácter de escape');
        $this->assertSame('%|%|_||%', Filtros::patronLike('%_|'), 'todos juntos');
    }

    public function test_desde_la_direccion_siempre_vuelven_todas_las_claves(): void
    {
        $esquema = ['q' => 'texto', 'tipo' => ['liga', 'suizo'], 'desde' => 'fecha'];
        $this->assertSame(['q' => '', 'tipo' => '', 'desde' => ''], Filtros::desdeQuery([], $esquema), 'la vista nunca pregunta si existen');

        $f = Filtros::desdeQuery(['q' => '  copa ', 'tipo' => 'suizo', 'desde' => '2026-10-01', 'otro' => 'x', 'page' => '3'], $esquema);
        $this->assertSame(['q' => 'copa', 'tipo' => 'suizo', 'desde' => '2026-10-01'], $f, 'lo que no está en el esquema (page, otro) no pasa');
    }

    public function test_desde_la_direccion_lo_malo_se_descarta_sin_romper(): void
    {
        $esquema = ['q' => 'texto', 'tipo' => ['liga'], 'desde' => 'fecha'];
        $f = Filtros::desdeQuery(['q' => ['a', 'b'], 'tipo' => ['liga'], 'desde' => '2026-02-30'], $esquema);
        $this->assertSame(['q' => '', 'tipo' => '', 'desde' => ''], $f);
    }

    public function test_un_tipo_de_filtro_desconocido_es_un_error_del_programador(): void
    {
        $this->assertThrows(fn() => Filtros::desdeQuery(['q' => 'x'], ['q' => 'numero']), 'tipo desconocido');
    }
}

TestCase::correrSiEsScript(FiltrosTest::class);
