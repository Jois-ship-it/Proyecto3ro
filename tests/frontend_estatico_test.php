<?php
declare(strict_types=1);

/**
 * Test del frontend sin código ni estilos en línea, y de la política de seguridad de contenido (CSP).
 *
 * Qué pasaba antes: las vistas llevaban 287 atributos `style="…"`, 7 bloques `<script>` y 25 manejadores
 * `onclick/onsubmit="…"`, y la política de Apache tenía `'unsafe-inline'` en scripts y en estilos: el navegador
 * ejecutaba cualquier script o atributo que apareciera en la página. Tres de esos manejadores llevaban texto
 * de una persona DENTRO de un literal de JavaScript (`confirm('¿Aprobar a <nombre>?')`): un nombre registrado
 * como `x');alert(document.cookie);//` se ejecutaba en el navegador del administrador al aprobar la solicitud.
 *
 * Qué se comprueba:
 *   - Ninguna vista (ni core/) lleva `style="…"`, `<script>` ejecutable ni manejadores `on…="…"`. Este test
 *     es la regla: una vista nueva que los use lo hace fallar.
 *   - La política: sin `'unsafe-inline'` ni `'unsafe-eval'`, con un nonce distinto por pedido, y Apache ya no
 *     fija una (dos políticas se intersectan y el nonce dejaría de valer).
 *   - View::claseDinamica(): solo acepta números; nada que cierre la regla o la etiqueta `<style>`.
 *   - Los avatares y las barras no llevan estilo en línea.
 *   - El mismo nombre malicioso de arriba ya no llega a ningún manejador: sale escapado en `data-confirmar`.
 *   - Toda clase que usa una vista tiene regla en las hojas, las hojas y los scripts citados existen, y las
 *     hojas siguen siendo Mobile First (sin `@media (max-width…)`).
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/frontend_estatico_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

final class RedirigidoA extends Exception {}

final class AdminObservable extends AdminController
{
    public static string $html = '';

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        ob_start();
        try {
            parent::render($view, $data, $layout);
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never { throw new RedirigidoA($url); }
}

final class TorneoObservable extends TorneoController
{
    public static string $html = '';

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        ob_start();
        try {
            parent::render($view, $data, $layout);
        } finally {
            self::$html = (string) ob_get_clean();
        }
    }

    protected function redirect(string $url): never { throw new RedirigidoA($url); }
}

class FrontendEstaticoTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Csp::olvidar();
        View::olvidarEstilosDinamicos();
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
        Csp::olvidar();
        View::olvidarEstilosDinamicos();
    }

    // ── apoyo ──────────────────────────────────────────────────────────

    /** @return array<string,string> ruta relativa => contenido, de cada .php bajo $dir */
    private function archivos(string $dir, string $ext = 'php'): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === $ext) {
                $out[str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1))] = (string) file_get_contents($f->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    /** El HTML de una vista, sin los bloques PHP ni los comentarios HTML. */
    private function soloHtml(string $codigo): string
    {
        $codigo = preg_replace('/<\?.*?\?>/s', ' ', $codigo) ?? $codigo;
        return preg_replace('/<!--.*?-->/s', ' ', $codigo) ?? $codigo;
    }

    // ── la regla ───────────────────────────────────────────────────────

    public function test_ninguna_vista_lleva_atributos_style(): void
    {
        $vistas = $this->archivos(APP_PATH . '/views');
        $this->assertGreaterThan(40, count($vistas), 'se están revisando las vistas de verdad');
        $mal = [];
        foreach ($vistas as $ruta => $codigo) {
            // También dentro de bloques PHP: una cadena como 'style="…"' que arma una etiqueta.
            if (preg_match_all('/\sstyle\s*=\s*["\']/i', $codigo, $m)) {
                $mal[] = "{$ruta} (" . count($m[0]) . ')';
            }
        }
        $this->assertSame([], $mal, "el CSP no admite style=\"…\":\n  " . implode("\n  ", $mal));
    }

    public function test_ninguna_vista_lleva_scripts_ni_manejadores_en_linea(): void
    {
        $mal = [];
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            $html = $this->soloHtml($codigo);
            // <script> sin src que no sea un bloque de datos (type="application/json")
            preg_match_all('/<script\b([^>]*)>/i', $html, $m);
            foreach ($m[1] as $atributos) {
                $esDatos = (bool) preg_match('/type\s*=\s*["\']application\/(?:ld\+)?json["\']/i', $atributos);
                $tieneSrc = (bool) preg_match('/\ssrc\s*=/i', ' ' . $atributos);
                if (!$tieneSrc && !$esDatos) $mal[] = "{$ruta}: <script> en línea";
            }
            if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $html, $h)) $mal[] = "{$ruta}: manejador {$h[0]}";
            // y los que arma PHP (echo '<button onclick=…')
            if (preg_match('/[\'"]\s*[^\'"]*\son(?:click|submit|change|load|error|input|focus|blur)\s*=/i', $codigo)) $mal[] = "{$ruta}: manejador armado por PHP";
        }
        $this->assertSame([], $mal, "el CSP no admite código en línea:\n  " . implode("\n  ", $mal));
    }

    public function test_los_bloques_style_son_solo_los_que_llevan_nonce(): void
    {
        $mal = [];
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            if ($ruta === 'partials/estilos.php') continue;          // el único que lo imprime (con nonce): se comprueba abajo
            if (preg_match_all('/<style\b([^>]*)>/i', $this->soloHtml($codigo), $m)) $mal[] = "{$ruta}: <style> escrito en la vista";
        }
        $this->assertSame([], $mal, implode("\n", $mal));

        $parcial = (string) file_get_contents(APP_PATH . '/views/partials/estilos.php');
        $this->assertStringContainsString('<style nonce="<?= View::e(Csp::nonce()) ?>">', $parcial, 'el bloque dinámico lleva el nonce del pedido');
    }

    public function test_el_codigo_de_core_no_arma_atributos_style(): void
    {
        foreach (['View.php', 'ManejadorErrores.php', 'BaseController.php', 'Respuesta.php'] as $archivo) {
            $codigo = (string) file_get_contents(BASE_PATH . '/core/' . $archivo);
            $codigo = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $codigo) ?? $codigo;      // sin comentarios
            $this->assertFalse((bool) preg_match('/\sstyle\s*=\s*["\']/i', $codigo), "core/{$archivo} arma un style=\"…\"");
        }
    }

    // ── la política ────────────────────────────────────────────────────

    public function test_la_politica_no_admite_codigo_en_linea(): void
    {
        $p = Csp::politica();

        $this->assertStringNotContainsString("'unsafe-inline'", $p);
        $this->assertStringNotContainsString("'unsafe-eval'", $p);
        $this->assertStringContainsString("script-src 'self';", $p, 'solo scripts de este sitio');
        $this->assertTrue((bool) preg_match("/style-src 'self' 'nonce-[A-Za-z0-9_-]{22}';/", $p), 'estilos: de este sitio y el bloque con nonce');
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'", "img-src 'self' data:"] as $directiva) {
            $this->assertStringContainsString($directiva, $p, "se mantiene {$directiva}");
        }
    }

    public function test_el_nonce_cambia_en_cada_pedido_y_es_impredecible(): void
    {
        $a = Csp::nonce();
        $this->assertSame($a, Csp::nonce(), 'dentro de un pedido es siempre el mismo (cabecera y <style>)');
        Csp::olvidar();
        $b = Csp::nonce();
        $this->assertNotSame($a, $b, 'otro pedido, otro nonce');
        $this->assertTrue((bool) preg_match('/^[A-Za-z0-9_-]{22}$/', $a), '16 bytes al azar en base64 seguro para URL');
        $conjunto = [];
        for ($i = 0; $i < 50; $i++) { Csp::olvidar(); $conjunto[Csp::nonce()] = true; }
        $this->assertSame(50, count($conjunto), 'no se repiten');
    }

    public function test_apache_ya_no_fija_una_politica(): void
    {
        $activas = array_filter(
            array_map('trim', file(BASE_PATH . '/public/.htaccess') ?: []),
            fn(string $l) => $l !== '' && $l[0] !== '#'
        );
        $conPolitica = array_filter($activas, fn(string $l) => stripos($l, 'Content-Security-Policy') !== false);
        $this->assertSame([], array_values($conPolitica), 'dos políticas se intersectan: con la de Apache, el nonce nunca valdría');
        $this->assertTrue((bool) preg_match('/^Csp::enviar\(\);/m', (string) file_get_contents(BASE_PATH . '/public/index.php')), 'la manda PHP, al empezar el pedido (y no está comentada)');
    }

    public function test_la_pagina_minima_de_error_cumple_la_politica(): void
    {
        $html = ManejadorErrores::paginaMinima(['titulo' => 'Error <x>', 'mensaje' => 'm', 'estado' => 500, 'id' => 'ab12cd34']);

        $this->assertFalse((bool) preg_match('/\sstyle\s*=/i', $html), 'sin style="…"');
        $this->assertStringContainsString('<style nonce="' . Csp::nonce() . '">', $html, 'sus estilos van en un bloque con nonce');
        $this->assertStringContainsString('Error &lt;x&gt;', $html, 'y sigue escapando');
        $this->assertFalse((bool) preg_match('/<script/i', $html));
    }

    // ── estilos dinámicos ──────────────────────────────────────────────

    public function test_una_clase_dinamica_solo_acepta_numeros(): void
    {
        $c = View::claseDinamica('left:12.500rem;top:3rem;width:100%;height:2px');
        $this->assertTrue((bool) preg_match('/^d[0-9a-f]{10}$/', $c), $c);
        $this->assertSame($c, View::claseDinamica('left:12.500rem;top:3rem;width:100%;height:2px'), 'la misma declaración, la misma clase');
        $this->assertNotSame($c, View::claseDinamica('left:1rem'));
        View::claseDinamica('top:-.25rem');
        View::claseDinamica('width:0');

        foreach (['}</style><script>alert(1)</script>', 'left:1rem;}body{display:none', 'color:red', 'background:url(x)', 'left:1rem;color:red',
                  'left:calc(1rem+2px)', "left:1rem\n", 'left:', '', 'LEFT:1rem', 'left:1em', 'left:1rem !important', 'left:1rem;;'] as $malo) {
            $this->assertThrows(fn() => View::claseDinamica($malo), 'Estilo dinámico no permitido', 'se rechaza: ' . json_encode($malo));
        }
    }

    public function test_los_estilos_dinamicos_se_imprimen_una_vez_y_sin_repetir(): void
    {
        $a = View::claseDinamica('left:1rem;top:2rem');
        View::claseDinamica('left:1rem;top:2rem');
        $b = View::claseDinamica('width:50%');

        $css = View::estilosDinamicos();

        $this->assertSame(".{$a}{left:1rem;top:2rem}\n.{$b}{width:50%}\n", $css);
        View::olvidarEstilosDinamicos();
        $this->assertSame('', View::estilosDinamicos());
    }

    public function test_el_layout_imprime_el_bloque_con_nonce_y_la_pagina_lo_usa(): void
    {
        $html = $this->conLayout(fn() => print View::avatar('Ana Pérez', null, 56));

        $this->assertTrue((bool) preg_match('/<style nonce="' . preg_quote(Csp::nonce(), '/') . '">\.d[0-9a-f]{10}\{width:56px;height:56px;font-size:21\.3px\}\s*<\/style>/', $html), 'el bloque con el tamaño del avatar');
        $this->assertTrue((bool) preg_match('/<span class="avatar avatar-ini d[0-9a-f]{10}">AP<\/span>/', $html), 'y el avatar lleva su clase');
        $this->assertSame(0, preg_match_all('/\sstyle\s*=/i', $html), 'ningún style="…" en toda la página');
    }

    public function test_una_pagina_sin_estilos_dinamicos_no_imprime_el_bloque(): void
    {
        $html = $this->conLayout(fn() => print '<p>hola</p>');

        $this->assertStringNotContainsString('<style', $html);
    }

    /** Renderiza algo dentro del layout público (lo que hace un controlador). */
    private function conLayout(callable $contenido): string
    {
        $vista = 'shared/prueba_frontend';
        $archivo = APP_PATH . '/views/' . $vista . '.php';
        file_put_contents($archivo, "<?php (\$f)(); ?>");
        try {
            ob_start();
            View::render($vista, ['f' => $contenido, '_sitio' => ['nombre' => 'FlexArena', 'contacto' => '', 'perfiles_publicos' => true]], 'public');
            return (string) ob_get_clean();
        } finally {
            @unlink($archivo);
        }
    }

    public function test_los_avatares_no_llevan_estilo_en_linea(): void
    {
        $con = View::avatar('Ana "Pérez"', '/assets/uploads/avatars/x.png', 64);
        $sin = View::avatar('<b>Ana</b>', null, 32);

        $this->assertFalse((bool) preg_match('/\sstyle\s*=/i', $con . $sin));
        $this->assertTrue((bool) preg_match('/<img src="\/assets\/uploads\/avatars\/x\.png" alt="Ana &quot;Pérez&quot;" class="avatar avatar-img d[0-9a-f]{10}">/', $con), $con);
        $this->assertStringContainsString('>&lt;</span>', $sin, 'las iniciales salen escapadas');
        $this->assertSame(0, preg_match('/<b>/', $sin));
    }

    // ── el defecto de seguridad que lo motivó ──────────────────────────

    public function test_un_nombre_malicioso_no_llega_a_un_manejador_de_javascript(): void
    {
        $nombre = "x');alert(document.cookie);//";
        $uid = Fixtures::usuario('Pendiente Malo', 'pendiente.malo@test.local', 'Clave-Segura-9876!', 3);
        $this->db->exec("UPDATE usuarios SET estado = 'pendiente' WHERE id = {$uid}");
        $pid = (new ParticipanteModel())->insert(['usuario_id' => $uid, 'nombre' => $nombre, 'estado' => 'pendiente']);
        $this->db->prepare('UPDATE usuarios SET nombre = ? WHERE id = ?')->execute([$nombre, $uid]);
        Fixtures::loguearComo(Fixtures::ADMIN);

        (new AdminObservable())->registros();
        $html = AdminObservable::$html;

        $this->assertStringContainsString('data-confirmar="¿Aprobar a x&apos;);alert(document.cookie);//?', $html, 'el nombre sale escapado dentro de un atributo de datos');
        $this->assertFalse((bool) preg_match('/\sonsubmit\s*=/i', $html), 'ya no hay ningún onsubmit');
        $this->assertSame(0, preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $html), 'ni otro manejador en la página');
        $this->assertSame(0, preg_match_all('/\sstyle\s*=/i', $html), 'ni un style="…"');
    }

    public function test_los_formularios_que_confirman_llevan_su_mensaje_en_data_confirmar(): void
    {
        $vistas = $this->archivos(APP_PATH . '/views');
        $cuenta = 0;
        foreach ($vistas as $ruta => $codigo) {
            $cuenta += preg_match_all('/\sdata-confirmar="/', $codigo);
        }
        $this->assertGreaterThan(13, $cuenta, 'las 14 confirmaciones de las pantallas siguen ahí (hay ' . $cuenta . ')');

        $gestion = $vistas['admin/torneo_gestion.php'];
        $this->assertStringContainsString('data-confirmar="¿Generar ronda <?= (int)$totalRondasJugadas + 1 ?>?"', $gestion, 'un valor calculado va en el atributo, no en un literal de JS');
    }

    // ── hojas y scripts ────────────────────────────────────────────────

    public function test_solo_los_campos_de_texto_largo_ocupan_todo_el_ancho_del_formulario(): void
    {
        // Antes era un style="grid-column:1/-1" que PHP agregaba solo a esos campos; al pasarlo a una clase se puede volver a
        // aplicar a todos por error (y la pantalla se descuadra sin que ningún otro test lo note).
        Fixtures::loguearComo(Fixtures::ADMIN);
        (new TorneoObservable())->formulario();

        preg_match_all('/<div class="field([^"]*)">\s*<label for="config_(\w+)"/', TorneoObservable::$html, $m, PREG_SET_ORDER);
        $claves = ConfiguracionTorneoService::CLAVES;
        $this->assertSame(count($claves), count($m), 'están todos los datos del evento');
        foreach ($m as [$_, $clases, $clave]) {
            $esLargo = $claves[$clave]['tipo'] === 'texto_largo';
            $this->assertSame($esLargo, str_contains($clases, 'span-all'), "{$clave}: " . ($esLargo ? 'un texto largo ocupa todo el ancho' : 'los demás campos van de a dos'));
        }
    }

    public function test_las_hojas_de_estilo_existen_y_se_cargan_en_orden(): void
    {
        $this->assertSame(['tokens', 'base', 'componentes', 'paginas', 'responsive', 'utilidades'], View::HOJAS);
        foreach (array_merge(View::HOJAS, ['impresion']) as $hoja) {
            $this->assertTrue(is_file(BASE_PATH . "/public/assets/css/{$hoja}.css"), "falta {$hoja}.css");
        }
        $this->assertFalse(is_file(BASE_PATH . '/public/assets/css/styles.css'), 'la hoja única ya no está');

        $parcial = (string) file_get_contents(APP_PATH . '/views/partials/estilos.php');
        $this->assertStringContainsString('View::HOJAS', $parcial);
        $this->assertStringContainsString('impresion.css', $parcial);
        $this->assertStringContainsString('media="print"', $parcial, 'la de impresión solo al imprimir');
        foreach (['layouts/admin.php', 'layouts/public.php', 'auth/login.php', 'auth/registro.php'] as $vista) {
            $this->assertStringContainsString("partials/estilos.php", (string) file_get_contents(APP_PATH . '/views/' . $vista), "{$vista} carga las hojas");
        }
    }

    public function test_los_assets_llevan_la_fecha_de_su_ultima_modificacion(): void
    {
        $url = View::asset('/assets/css/base.css');
        $this->assertTrue((bool) preg_match('~^/assets/css/base\.css\?v=\d{9,}$~', $url), $url);
        $this->assertSame(filemtime(BASE_PATH . '/public/assets/css/base.css'), (int) substr($url, strpos($url, '=') + 1));
        $this->assertSame('/assets/css/no_existe.css', View::asset('/assets/css/no_existe.css'), 'un archivo que no existe queda sin versión');
    }

    public function test_lo_que_lleva_version_se_guarda_un_ano_y_lo_demas_no(): void
    {
        $htaccess = (string) file_get_contents(BASE_PATH . '/public/.htaccess');

        $this->assertStringContainsString('<FilesMatch "\.(css|js)$">', $htaccess);
        $this->assertStringContainsString('<If "%{QUERY_STRING} =~ /^v=[0-9]+$/">', $htaccess, 'solo con ?v=<número>: otra consulta no se guarda un año');
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=31536000, immutable"', $htaccess);
        $this->assertStringContainsString('ExpiresByType text/css             "access plus 7 days"', $htaccess, 'y sin versión valen los 7 días de siempre');
    }

    public function test_las_hojas_siguen_siendo_mobile_first(): void
    {
        foreach ($this->archivos(BASE_PATH . '/public/assets/css', 'css') as $archivo => $css) {
            $this->assertFalse((bool) preg_match('/@media\s*\(\s*max-width/i', $css), "{$archivo} tiene un @media (max-width…): Mobile First solo agrega con min-width");
        }
        $responsive = (string) file_get_contents(BASE_PATH . '/public/assets/css/responsive.css');
        $this->assertStringContainsString('@media (min-width: 721px)', $responsive);
        $this->assertStringContainsString('@media (min-width: 981px)', $responsive);
        $base = (string) file_get_contents(BASE_PATH . '/public/assets/css/base.css');
        $this->assertStringContainsString('[hidden] { display: none !important; }', $base, 'un elemento con hidden no se ve aunque su clase le dé display');
    }

    public function test_toda_clase_que_usa_una_vista_tiene_regla_en_las_hojas(): void
    {
        $css = '';
        foreach ($this->archivos(BASE_PATH . '/public/assets/css', 'css') as $contenido) $css .= $contenido;
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        preg_match_all('/\.([A-Za-z_][\w-]*)/', $css, $m);
        $definidas = array_flip($m[1]);

        $sinRegla = [];
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            // Con el bloque PHP quitado, «pct-» seguido de un valor calculado queda como un prefijo («pct-»), no como una clase.
            preg_match_all('/class="([^"]*)"/', preg_replace('/<\?.*?\?>/s', ' ', $codigo) ?? '', $clases);
            foreach ($clases[1] as $lista) {
                foreach (preg_split('/\s+/', trim($lista)) ?: [] as $clase) {
                    if ($clase === '' || str_ends_with($clase, '-')) continue;
                    if (!isset($definidas[$clase])) $sinRegla[$clase][] = $ruta;
                }
            }
        }
        $this->assertSame([], $sinRegla, 'clases usadas por una vista sin regla en ninguna hoja: ' . json_encode($sinRegla, JSON_UNESCAPED_UNICODE));
    }

    public function test_las_clases_de_porcentaje_cubren_de_cero_a_cien(): void
    {
        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/utilidades.css');
        for ($i = 0; $i <= 100; $i++) {
            $this->assertTrue(str_contains($css, ".pct-{$i} { width: {$i}%; }"), "falta .pct-{$i}");
        }
    }

    public function test_la_grilla_del_calendario_no_se_ensancha_con_un_titulo_largo(): void
    {
        $paginas = (string) file_get_contents(BASE_PATH . '/public/assets/css/paginas.css');
        // Con repeat(7, 1fr) una columna no baja del ancho de su contenido: un título largo ensanchaba el calendario más allá de la pantalla.
        $this->assertStringContainsString('grid-template-columns: repeat(7, minmax(0, 1fr))', $paginas);
        $this->assertFalse((bool) preg_match('/repeat\(7,\s*1fr\)/', $paginas), 'repeat(7, 1fr) deja crecer las columnas');
        $this->assertTrue((bool) preg_match('/\.cal-dia\s*\{[^}]*min-width:\s*0/', $paginas), 'la celda también puede achicarse');
        $this->assertTrue((bool) preg_match('/\.cal-titulo\s*\{[^}]*white-space:\s*nowrap[^}]*overflow:\s*hidden[^}]*text-overflow:\s*ellipsis/', $paginas), 'el título largo se corta con puntos suspensivos');

        // Mobile First: en pantalla chica puntos + lista del mes; desde 721 px, tarjetas por día y sin la lista.
        $this->assertTrue((bool) preg_match('/\.cal-lista\s*\{\s*display:\s*none/', $paginas), 'las tarjetas no entran en un cuadrito de 45 px');
        $this->assertFalse((bool) preg_match('/\.cal-agenda\s*\{[^}]*display:\s*none/', $paginas), 'en el celular la lista del mes se ve');
        $responsive = (string) file_get_contents(BASE_PATH . '/public/assets/css/responsive.css');
        $this->assertTrue((bool) preg_match('/@media \(min-width: 721px\)\s*\{[^@]*\.cal-puntos\s*\{\s*display:\s*none;?\s*\}[^@]*\.cal-lista\s*\{\s*display:\s*flex;?\s*\}[^@]*\.cal-agenda\s*\{\s*display:\s*none/s', $responsive), 'desde 721 px: sin puntos ni lista del mes, con las tarjetas');
    }

    public function test_el_script_del_calendario_no_arma_estilos_ni_html_con_datos(): void
    {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/calendar.js');
        $this->assertFalse((bool) preg_match('/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js), 'la CSP no admite estilos en línea: todo va por clases');
        $this->assertFalse((bool) preg_match('/innerHTML\s*(?:\+|\|\|)?=(?!\s*\'\')/', $js), 'innerHTML solo para vaciar: el nombre de un torneo se escribe con textContent');
        $this->assertFalse((bool) preg_match('/insertAdjacentHTML|outerHTML|document\.write/', $js));
    }

    public function test_toda_clase_que_arma_el_calendario_tiene_regla_en_las_hojas(): void
    {
        $css = '';
        foreach ($this->archivos(BASE_PATH . '/public/assets/css', 'css') as $contenido) $css .= $contenido;
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        preg_match_all('/\.([A-Za-z_][\w-]*)/', $css, $m);
        $definidas = array_flip($m[1]);

        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/calendar.js');
        $js = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $js) ?? $js;
        preg_match_all("/el\(\s*'[a-z0-9]+'\s*,\s*'([^']+)'|'(cal-[\w -]+)'/", $js, $usos, PREG_SET_ORDER);
        $clases = [];
        foreach ($usos as $uso) {
            foreach (preg_split('/\s+/', trim($uso[1] !== '' ? $uso[1] : ($uso[2] ?? ''))) ?: [] as $clase) {
                if ($clase !== '') $clases[$clase] = true;
            }
        }
        $this->assertGreaterThan(15, count($clases), 'se leyeron las clases del script');
        // «cal-mes» no pinta nada: es el sitio del título que se anuncia (aria-live).
        $sinRegla = array_values(array_filter(array_keys($clases), fn (string $c) => !isset($definidas[$c]) && $c !== 'cal-mes'));
        $this->assertSame([], $sinRegla, 'clases que dibuja calendar.js sin regla en las hojas');
    }

    public function test_el_texto_de_una_persona_sin_espacios_se_parte_en_las_tarjetas_donde_se_muestra(): void
    {
        // Un nombre de torneo de 80 letras sin espacios ensanchaba la tarjeta y con ella toda la página (en el celular, sacaba el contenido de
        // pantalla). Lo encontró el recorrido de Cypress al crear a propósito un torneo así: `tests/e2e/cypress/e2e/07_pantallas_y_adaptabilidad.cy.js`.
        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/componentes.css');
        $this->assertTrue((bool) preg_match('/\.rompe-texto\s*\{\s*min-width:\s*0;\s*overflow-wrap:\s*anywhere;\s*\}/', $css), 'la clase parte el texto largo y deja encoger al elemento');

        // Solo donde se muestra texto de una persona. Puesta en general parte palabras normales de elementos angostos: se probó, y
        // «Semifinales» salía como «Semifinale/s» y «García» como «Garcí/a».
        $this->assertFalse((bool) preg_match('/(?:^|\})\s*(?:\.card|\.panel|h1|body|main)[^{}]*\{[^}]*overflow-wrap:\s*anywhere/m', $css), 'ninguna regla general usa overflow-wrap: anywhere');

        foreach (['public/torneos.php', 'public/torneo_detalle.php', 'organizador/dashboard.php', 'organizador/torneo_gestion.php', 'admin/dashboard.php', 'admin/torneo_gestion.php', 'admin/torneo_reporte.php'] as $vista) {
            $this->assertStringContainsString('rompe-texto', (string) file_get_contents(APP_PATH . '/views/' . $vista), "{$vista} muestra texto de una persona en una tarjeta");
        }
        // La lista en vivo dibuja el mismo título que el servidor, con la misma clase.
        $this->assertStringContainsString("el('strong', 'rompe-texto', t.nombre)", (string) file_get_contents(BASE_PATH . '/public/assets/js/torneos-en-vivo.js'));
    }

    public function test_la_busqueda_en_vivo_no_arma_estilos_ni_html_con_datos_y_sus_clases_tienen_regla(): void
    {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/torneos-en-vivo.js');
        $this->assertFalse((bool) preg_match('/\.style\b|cssText|setAttribute\(\s*[\'"]style/', $js), 'la CSP no admite estilos en línea: todo va por clases');
        $this->assertFalse((bool) preg_match('/innerHTML\s*(?:\+|\|\|)?=(?!\s*\'\')/', $js), 'innerHTML solo para vaciar: lo que viene de la API se escribe con textContent');
        $this->assertFalse((bool) preg_match('/insertAdjacentHTML|outerHTML|document\.write|eval\(|new Function/', $js));

        $css = '';
        foreach ($this->archivos(BASE_PATH . '/public/assets/css', 'css') as $contenido) $css .= $contenido;
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        preg_match_all('/\.([A-Za-z_][\w-]*)/', $css, $m);
        $definidas = array_flip($m[1]);

        $sinComentarios = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $js) ?? $js;
        preg_match_all("/el\(\s*'[a-z0-9]+'\s*,\s*'([^']+)'|classList\.(?:add|remove)\('([^']+)'\)/", $sinComentarios, $usos, PREG_SET_ORDER);
        $clases = [];
        foreach ($usos as $uso) {
            foreach (preg_split('/\s+/', trim($uso[1] !== '' ? $uso[1] : ($uso[2] ?? ''))) ?: [] as $clase) {
                if ($clase !== '') $clases[$clase] = true;
            }
        }
        $this->assertGreaterThan(15, count($clases), 'se leyeron las clases del script');
        $sinRegla = array_values(array_filter(array_keys($clases), fn (string $c) => !isset($definidas[$c])));
        $this->assertSame([], $sinRegla, 'clases que dibuja torneos-en-vivo.js sin regla en las hojas');
        foreach (['cargando', 'alerta-lista', 'solo-lectores'] as $nueva) {
            $this->assertTrue(isset($definidas[$nueva]), "falta la regla .{$nueva}");
        }
        // Lo que dibuja tiene que ser lo mismo que dibuja el servidor: las clases de la tarjeta salen de la vista pública.
        $vista = (string) file_get_contents(APP_PATH . '/views/public/torneos.php');
        foreach (['card flex flex-col gap-06', 'flex justify-between items-start gap-05', 'btn small primary mt-auto', 'card sin-resultados', 'grid cols-3', 'muted ml-04'] as $clase) {
            $this->assertStringContainsString($clase, $vista, "la vista del servidor ya no usa «{$clase}»");
            $this->assertStringContainsString($clase, $js, "la búsqueda en vivo ya no dibuja «{$clase}»: dejaría de verse igual al servidor");
        }
    }

    public function test_la_lista_publica_de_torneos_carga_la_busqueda_en_vivo_y_su_formulario_la_pide(): void
    {
        // Sin el script, el formulario con data-en-vivo sigue funcionando como un GET común, pero la mejora se perdería sin avisar.
        $controlador = (string) file_get_contents(APP_PATH . '/controllers/PublicoController.php');
        $this->assertSame(1, preg_match("/function torneos\(\)[^{]*\{.*?'extraJs'\s*=>\s*\[([^\]]*)\]/s", $controlador, $m), 'torneos() declara extraJs');
        preg_match_all("/'([\w.-]+\.js)'/", $m[1], $js);
        $this->assertSame(['formato.js', 'api.js', 'torneos-en-vivo.js'], $js[1], 'la lista pública carga formato.js, api.js y después torneos-en-vivo.js');
        $this->assertTrue(is_file(BASE_PATH . '/public/assets/js/torneos-en-vivo.js'), 'el script existe');

        $vista = (string) file_get_contents(APP_PATH . '/views/public/torneos.php');
        $this->assertStringContainsString("'en_vivo' => '/api/torneos'", $vista, 'el formulario pide la actualización en vivo');
        $this->assertStringContainsString('id="lista-torneos"', $vista, 'la zona que reemplaza el script');
        $this->assertStringContainsString('id="estado-lista"', $vista, 'el aviso para los lectores de pantalla');

        // Y solo esa lista: las demás listas del panel siguen siendo recargas comunes.
        foreach (glob(APP_PATH . '/views/{admin,organizador,participante,public}/*.php', GLOB_BRACE) ?: [] as $archivo) {
            if (str_replace('\\', '/', $archivo) === str_replace('\\', '/', APP_PATH) . '/views/public/torneos.php') continue;
            $this->assertStringNotContainsString("'en_vivo'", (string) file_get_contents($archivo), basename($archivo) . ' no tiene su script de actualización en vivo');
        }
    }

    public function test_cada_pantalla_carga_formato_y_api_antes_de_los_scripts_que_los_usan(): void
    {
        $dependencias = ['FlexFormato' => 'formato.js', 'FlexApi' => 'api.js'];
        $usan = [];   // script => [dependencias que usa]
        foreach (glob(BASE_PATH . '/public/assets/js/*.js') ?: [] as $archivo) {
            $nombre = basename($archivo);
            $codigo = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($archivo)) ?? '';
            foreach ($dependencias as $global => $provee) {
                if ($nombre !== $provee && str_contains($codigo, $global . '.')) $usan[$nombre][] = $provee;
                if ($nombre !== $provee && preg_match('/=\s*' . $global . '\s*;/', $codigo)) $usan[$nombre][] = $provee;
            }
        }
        $this->assertTrue(isset($usan['calendar.js']) && isset($usan['proximos-partidos.js']) && isset($usan['torneos-en-vivo.js']), 'se leyeron los scripts que usan formato.js');

        foreach (glob(APP_PATH . '/controllers/*.php') ?: [] as $archivo) {
            preg_match_all("/'extraJs'\s*=>\s*\[([^\]]*)\]/", (string) file_get_contents($archivo), $m);
            foreach ($m[1] as $lista) {
                preg_match_all("/'([\w.-]+\.js)'/", $lista, $js);
                $orden = $js[1];
                foreach ($orden as $posicion => $script) {
                    foreach (array_unique($usan[$script] ?? []) as $necesita) {
                        $antes = array_slice($orden, 0, $posicion);
                        $this->assertTrue(in_array($necesita, $antes, true),
                            basename($archivo) . ": «{$script}» usa {$necesita}, que tiene que cargarse ANTES en extraJs (hoy: " . implode(', ', $orden) . ')');
                    }
                }
            }
        }
    }

    /** Luminancia relativa de un color #rrggbb (WCAG 2.x). */
    private function luminancia(string $hex): float
    {
        $canales = array_map(function (string $c): float {
            $v = hexdec($c) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    private function contraste(string $a, string $b): float
    {
        [$x, $y] = [$this->luminancia($a), $this->luminancia($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function test_el_boton_primario_tiene_contraste_suficiente_en_todo_su_degradado(): void
    {
        $componentes = (string) file_get_contents(BASE_PATH . '/public/assets/css/componentes.css');
        $tokens = (string) file_get_contents(BASE_PATH . '/public/assets/css/tokens.css');
        $this->assertTrue((bool) preg_match('/\.btn\s*\{[^}]*\bcolor:\s*#fff\b/', $componentes), 'el texto de los botones es blanco');
        $this->assertTrue((bool) preg_match('/\.btn\.primary\s*\{\s*background:\s*linear-gradient\(\s*135deg,\s*(#[0-9a-f]{6}),\s*(#[0-9a-f]{6}|var\(--[\w-]+\))\s*\)/i', $componentes, $g), 'el botón primario es un degradado de dos colores');

        $colores = [$g[1]];
        if (str_starts_with($g[2], 'var(')) {
            $this->assertTrue((bool) preg_match('/' . preg_quote(substr($g[2], 4, -1), '/') . ':\s*(#[0-9a-f]{6})/i', $tokens, $t), 'el token del degradado existe');
            $colores[] = $t[1];
        } else {
            $colores[] = $g[2];
        }
        foreach ($colores as $color) {
            // WCAG AA para texto normal: 4,5:1. Con el degradado anterior (#3498db) el extremo claro llegaba a 3,15:1.
            $razon = $this->contraste('#ffffff', $color);
            $this->assertTrue($razon >= 4.5, sprintf('blanco sobre %s: %.2f:1 (hace falta 4,5:1)', $color, $razon));
        }
        $this->assertSame('#1f7ab8', $colores[0], 'el extremo claro es el que se midió (4,63:1)');
    }

    public function test_los_scripts_que_piden_las_pantallas_existen(): void
    {
        $citados = [];
        foreach (glob(APP_PATH . '/controllers/*.php') ?: [] as $archivo) {
            preg_match_all("/'extraJs'\s*=>\s*\[([^\]]*)\]/", (string) file_get_contents($archivo), $m);
            foreach ($m[1] as $lista) {
                preg_match_all("/'([\w.-]+\.js)'/", $lista, $js);
                foreach ($js[1] as $nombre) $citados[$nombre][] = basename($archivo);
            }
        }
        $this->assertGreaterThan(8, count($citados), 'se leyeron las listas de scripts');
        foreach ($citados as $nombre => $quienes) {
            $this->assertTrue(is_file(BASE_PATH . '/public/assets/js/' . $nombre), "falta public/assets/js/{$nombre} (lo piden: " . implode(', ', $quienes) . ')');
        }
        foreach (['modales.js', 'gestion-torneo.js', 'bracket.js', 'correcciones.js', 'proximos-partidos.js', 'registro.js'] as $nuevo) {
            $this->assertTrue(is_file(BASE_PATH . '/public/assets/js/' . $nuevo), $nuevo);
        }
    }

    public function test_los_datos_para_los_scripts_viajan_en_bloques_json_seguros(): void
    {
        $torneo = $this->archivos(APP_PATH . '/views')['public/torneo_detalle.php'];
        $cal    = $this->archivos(APP_PATH . '/views')['public/torneos_calendario.php'];
        foreach ([$torneo, $cal] as $codigo) {
            $this->assertStringContainsString('type="application/json"', $codigo);
            $this->assertStringContainsString('JSON_HEX_TAG', $codigo, 'un nombre con </script> no puede cerrar el bloque');
            $this->assertStringContainsString('JSON_HEX_AMP', $codigo);
        }
        // y lo que sale: un nombre con </script> queda inofensivo dentro del bloque
        $json = json_encode(['a' => '</script><script>alert(1)</script>'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->assertStringNotContainsString('</script>', (string) $json);
        $this->assertStringNotContainsString('<', (string) $json);
    }

    // ── accesibilidad ──────────────────────────────────────────────────

    public function test_cada_etiqueta_de_formulario_esta_asociada_a_su_campo(): void
    {
        $sueltas = [];
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            preg_match_all('/<label((?:\s[^>]*)?)>(.*?)<\/label>/s', $codigo, $m, PREG_SET_ORDER);
            foreach ($m as $l) {
                $envuelve = (bool) preg_match('/<(?:input|select|textarea)\b/', $l[2]);
                $tieneFor = (bool) preg_match('/\sfor\s*=/', $l[1]);
                // Una etiqueta que no envuelve su campo ni lleva `for` no sirve a un lector de pantalla ni agranda el área de clic.
                if (!$envuelve && !$tieneFor) $sueltas[] = $ruta . ': ' . trim(strip_tags($l[2]));
            }
        }
        $this->assertSame([], $sueltas, "etiquetas sin for ni campo adentro:\n  " . implode("\n  ", $sueltas));
    }

    public function test_cada_campo_con_for_apunta_a_un_id_que_existe_en_su_vista(): void
    {
        $rotos = [];
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            preg_match_all('/<label[^>]*\sfor="([^"<]+)"/', $codigo, $fors);
            foreach ($fors[1] as $for) {
                if (!str_contains($codigo, 'id="' . $for . '"')) $rotos[] = "{$ruta}: for=\"{$for}\"";
            }
        }
        $this->assertSame([], $rotos, "for sin un id que lo reciba:\n  " . implode("\n  ", $rotos));
    }

    public function test_cada_tabla_tiene_titulo_accesible_y_sus_cabeceras_scope(): void
    {
        $mal = [];
        $tablas = 0;
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            preg_match_all('/<table\b([^>]*)>/', $codigo, $m);
            foreach ($m[1] as $atributos) {
                $tablas++;
                if (!preg_match('/\saria-label(?:ledby)?\s*=/', ' ' . $atributos)) $mal[] = "{$ruta}: <table> sin aria-label";
            }
            preg_match_all('/<thead>(.*?)<\/thead>/s', $codigo, $cabeceras);
            foreach ($cabeceras[1] as $cab) {
                preg_match_all('/<th\b([^>]*)>/', $cab, $ths);
                foreach ($ths[1] as $atributos) {
                    if (!preg_match('/\sscope\s*=/', ' ' . $atributos)) $mal[] = "{$ruta}: <th> de columna sin scope";
                }
            }
        }
        $this->assertGreaterThan(15, $tablas, 'se revisaron las tablas de verdad');
        $this->assertSame([], $mal, implode("\n", $mal));
    }

    public function test_los_dialogos_son_dialogos_para_un_lector_de_pantalla(): void
    {
        $mal = [];
        $dialogos = 0;
        foreach ($this->archivos(APP_PATH . '/views') as $ruta => $codigo) {
            preg_match_all('/<div class="modal-fondo[^"]*"[^>]*>\s*<div class="[^"]*"([^>]*)>/s', $codigo, $m);
            foreach ($m[1] as $atributos) {
                $dialogos++;
                if (!str_contains($atributos, 'role="dialog"')) $mal[] = "{$ruta}: falta role=\"dialog\"";
                if (!str_contains($atributos, 'aria-modal="true"')) $mal[] = "{$ruta}: falta aria-modal";
                if (!preg_match('/aria-labelledby="(\w+)"/', $atributos, $nombre)) {
                    $mal[] = "{$ruta}: falta aria-labelledby";
                } elseif (!str_contains($codigo, 'id="' . $nombre[1] . '"')) {
                    $mal[] = "{$ruta}: aria-labelledby=\"{$nombre[1]}\" no apunta a nada";
                }
            }
        }
        $this->assertSame(8, $dialogos, 'los 8 diálogos del sitio (3 de la gestión del administrador, 3 de la del organizador, rechazar y eliminar datos)');
        $this->assertSame([], $mal, implode("\n", $mal));
    }

    public function test_las_paginas_tienen_landmarks_y_enlace_para_saltar_al_contenido(): void
    {
        foreach (['layouts/public.php', 'layouts/admin.php'] as $layout) {
            $codigo = (string) file_get_contents(APP_PATH . '/views/' . $layout);
            $this->assertStringContainsString('<a class="saltar" href="#contenido">Saltar al contenido</a>', $codigo, "{$layout}: enlace para saltar");
            $this->assertStringContainsString('id="contenido"', $codigo, "{$layout}: el destino del enlace");
        }
        $this->assertStringContainsString('<main id="contenido"', (string) file_get_contents(APP_PATH . '/views/layouts/public.php'));
        $this->assertStringContainsString('<main class="app-main">', (string) file_get_contents(APP_PATH . '/views/layouts/admin.php'));
        $this->assertStringContainsString('<nav aria-label="Menú del panel">', (string) file_get_contents(APP_PATH . '/views/partials/sidebar.php'));
        $this->assertStringContainsString('<nav class="nav-links">', (string) file_get_contents(APP_PATH . '/views/partials/navbar_public.php'));
        foreach (['auth/login.php', 'auth/registro.php'] as $vista) {
            $codigo = (string) file_get_contents(APP_PATH . '/views/' . $vista);
            $this->assertStringContainsString('<main class="auth-layout" id="contenido">', $codigo, "{$vista}: el formulario es el contenido principal");
            $this->assertSame(1, preg_match_all('/<h1\b/', $codigo), "{$vista}: un solo h1");
            $this->assertStringContainsString('lang="es"', $codigo);
        }
        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/base.css');
        $this->assertStringContainsString('.saltar:focus', $css, 'el enlace aparece al recibir el foco');
        $this->assertStringContainsString('left: -9999px', $css, 'y estando quieto no se ve');
    }

    public function test_ninguna_hoja_apaga_el_marco_de_foco_del_teclado(): void
    {
        foreach ($this->archivos(BASE_PATH . '/public/assets/css', 'css') as $archivo => $css) {
            $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
            preg_match_all('/([^{}]+)\{([^{}]*outline\s*:\s*(?:none|0)\b[^{}]*)\}/', $css, $m, PREG_SET_ORDER);
            foreach ($m as $regla) {
                $selector = trim($regla[1]);
                // El destino del enlace de saltar recibe el foco solo por código. Los campos de texto cambian el marco por un borde de
                // otro color y un halo (input:focus, abajo); ningún otro control que se maneje con el teclado queda sin marca.
                $esCampo = (bool) preg_match('/^(?:input|select|textarea)\b|\b(?:input|select|textarea)(?:,|$)/', $selector) || str_contains($selector, 'select') || str_contains($selector, 'textarea');
                $this->assertTrue($selector === '#contenido:focus' || $esCampo, "{$archivo}: «{$selector}» apaga el marco de foco");
            }
        }
        $componentes = (string) file_get_contents(BASE_PATH . '/public/assets/css/componentes.css');
        $this->assertTrue((bool) preg_match('/input:focus, select:focus, textarea:focus\s*\{[^}]*border-color[^}]*box-shadow/s', $componentes), 'un campo enfocado cambia de borde y lleva un halo');
        $base = (string) file_get_contents(BASE_PATH . '/public/assets/css/componentes.css');
        $this->assertStringContainsString(':focus-visible { outline: 2px solid var(--primary-strong); outline-offset: 2px; }', $base, 'el marco de foco visible para todo lo demás');
    }

    public function test_los_avisos_se_anuncian_a_los_lectores_de_pantalla(): void
    {
        $flash = (string) file_get_contents(APP_PATH . '/views/partials/flash_messages.php');
        $this->assertStringContainsString("role=\"<?= \$key === 'error' ? 'alert' : 'status' ?>\"", $flash, 'un error se anuncia al instante; el resto, sin interrumpir');
        foreach (['admin/torneo_gestion.php', 'organizador/torneo_gestion.php'] as $vista) {
            $this->assertStringContainsString('data-api-error hidden role="alert"', (string) file_get_contents(APP_PATH . '/views/' . $vista), "{$vista}: el motivo de un rechazo dentro del diálogo");
        }
    }

    public function test_el_404_tiene_titulo_propio(): void
    {
        $this->assertStringContainsString("'pageTitle' => 'Página no encontrada'", (string) file_get_contents(BASE_PATH . '/core/Router.php'));
        $this->assertStringContainsString("'pageTitle' => 'Página no encontrada'", (string) file_get_contents(BASE_PATH . '/app/controllers/PublicoController.php'));
    }

    public function test_los_campos_que_no_son_datos_de_quien_escribe_no_se_autocompletan(): void
    {
        $this->assertStringContainsString('type="email" autocomplete="off" name="email"', (string) file_get_contents(APP_PATH . '/views/admin/usuario_form.php'), 'el correo de otra persona');
        $this->assertStringContainsString('autocomplete="email"', (string) file_get_contents(APP_PATH . '/views/participante/perfil.php'), 'el propio sí');
        $this->assertStringContainsString('autocomplete="new-password"', (string) file_get_contents(APP_PATH . '/views/auth/registro.php'));
    }
}

TestCase::correrSiEsScript(FrontendEstaticoTest::class);
