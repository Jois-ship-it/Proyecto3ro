<?php
declare(strict_types=1);

/**
 * Test del orden de apilado del menú de acciones del bracket.
 *
 * El síntoma era visual: al abrir el menú de una tarjeta del bracket, el
 * contenido de otras tarjetas se veía atravesándolo. Parecía transparencia del
 * panel, pero el panel es opaco (`background: #1b1f24`). Era apilado.
 *
 * `.bk-card` usa `position: absolute` junto con `z-index`, y esa combinación
 * crea un CONTEXTO DE APILADO. El `z-index` del menú que vive adentro solo
 * ordena contra sus hermanos de esa tarjeta: no puede competir con las demás
 * tarjetas. Con todas en `z-index: 2`, las que vienen después en el DOM se
 * dibujan encima del menú abierto, por alto que sea su z-index.
 *
 * Por eso el arreglo levanta la TARJETA, no el menú. Este test fija ese
 * razonamiento en números, para que no se «simplifique» de vuelta subiéndole el
 * z-index al menú —que es lo intuitivo y no funciona—.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/bracket_apilado_test.php
 */

require __DIR__ . '/bootstrap.php';

final class BracketApiladoTest extends TestCase
{
    private string $css = '';

    protected function setUp(): void
    {
        if ($this->css !== '') return;
        $ruta = dirname(__DIR__) . '/public/assets/css/styles.css';
        $contenido = file_get_contents($ruta);
        if ($contenido === false) $this->fallar("No se pudo leer {$ruta}.");
        $this->css = $contenido;
    }

    /** El cuerpo de la primera regla cuyo selector coincide exactamente. */
    private function regla(string $selector): string
    {
        $patron = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        if (preg_match($patron, $this->css, $m) !== 1) {
            $this->fallar("No existe la regla CSS «{$selector}».");
        }
        return $m[1];
    }

    /** El z-index declarado en un selector. */
    private function zIndex(string $selector): int
    {
        $cuerpo = $this->regla($selector);
        if (preg_match('/z-index\s*:\s*(-?\d+)/', $cuerpo, $m) !== 1) {
            $this->fallar("La regla «{$selector}» no declara z-index.");
        }
        return (int) $m[1];
    }

    // ─── El escenario que hace falta arreglar ───────────────────────────────

    public function test_la_tarjeta_del_bracket_crea_un_contexto_de_apilado(): void
    {
        // Es la premisa de todo lo demás. Si algún día .bk-card dejara de ser
        // position:absolute + z-index, el menú podría ordenarse solo y esta
        // regla sobraría. Mientras siga así, no.
        $cuerpo = $this->regla('.bk-card');

        $this->assertStringContainsString('position: absolute', $cuerpo);
        $this->assertGreaterThan(0, $this->zIndex('.bk-card'),
            'con z-index sobre un elemento posicionado, .bk-card atrapa el apilado de lo que tenga adentro');
    }

    public function test_la_tarjeta_con_el_menu_abierto_se_levanta(): void
    {
        $abierta = $this->zIndex('.bk-card[aria-expanded="true"]');
        $normal  = $this->zIndex('.bk-card');

        $this->assertGreaterThan($normal, $abierta,
            'la tarjeta con el menú abierto tiene que quedar sobre las demás, '
            . 'o las que vienen después en el DOM le dibujan encima');
    }

    public function test_el_menu_abierto_queda_sobre_las_barras_fijas(): void
    {
        // El menú se abre hacia arriba cuando no entra abajo, y ahí puede llegar
        // al borde superior de la ventana: justo donde vive la barra fija.
        $abierta = $this->zIndex('.bk-card[aria-expanded="true"]');

        foreach (['.topbar', '.public-nav'] as $barra) {
            $this->assertGreaterThan($this->zIndex($barra), $abierta,
                "el menú abierto quedaría debajo de {$barra}");
        }
    }

    public function test_el_menu_abierto_queda_debajo_de_los_modales(): void
    {
        // Los botones del menú abren un modal. Si la tarjeta quedara por encima,
        // el menú taparía el diálogo que él mismo abrió.
        $abierta = $this->zIndex('.bk-card[aria-expanded="true"]');

        // Los modales declaran su z-index inline en las vistas de gestión.
        $vista = (string) file_get_contents(dirname(__DIR__) . '/app/views/admin/torneo_gestion.php');
        preg_match_all('/z-index:\s*(\d+)/', $vista, $m);
        $this->assertGreaterThan(0, count($m[1]), 'no se encontró ningún modal con z-index en la vista');

        foreach (array_map('intval', $m[1]) as $zModal) {
            $this->assertGreaterThan($abierta, $zModal,
                'un modal quedaría por debajo de la tarjeta con el menú abierto');
        }
    }

    // ─── Y el arreglo sigue siendo alcanzable ───────────────────────────────

    public function test_el_javascript_marca_la_tarjeta_al_abrir_y_al_cerrar(): void
    {
        // La regla CSS depende de aria-expanded. Si el script dejara de
        // mantenerlo, el arreglo se apagaría en silencio: el menú volvería a
        // quedar tapado y el CSS seguiría ahí, aparentemente correcto.
        $js = (string) file_get_contents(dirname(__DIR__) . '/app/views/partials/eliminacion_bracket.php');

        $this->assertStringContainsString("aria-expanded','true'", $js,
            'al abrir el menú hay que marcar la tarjeta');
        $this->assertStringContainsString("aria-expanded','false'", $js,
            'al cerrarlo hay que desmarcarla, o la tarjeta queda levantada para siempre');
    }

    public function test_el_panel_del_menu_es_opaco(): void
    {
        // Lo que el síntoma parecía y no era. Se comprueba igual: si alguien le
        // pusiera un color con alfa, volvería el mismo aspecto por otra causa.
        $cuerpo = $this->regla('.bk-action-menu');

        if (preg_match('/background\s*:\s*([^;]+);/', $cuerpo, $m) !== 1) {
            $this->fallar('.bk-action-menu no declara background: el panel se vería a través.');
        }
        $fondo = trim($m[1]);

        $this->assertStringNotContainsString('rgba', $fondo,
            "el panel del menú tiene que ser opaco, y es «{$fondo}»");
        $this->assertStringNotContainsString('transparent', $fondo,
            "el panel del menú tiene que ser opaco, y es «{$fondo}»");
    }

    public function test_ningun_ancestro_del_menu_usa_opacity(): void
    {
        // `opacity` en un ancestro vuelve translúcido a TODO lo que tenga
        // adentro, y no hay background del hijo que lo corrija. Sería la única
        // forma de que el síntoma fuera realmente transparencia.
        $conOpacity = [];
        foreach (['.bk', '.bk-card', '.bk-action-menu', '.bk-action-inner'] as $sel) {
            $patron = '/(?:^|\})\s*' . preg_quote($sel, '/') . '\s*\{([^}]*)\}/m';
            if (preg_match($patron, $this->css, $m) !== 1) continue;
            if (preg_match('/(?<!-)\bopacity\s*:\s*0?\.\d+/', $m[1]) === 1) {
                $conOpacity[] = $sel;
            }
        }

        $this->assertCount(0, $conOpacity,
            'vuelven translúcido todo el menú: ' . implode(', ', $conOpacity));
    }
}

exit(TestCase::ejecutar(BracketApiladoTest::class));
