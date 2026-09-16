<?php
declare(strict_types=1);

/**
 * Test de los destinos de redirección (`core/Url.php`).
 *
 * Varios controladores redirigían a un valor que venía del pedido —la cabecera
 * `Referer` en ResultadoController y CorreccionController, un campo `return` del
 * formulario en AdminController— para devolver al usuario a la pantalla de la
 * que vino. Sin filtrar, eso convierte al sistema en un trampolín hacia
 * cualquier sitio, que es la base de una estafa de phishing.
 *
 * Ahora todo destino pasa por `Url::interna()`, y `BaseController::redirect()`
 * lo aplica también como red de seguridad: aunque alguien olvide filtrar en un
 * controlador nuevo, no se puede salir del sitio.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/redireccion_segura_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('APP_URL')) define('APP_URL', 'https://flexarena.local');

final class RedireccionSeguraTest extends TestCase
{
    protected function setUp(): void
    {
        // El host del pedido en curso: en CLI no existe, así que se simula.
        $_SERVER['HTTP_HOST'] = 'flexarena.local';
    }

    // ─── Lo que se acepta ───────────────────────────────────────────────────

    public function test_acepta_las_rutas_del_propio_sitio(): void
    {
        foreach ([
            '/admin/torneos',
            '/admin/torneos/5',
            '/organizador/torneos/12?ronda=3',
            '/',
        ] as $ruta) {
            $this->assertSame($ruta, Url::rutaInterna($ruta), "«{$ruta}» es una ruta interna");
        }
    }

    public function test_una_url_absoluta_del_mismo_host_se_reduce_a_su_ruta(): void
    {
        // Se vuelve por ruta y no por URL completa: el esquema y el puerto que
        // trae el Referer pueden no ser los que el visitante está usando.
        $this->assertSame('/admin/torneos/5',
            Url::rutaInterna('https://flexarena.local/admin/torneos/5'));

        $this->assertSame('/admin/torneos?estado=en_curso',
            Url::rutaInterna('http://flexarena.local:8080/admin/torneos?estado=en_curso'));
    }

    public function test_reconoce_el_host_del_pedido_en_curso(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        $this->assertSame('/admin/torneos', Url::rutaInterna('http://localhost:8080/admin/torneos'),
            'el host del pedido cuenta como propio aunque no sea el de APP_URL');
        $this->assertSame(null, Url::rutaInterna('http://localhost.ataque.com/admin/torneos'),
            'un host que solo EMPIEZA igual no es el mismo host');
    }

    // ─── Lo que se rechaza ──────────────────────────────────────────────────

    /**
     * Cada caso es una forma conocida de disfrazar un destino externo.
     *
     * @return array<string, string>
     */
    private function destinosExternos(): array
    {
        return [
            'otro dominio'                 => 'https://sitio-falso.com/login',
            'otro dominio sin esquema'     => 'sitio-falso.com/login',
            'protocolo relativo'           => '//sitio-falso.com/login',
            'protocolo relativo con barra' => '/\\sitio-falso.com/login',
            'subdominio parecido'          => 'https://flexarena.local.sitio-falso.com/',
            'host que contiene al nuestro' => 'https://malo.com/?x=flexarena.local',
            'javascript'                   => 'javascript:alert(1)',
            'data'                         => 'data:text/html,<script>alert(1)</script>',
            'inyeccion de cabecera'        => "/admin\r\nSet-Cookie: rol=admin",
            'byte nulo'                    => "/admin\0/torneos",
            'vacio'                        => '',
            'solo espacios'                => '   ',
        ];
    }

    public function test_rechaza_todo_destino_que_salga_del_sitio(): void
    {
        $aceptados = [];
        foreach ($this->destinosExternos() as $etiqueta => $destino) {
            $resultado = Url::rutaInterna($destino);
            if ($resultado !== null) {
                $aceptados[] = "{$etiqueta} → aceptado como «{$resultado}»";
            }
        }

        $this->assertCount(0, $aceptados, "\n        - " . implode("\n        - ", $aceptados));
    }

    public function test_lo_rechazado_cae_en_el_destino_de_reserva(): void
    {
        foreach ($this->destinosExternos() as $etiqueta => $destino) {
            $this->assertSame('/admin/torneos', Url::interna($destino, '/admin/torneos'),
                "«{$etiqueta}» debería caer en el destino de reserva");
        }

        // Y null (la cabecera Referer ausente) también.
        $this->assertSame('/organizador/torneos', Url::interna(null, '/organizador/torneos'));
    }

    public function test_un_destino_bueno_no_se_reemplaza_por_el_de_reserva(): void
    {
        $this->assertSame('/admin/torneos/7', Url::interna('/admin/torneos/7', '/admin/torneos'));
    }

    // ─── El filtro está puesto donde hace falta ─────────────────────────────

    public function test_redirect_filtra_por_su_cuenta(): void
    {
        // Red de seguridad: aunque un controlador nuevo olvide filtrar, no se
        // puede salir del sitio.
        //
        // `redirect()` no se puede ejecutar en un test: llama a exit() y mataría
        // el proceso, y en CLI `header()` no deja rastro que consultar. Así que
        // se comprueba sobre el código fuente del método, igual que con las
        // guardas de permisos. Lo que ese filtro hace ya está cubierto por los
        // casos de `Url::interna()` de más arriba.
        $r      = new ReflectionMethod(BaseController::class, 'redirect');
        $lineas = file((string) $r->getFileName());
        $cuerpo = implode('', array_slice(
            (array) $lineas,
            $r->getStartLine() - 1,
            $r->getEndLine() - $r->getStartLine() + 1
        ));

        $this->assertStringContainsString('Url::interna', $cuerpo,
            'redirect() tiene que filtrar el destino antes de mandarlo en la cabecera');
        $this->assertStringNotContainsString("header('Location: ' . \$url)", $cuerpo,
            'no puede quedar la version que manda el destino crudo');
    }

    public function test_los_controladores_filtran_el_referer_y_el_campo_return(): void
    {
        // Que `Url` funcione no sirve de nada si los controladores siguen
        // pasando el valor crudo: ese era exactamente el problema.
        $sinFiltrar = [];
        foreach (['ResultadoController', 'CorreccionController', 'AdminController'] as $clase) {
            $fuente = (string) file_get_contents(dirname(__DIR__) . "/app/controllers/{$clase}.php");

            foreach (explode("\n", $fuente) as $n => $linea) {
                $usaEntrada = str_contains($linea, 'HTTP_REFERER')
                           || str_contains($linea, "postStr('return'");
                if (!$usaEntrada) continue;
                if (str_contains($linea, 'Url::interna')) continue;

                $sinFiltrar[] = "{$clase}.php:" . ($n + 1) . ' → ' . trim($linea);
            }
        }

        $this->assertCount(0, $sinFiltrar,
            "hay entrada del pedido llegando cruda a un redirect:\n        - "
            . implode("\n        - ", $sinFiltrar));
    }

    public function test_redirect_sigue_siendo_el_unico_camino_de_salida(): void
    {
        // Si alguien llama a header('Location: ...') directo, se saltea el
        // filtro. El único lugar donde puede aparecer es BaseController.
        $sospechosos = [];
        foreach (['app/controllers', 'app/services', 'app/views'] as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir)
            );
            foreach ($it as $archivo) {
                if (!$archivo->isFile() || $archivo->getExtension() !== 'php') continue;
                $fuente = (string) file_get_contents($archivo->getPathname());
                if (preg_match('/header\s*\(\s*[\'"]Location:/i', $fuente) === 1) {
                    $sospechosos[] = $dir . '/' . $archivo->getFilename();
                }
            }
        }

        $this->assertCount(0, $sospechosos,
            'redirigen sin pasar por redirect(): ' . implode(', ', $sospechosos));
    }
}

exit(TestCase::ejecutar(RedireccionSeguraTest::class));
