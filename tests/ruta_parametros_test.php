<?php
declare(strict_types=1);

/**
 * Test de validación de los parámetros de ruta.
 *
 * El problema: «{id}» compilaba a ([^/]+), así que /torneo/1 OR 1=1 matcheaba la
 * ruta del detalle de torneo, el controlador hacía (int)'1 OR 1=1' → 1 y servía
 * la página del torneo 1 con código 200. Nunca hubo inyección —los modelos usan
 * consultas preparadas— pero una URL inválida devolvía una página válida en vez
 * de un 404.
 *
 * Se comprueba en dos niveles:
 *   1. De punta a punta: se despacha la URL contra el router real, con los
 *      controladores y las vistas reales, y se mira el código HTTP y el HTML.
 *      Es la reproducción exacta de lo reportado.
 *   2. Sobre la tabla de rutas completa: para CADA ruta con parámetros se
 *      comprueba que los valores basura no matcheen ninguna ruta y que los ids
 *      legítimos sigan matcheando la suya. Así la garantía no depende de los
 *      pocos casos que se puedan despachar sin sesión iniciada.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/ruta_parametros_test.php
 */

require __DIR__ . '/bootstrap.php';

// El router y las vistas necesitan las constantes que normalmente define
// public/index.php.
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('CORE_PATH', BASE_PATH . '/core');
require BASE_PATH . '/config/app.php';

final class RutaParametrosTest extends TestCase
{
    /** Fragmento propio de app/views/shared/404.php. */
    private const MARCA_404 = 'Página no encontrada';

    /**
     * Valores que NO son un id y por lo tanto no deben matchear ninguna ruta.
     * La clave es solo una etiqueta legible para el mensaje de error.
     */
    private const IDS_INVALIDOS = [
        'inyección booleana'   => '1 OR 1=1',
        'inyección con DROP'   => "1';DROP TABLE usuarios;--",
        'texto'                => 'abc',
        'negativo'             => '-1',
        'cero'                 => '0',
        'ceros a la izquierda' => '007',
        'decimal'              => '1.0',
        'notación científica'  => '1e3',
        'espacio adelante'     => ' 1',
        'espacio atrás'        => '1 ',
        'byte nulo'            => "1\0",
        'salto de línea'       => "1\n",
        'más de 10 dígitos'    => '99999999999',
        'vacío'                => '',
    ];

    /**
     * Basura pegada a un id que SÍ existe. Es el caso que importa: como el
     * controlador hace (int)$id, todos estos valores se leían como el id real y
     * devolvían su página con código 200.
     */
    private const SUFIJOS_INVALIDOS = [
        'inyección booleana'  => ' OR 1=1',
        'inyección con DROP'  => "';DROP TABLE usuarios;--",
        'texto pegado'        => 'abc',
        'guion y número'      => '-1',
        'decimal'             => '.0',
        'notación científica' => 'e3',
        'espacio atrás'       => ' ',
        'byte nulo'           => "\0",
        'salto de línea'      => "\n",
        'barra de más'        => '//',
    ];

    /** Ids legítimos: tienen que seguir matcheando. 4294967295 = máximo INT UNSIGNED. */
    private const IDS_VALIDOS = ['1', '53', '4294967295'];

    private static ?Router $router = null;
    /** @var array<string,array{id:int,marca:string}> recurso → id real y texto propio de su página */
    private static array $reales = [];

    protected function setUp(): void
    {
        if (self::$router === null) {
            $router = new Router();
            require BASE_PATH . '/config/routes.php';
            self::$router = $router;
        }

        if (self::$reales === []) {
            Fixtures::reset($this->db);
            $participantes = Fixtures::participantes(3, 'Jugador');
            $equipos       = Fixtures::equipos(1, $participantes);

            self::$reales = [
                'torneo'  => ['id' => Fixtures::torneo('liga'), 'marca' => 'Torneo de prueba'],
                'equipo'  => ['id' => $equipos[0],              'marca' => 'Equipo 1'],
                'jugador' => ['id' => $participantes[0],        'marca' => 'Jugador 1'],
            ];
        }
    }

    // ─── Nivel 1: de punta a punta contra el router real ────────────────────

    /**
     * Despacha una URL y devuelve ['codigo' => int, 'html' => string].
     *
     * Solo se usa con rutas públicas: las de panel llaman a exit() cuando no hay
     * sesión iniciada y matarían el proceso del test.
     */
    private function despachar(string $url): array
    {
        http_response_code(200); // el código sobrevive entre llamadas en CLI
        ob_start();
        try {
            self::$router->dispatch($url, 'GET');
        } finally {
            $html = (string) ob_get_clean();
        }
        return ['codigo' => (int) http_response_code(), 'html' => $html];
    }

    public function test_las_rutas_publicas_legitimas_siguen_sirviendo_su_pagina(): void
    {
        foreach (self::$reales as $recurso => $real) {
            $r = $this->despachar($recurso . '/' . $real['id']);

            $this->assertSame(200, $r['codigo'], "«{$recurso}/{$real['id']}» tiene que seguir devolviendo la página");
            $this->assertStringContainsString($real['marca'], $r['html']);
            $this->assertStringNotContainsString(self::MARCA_404, $r['html']);
        }
    }

    public function test_la_basura_pegada_a_un_id_real_devuelve_404_y_no_su_pagina(): void
    {
        // Acá están las dos URL exactas que se reportaron (inyección booleana y
        // DROP), junto con el resto de las formas de ensuciar un id que existe.
        foreach (self::$reales as $recurso => $real) {
            foreach (self::SUFIJOS_INVALIDOS as $etiqueta => $sufijo) {
                $url = $recurso . '/' . $real['id'] . $sufijo;
                $r   = $this->despachar($url);

                $this->assertSame(404, $r['codigo'], "«{$recurso}/{$real['id']}» + {$etiqueta} debería ser 404");
                $this->assertStringNotContainsString($real['marca'], $r['html'],
                    "«{$recurso}/{$real['id']}» + {$etiqueta} no puede devolver la página real");
            }
        }
    }

    public function test_las_rutas_publicas_rechazan_tambien_los_ids_que_no_son_numeros(): void
    {
        foreach (array_keys(self::$reales) as $recurso) {
            foreach (self::IDS_INVALIDOS as $etiqueta => $valor) {
                $r = $this->despachar($recurso . '/' . $valor);
                $this->assertSame(404, $r['codigo'], "«{$recurso}/» con {$etiqueta} debería ser 404");
            }
        }
    }

    public function test_la_tabla_usuarios_sigue_existiendo(): void
    {
        // Lo que se verificó a mano al reportar el problema: PDO aguanta, pero
        // conviene que quede asegurado junto al resto.
        $existe = (bool) $this->db->query("SHOW TABLES LIKE 'usuarios'")->fetchColumn();
        $this->assertTrue($existe, 'la URL con DROP TABLE no puede haber tocado el esquema');
    }

    // ─── Nivel 2: la tabla de rutas completa ────────────────────────────────

    /** @return list<array{method:string,path:string,pattern:string,params:list<string>}> */
    private function rutas(): array
    {
        $prop = new ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        return $prop->getValue(self::$router);
    }

    /** Devuelve el `path` de la ruta que matchea, o null si no matchea ninguna. */
    private function rutaQueMatchea(string $url, string $metodo): ?string
    {
        foreach ($this->rutas() as $ruta) {
            if ($ruta['method'] !== $metodo) continue;
            if (preg_match($ruta['pattern'], $url)) return $ruta['path'];
        }
        return null;
    }

    /** Reemplaza cada {param} de una ruta por el valor dado. */
    private function urlCon(string $path, string $valor): string
    {
        return (string) preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $valor, $path);
    }

    public function test_ninguna_ruta_del_sistema_acepta_un_id_invalido(): void
    {
        $conParametros = array_values(array_filter($this->rutas(), fn(array $r) => $r['params'] !== []));
        $this->assertGreaterThan(30, count($conParametros), 'se esperaban las ~40 rutas con {id}');

        $aceptadas = [];
        foreach ($conParametros as $ruta) {
            foreach (self::IDS_INVALIDOS as $etiqueta => $valor) {
                $url = $this->urlCon($ruta['path'], $valor);
                $matchea = $this->rutaQueMatchea($url, $ruta['method']);
                if ($matchea !== null) {
                    $aceptadas[] = "{$ruta['method']} {$ruta['path']} con {$etiqueta} → matchea «{$matchea}»";
                }
            }
        }

        $this->assertCount(0, $aceptadas,
            "rutas que aceptan un id inválido:\n        - " . implode("\n        - ", $aceptadas));
    }

    public function test_todas_las_rutas_siguen_aceptando_ids_legitimos(): void
    {
        $rotas = [];
        foreach ($this->rutas() as $ruta) {
            if ($ruta['params'] === []) continue;
            foreach (self::IDS_VALIDOS as $valor) {
                $url = $this->urlCon($ruta['path'], $valor);
                if ($this->rutaQueMatchea($url, $ruta['method']) !== $ruta['path']) {
                    $rotas[] = "{$ruta['method']} {$ruta['path']} deja de matchear con id={$valor}";
                }
            }
        }

        $this->assertCount(0, $rotas, implode('; ', $rotas));
    }

    public function test_las_rutas_sin_parametros_no_se_ven_afectadas(): void
    {
        foreach ([['GET', ''], ['GET', 'torneos'], ['GET', 'login'], ['POST', 'login']] as [$metodo, $url]) {
            $this->assertSame($url, $this->rutaQueMatchea($url, $metodo),
                "la ruta fija «{$metodo} {$url}» tiene que seguir resolviendo");
        }
    }

    public function test_un_parametro_sin_patron_definido_rompe_al_registrar_la_ruta(): void
    {
        // La lista de patrones es restrictiva a propósito: agregar una ruta con
        // un parámetro nuevo tiene que avisar, no aceptar cualquier cosa.
        $this->assertThrows(
            fn() => (new Router())->get('torneo/{slug}', 'PublicoController', 'detalle'),
            'no tiene patrón definido'
        );
    }
}

exit(TestCase::ejecutar(RutaParametrosTest::class));
