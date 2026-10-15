<?php
declare(strict_types=1);

/**
 * Test del manejo central de errores (ManejadorErrores, Respuesta, ErrorNegocio).
 *
 * Qué pasaba antes:
 *   - En producción `error_reporting(0)` apagaba también el log: un error no dejaba rastro.
 *   - Una excepción sin atrapar daba una página en blanco.
 *   - Los controladores mostraban el mensaje de CUALQUIER RuntimeException, y PDOException lo
 *     es: un nick de 200 caracteres terminaba en pantalla como «SQLSTATE[22001]: Data too
 *     long for column…».
 *   - Y el proceso web (www-data) no podía escribir php_errors.log, que crean como root los
 *     scripts de arranque: aun con el registro activado, la web no registraba nada.
 *
 * Qué se comprueba:
 *   1. Qué pedidos reciben JSON y cuáles HTML.
 *   2. Que un error de negocio muestre su mensaje y uno técnico, un aviso genérico con un
 *      código de referencia (y el detalle solo en desarrollo).
 *   3. Que el registro lleve el código, la clase, el mensaje, el archivo y el pedido, y que
 *      NO lleve argumentos de la traza (contraseñas) ni la query string.
 *   4. El formato único de las respuestas JSON.
 *   5. Que nadie muestre getMessage() crudo y que `_method` solo admita PUT, PATCH y DELETE.
 *   6. Los guardias de configuración: log activado, y archivo de log escribible por Apache.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/manejador_errores_test.php
 */

require __DIR__ . '/bootstrap.php';

// Las vistas necesitan las constantes que normalmente define public/index.php.
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
if (!defined('CORE_PATH')) define('CORE_PATH', BASE_PATH . '/core');
require_once BASE_PATH . '/config/app.php';

class ManejadorErroresTest extends TestCase
{
    private string $log = '';
    private string|false $logAnterior = '';

    protected function setUp(): void
    {
        // error_log() escribe donde diga el ini `error_log`: se lo apunta a un archivo propio.
        $this->log         = (string) tempnam(sys_get_temp_dir(), 'fa_log_');
        $this->logAnterior = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->logAnterior === false ? '' : $this->logAnterior);
        @unlink($this->log);
    }

    private function contenidoLog(): string
    {
        return (string) file_get_contents($this->log);
    }

    private function fuente(string $ruta): string
    {
        return (string) file_get_contents(BASE_PATH . '/' . $ruta);
    }

    /** Una función con un argumento sensible, para ver que la traza no lo guarde. */
    private static function iniciarSesionCon(string $clave): never
    {
        throw new RuntimeException('falló el inicio de sesión');
    }

    // ─── 1. Qué pedidos reciben JSON ────────────────────────────────────────

    public function test_la_api_y_los_fetch_reciben_json_y_el_resto_html(): void
    {
        $this->assertTrue(ManejadorErrores::esPeticionApi('api/torneos', []), 'una ruta /api');
        $this->assertTrue(ManejadorErrores::esPeticionApi('/api/torneos/3/posiciones', []), 'con barra inicial');
        $this->assertTrue(ManejadorErrores::esPeticionApi('api', []), 'la raíz de la API');
        $this->assertTrue(ManejadorErrores::esPeticionApi('torneos', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), 'un fetch');
        $this->assertTrue(ManejadorErrores::esPeticionApi('torneos', ['HTTP_ACCEPT' => 'application/json']), 'pide JSON');

        $this->assertFalse(ManejadorErrores::esPeticionApi('torneos', []), 'una página normal');
        $this->assertFalse(ManejadorErrores::esPeticionApi('apiario', []), '«apiario» no es la API');
        $this->assertFalse(ManejadorErrores::esPeticionApi('torneos', ['HTTP_ACCEPT' => 'text/html,application/json;q=0.9']),
            'un navegador acepta HTML primero');
    }

    // ─── 2. Qué se le muestra a la persona ──────────────────────────────────

    public function test_un_error_de_negocio_muestra_su_mensaje(): void
    {
        $d = ManejadorErrores::describir(new ErrorNegocio('Ya existe un equipo con ese nombre.'), 'abcd1234', false);

        $this->assertSame(400, $d['estado']);
        $this->assertSame('peticion_invalida', $d['codigo']);
        $this->assertSame('Ya existe un equipo con ese nombre.', $d['mensaje']);
    }

    public function test_un_error_tecnico_muestra_un_aviso_generico(): void
    {
        $e = new PDOException("SQLSTATE[22001]: Data too long for column 'nick' at row 1");
        $d = ManejadorErrores::describir($e, 'abcd1234', false);

        $this->assertSame(500, $d['estado']);
        $this->assertSame('error_interno', $d['codigo']);
        $this->assertSame(ManejadorErrores::MENSAJE_GENERICO, $d['mensaje']);
        $this->assertStringNotContainsString('SQLSTATE', $d['mensaje'] . $d['titulo'], 'nada de la base llega a la persona');
        $this->assertSame(null, $d['detalle'], 'sin detalle fuera de desarrollo');

        $dev = ManejadorErrores::describir($e, 'abcd1234', true);
        $this->assertStringContainsString('PDOException', (string) $dev['detalle'], 'en desarrollo sí se ve');
        $this->assertStringContainsString('Data too long', (string) $dev['detalle']);
    }

    public function test_la_pagina_de_error_muestra_el_codigo_y_no_el_detalle_tecnico(): void
    {
        $r = ManejadorErrores::responder(new PDOException('SQLSTATE[HY000] tabla_secreta'), false, false);

        $this->assertSame(500, $r['estado']);
        $this->assertStringContainsString('text/html', $r['tipo']);
        $this->assertStringContainsString('Algo salió mal', $r['cuerpo']);
        $this->assertStringContainsString($r['id'], $r['cuerpo'], 'la persona ve el código de referencia');
        $this->assertStringNotContainsString('tabla_secreta', $r['cuerpo']);
        $this->assertStringNotContainsString('SQLSTATE', $r['cuerpo']);
        $this->assertStringContainsString('flexarena-logo', $r['cuerpo'], 'es la página del sitio, con su diseño');
    }

    public function test_en_desarrollo_la_pagina_incluye_el_detalle(): void
    {
        $r = ManejadorErrores::responder(new RuntimeException('detalle_para_el_programador'), true, false);
        $this->assertStringContainsString('detalle_para_el_programador', $r['cuerpo']);
    }

    public function test_el_mensaje_de_negocio_sale_escapado(): void
    {
        $r = ManejadorErrores::responder(new ErrorNegocio('<script>alert(1)</script>'), false, false);

        $this->assertStringNotContainsString('<script>alert(1)', $r['cuerpo']);
        $this->assertStringContainsString('&lt;script&gt;', $r['cuerpo']);
    }

    public function test_si_no_se_puede_dibujar_la_pagina_va_una_minima(): void
    {
        // La página de error usa el layout del sitio, que puede depender de la misma base que
        // acaba de fallar. Nunca debe quedar una página en blanco.
        $r = ManejadorErrores::responder(new RuntimeException('x'), false, false, 'shared/no_existe');

        $this->assertSame(500, $r['estado']);
        $this->assertStringContainsString('<!DOCTYPE html>', $r['cuerpo']);
        $this->assertStringContainsString($r['id'], $r['cuerpo']);
        $this->assertStringContainsString('no se pudo dibujar la página de error', $this->contenidoLog());
    }

    public function test_la_pagina_minima_escapa_su_contenido(): void
    {
        $html = ManejadorErrores::paginaMinima([
            'estado' => 500, 'titulo' => '<b>t</b>', 'mensaje' => '<i>m</i>', 'id' => '"><x>',
        ]);

        $this->assertStringNotContainsString('<b>t</b>', $html);
        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringContainsString('&lt;b&gt;t&lt;/b&gt;', $html);
    }

    // ─── 3. El registro ─────────────────────────────────────────────────────

    public function test_el_registro_deja_codigo_clase_mensaje_archivo_y_pedido(): void
    {
        $e  = new PDOException('SQLSTATE[23000]: duplicado');
        $id = ManejadorErrores::registrarEnLog($e, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/login?token=SECRETO_EN_LA_URL',
            'REMOTE_ADDR'    => '10.1.2.3',
        ]);
        $log = $this->contenidoLog();

        $this->assertTrue(preg_match('/^[0-9a-f]{8}$/', $id) === 1, 'el código es corto y se puede dictar por teléfono');
        $this->assertStringContainsString($id, $log);
        $this->assertStringContainsString('PDOException: SQLSTATE[23000]: duplicado', $log);
        $this->assertStringContainsString('tests/manejador_errores_test.php', $log, 'el archivo, relativo al proyecto');
        $this->assertStringNotContainsString(BASE_PATH . '/', str_replace('\\', '/', $log), 'sin la ruta absoluta del servidor');
        $this->assertStringContainsString('POST /login', $log);
        $this->assertStringContainsString('10.1.2.3', $log);
        $this->assertStringNotContainsString('SECRETO_EN_LA_URL', $log, 'la query string puede traer datos');
    }

    public function test_la_traza_no_guarda_los_argumentos(): void
    {
        // En la imagen `zend.exception_ignore_args` ya está activo y PHP ni siquiera junta los
        // argumentos. Se lo apaga acá para probar que el manejador NO depende de esa opción: en
        // un servidor con otro php.ini, getTraceAsString() mostraría el comienzo de la clave.
        $anterior = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::iniciarSesionCon('Clave-Muy-Secreta-9!');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Clave-Muy', $e->getTraceAsString(),
                    'control: sin la protección, la traza de PHP SÍ lleva el argumento');
                ManejadorErrores::registrarEnLog($e);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $anterior);
        }
        $log = $this->contenidoLog();

        $this->assertStringContainsString('iniciarSesionCon()', $log, 'la función sí aparece');
        $this->assertStringNotContainsString('Clave-Muy', $log, 'pero no lo que se le pasó: podría ser una contraseña');
    }

    public function test_la_causa_encadenada_tambien_se_registra(): void
    {
        ManejadorErrores::registrarEnLog(new RuntimeException('externa', 0, new PDOException('causa_original')));
        $log = $this->contenidoLog();

        $this->assertStringContainsString('causa:', $log);
        $this->assertStringContainsString('causa_original', $log);
    }

    // ─── 4. El formato JSON ─────────────────────────────────────────────────

    public function test_un_pedido_de_api_recibe_json_con_el_formato_unico(): void
    {
        $r = ManejadorErrores::responder(new PDOException('SQLSTATE[HY000] interno'), false, true);
        $j = json_decode($r['cuerpo'], true);

        $this->assertSame(500, $r['estado']);
        $this->assertStringContainsString('application/json', $r['tipo']);
        $this->assertSame(false, $j['ok']);
        $this->assertSame('error_interno', $j['error']['codigo']);
        $this->assertSame(ManejadorErrores::MENSAJE_GENERICO, $j['error']['mensaje']);
        $this->assertSame($r['id'], $j['error']['id']);
        $this->assertTrue(!isset($j['error']['detalle']), 'sin detalle fuera de desarrollo');
        $this->assertStringNotContainsString('SQLSTATE', $r['cuerpo']);

        $negocio = json_decode(ManejadorErrores::responder(new ErrorNegocio('Torneo cerrado.'), false, true)['cuerpo'], true);
        $this->assertSame('peticion_invalida', $negocio['error']['codigo']);
        $this->assertSame('Torneo cerrado.', $negocio['error']['mensaje']);
    }

    // ─── 4b. Acceso denegado y sesión requerida ─────────────────────────────
    //
    // Antes una compuerta que no daba verde hacía `http_response_code(403)` o `header('Location:
    // /login')` y `exit` en medio del controlador: no se podía ejecutar en un test y no dejaba
    // rastro. Ahora lanza AccesoDenegado / SesionRequerida y las traduce este manejador.

    public function test_un_acceso_denegado_es_un_403_con_el_motivo_y_sin_codigo_de_referencia(): void
    {
        $r = ManejadorErrores::responder(new AccesoDenegado('Tu rol no tiene acceso a esta sección.'), false, false);

        $this->assertSame(403, $r['estado']);
        $this->assertSame('', $r['id'], 'no es un error del sistema: no hay un código que dictar');
        $this->assertStringContainsString('Error 403', $r['cuerpo']);
        $this->assertStringContainsString('Tu rol no tiene acceso a esta sección.', $r['cuerpo'], 'la persona ve el motivo');
        $this->assertStringNotContainsString('Algo salió mal', $r['cuerpo']);
    }

    public function test_un_acceso_denegado_queda_anotado_en_una_linea_y_sin_traza(): void
    {
        ManejadorErrores::responder(new AccesoDenegado('Tu rol no tiene acceso a esta sección.'), false, false);
        $this->assertStringContainsString('ACCESO_DENEGADO', $this->contenidoLog(), 'responder() lo anota');

        @file_put_contents($this->log, '');
        ManejadorErrores::registrarAcceso(new AccesoDenegado('Tu rol no tiene acceso a esta sección.'), [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/resultados/corregir?x=SECRETO', 'REMOTE_ADDR' => '10.1.2.3',
        ]);
        $log = $this->contenidoLog();

        $this->assertStringContainsString('ACCESO_DENEGADO POST /admin/resultados/corregir', $log);
        $this->assertStringContainsString('10.1.2.3', $log);
        $this->assertStringContainsString('Tu rol no tiene acceso a esta sección.', $log);
        $this->assertStringNotContainsString('SECRETO', $log, 'la query string puede traer datos');
        $this->assertStringNotContainsString('#0', $log, 'no es un error del sistema: sin traza');
        $this->assertSame(1, substr_count(trim($log), "\n") + 1, 'una sola línea');
    }

    public function test_un_acceso_denegado_a_la_api_es_un_403_en_json_sin_id(): void
    {
        $r = ManejadorErrores::responder(new AccesoDenegado('Sin permiso para esto.'), false, true);
        $j = json_decode($r['cuerpo'], true);

        $this->assertSame(403, $r['estado']);
        $this->assertStringContainsString('application/json', $r['tipo']);
        $this->assertSame('sin_permiso', $j['error']['codigo']);
        $this->assertSame('Sin permiso para esto.', $j['error']['mensaje']);
        $this->assertTrue(!isset($j['error']['id']), 'sin código de referencia');
    }

    public function test_sin_sesion_una_pagina_redirige_a_login_y_no_se_anota_nada(): void
    {
        $r = ManejadorErrores::responder(new SesionRequerida('Hace falta iniciar sesión.'), false, false);

        $this->assertSame(302, $r['estado']);
        $this->assertSame('/login', $r['ubicacion']);
        $this->assertSame('', $r['cuerpo'], 'una redirección no lleva página');
        $this->assertSame('', $this->contenidoLog(), 'una sesión vencida es de lo más normal: no se anota');
    }

    public function test_sin_sesion_la_api_recibe_un_401_en_json(): void
    {
        $r = ManejadorErrores::responder(new SesionRequerida('Hace falta iniciar sesión.'), false, true);
        $j = json_decode($r['cuerpo'], true);

        $this->assertSame(401, $r['estado']);
        $this->assertSame('no_autenticado', $j['error']['codigo']);
        $this->assertTrue(!isset($r['ubicacion']), 'a la API no se la redirige');
        $this->assertSame('', $this->contenidoLog());
    }

    public function test_el_resto_de_los_errores_sigue_igual(): void
    {
        // Las dos excepciones nuevas no cambian lo que se le muestra a la persona en los demás casos.
        $r = ManejadorErrores::responder(new RuntimeException('detalle_interno'), false, false);
        $this->assertSame(500, $r['estado']);
        $this->assertTrue($r['id'] !== '', 'un error del sistema sí lleva código');
        $this->assertTrue(!isset($r['ubicacion']));
        $this->assertStringContainsString('ERROR ' . $r['id'], $this->contenidoLog());
    }

    public function test_formato_de_las_respuestas_json(): void
    {
        $this->assertSame(['ok' => true, 'datos' => [1, 2]], Respuesta::cuerpoOk([1, 2]), 'sin meta no hay clave meta');
        $this->assertSame(['ok' => true, 'datos' => [], 'meta' => ['pagina' => 2, 'total' => 40]],
            Respuesta::cuerpoOk([], ['pagina' => 2, 'total' => 40]));
        $this->assertSame(['ok' => false, 'error' => ['codigo' => 'no_encontrado', 'mensaje' => 'No existe.']],
            Respuesta::cuerpoError('no_encontrado', 'No existe.'));

        $this->assertSame('no_encontrado', Respuesta::codigoPara(404));
        $this->assertSame('sin_permiso', Respuesta::codigoPara(403));
        $this->assertSame('peticion_invalida', Respuesta::codigoPara(418), 'un 4xx desconocido');
        $this->assertSame('error_interno', Respuesta::codigoPara(503), 'un 5xx desconocido');
    }

    public function test_el_json_conserva_los_acentos_y_no_se_cae_con_bytes_invalidos(): void
    {
        $this->assertStringContainsString('Ñandú', Respuesta::codificar(Respuesta::cuerpoOk('Ñandú')), 'sin \\u00d1');
        $this->assertStringContainsString('/api/torneos', Respuesta::codificar(Respuesta::cuerpoOk('/api/torneos')), 'sin \\/');

        $roto = Respuesta::codificar(Respuesta::cuerpoOk("nombre \xB1 mal codificado"));
        $this->assertTrue(is_array(json_decode($roto, true)), 'sigue siendo JSON válido');
    }

    // ─── 5. Los controladores y el router ───────────────────────────────────

    public function test_mensajeDe_distingue_negocio_de_error_tecnico(): void
    {
        $controlador = new class extends BaseController {
            public function mensaje(Throwable $e): string { return $this->mensajeDe($e); }
        };

        $this->assertSame('Ya existe un equipo con ese nombre.',
            $controlador->mensaje(new ErrorNegocio('Ya existe un equipo con ese nombre.')));

        $texto = $controlador->mensaje(new PDOException("SQLSTATE[22001]: Data too long for column 'nick'"));
        $this->assertStringContainsString(ManejadorErrores::MENSAJE_GENERICO, $texto);
        $this->assertTrue(preg_match('/\(código [0-9a-f]{8}\)$/', $texto) === 1, 'termina con el código de referencia');
        $this->assertStringNotContainsString('SQLSTATE', $texto);
        $this->assertStringNotContainsString('nick', $texto);
        $this->assertStringContainsString("Data too long for column 'nick'", $this->contenidoLog(), 'pero el detalle queda en el registro');
    }

    public function test_ningun_controlador_muestra_el_mensaje_crudo_de_una_excepcion(): void
    {
        $crudos = [];
        foreach (glob(BASE_PATH . '/app/controllers/*.php') ?: [] as $archivo) {
            if (str_contains((string) file_get_contents($archivo), '->getMessage()')) {
                $crudos[] = basename($archivo);
            }
        }
        $this->assertSame([], $crudos,
            'estos controladores usan getMessage() directo; tienen que pasar por mensajeDe(): ' . implode(', ', $crudos));
    }

    public function test_los_servicios_lanzan_errores_de_negocio_y_no_runtimeexception_a_secas(): void
    {
        // Un servicio que lanza RuntimeException a secas deja la decisión de mostrarlo o no al azar
        // de quién lo atrape. Si el mensaje es para la persona es un ErrorNegocio; si es un
        // problema técnico, otra excepción (LogicException…), que va al registro con su código.
        $sueltos = [];
        foreach (glob(BASE_PATH . '/app/services/*.php') ?: [] as $archivo) {
            if (str_contains((string) file_get_contents($archivo), 'new RuntimeException(')) {
                $sueltos[] = basename($archivo);
            }
        }
        $this->assertSame([], $sueltos, 'usan RuntimeException a secas: ' . implode(', ', $sueltos));
    }

    public function test_un_post_solo_puede_hacerse_pasar_por_put_patch_o_delete(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE', 'put', ' Delete '] as $verbo) {
            $this->assertSame(strtoupper(trim($verbo)), Router::metodoEfectivo('POST', ['_method' => $verbo]), "«{$verbo}» se respeta");
        }

        // Antes un POST con _method=GET se despachaba como GET, por rutas que no piden CSRF.
        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT', '', 'x', 'POST; DROP'] as $verbo) {
            $this->assertSame('POST', Router::metodoEfectivo('POST', ['_method' => $verbo]), "«{$verbo}» se ignora");
        }
        $this->assertSame('POST', Router::metodoEfectivo('POST', ['_method' => ['DELETE']]), 'un arreglo no rompe nada');
        $this->assertSame('POST', Router::metodoEfectivo('POST', []), 'sin _method sigue siendo POST');
        $this->assertSame('GET', Router::metodoEfectivo('GET', ['_method' => 'DELETE']), 'solo un POST puede disfrazarse');
        $this->assertSame('GET', Router::metodoEfectivo('get', []));
    }

    // ─── 6. Guardias de configuración ───────────────────────────────────────

    public function test_la_configuracion_nunca_apaga_el_registro_de_errores(): void
    {
        // Solo el código: el comentario que explica el cambio nombra la línea vieja.
        $config = (string) preg_replace('~^\s*//.*$~m', '', $this->fuente('config/app.php'));

        $this->assertStringNotContainsString('error_reporting(0)', $config, 'B7: apagaba también el log');
        $this->assertStringContainsString('error_reporting(E_ALL)', $config);
        $this->assertStringContainsString("ini_set('log_errors', '1')", $config);
        $this->assertStringContainsString("ini_set('display_errors', APP_ENV === 'development'", $config,
            'solo la pantalla depende del entorno');
        $this->assertStringContainsString('ManejadorErrores::registrar(', $this->fuente('public/index.php'));
    }

    public function test_apache_puede_escribir_el_registro_de_php(): void
    {
        // php_errors.log lo crean como root los scripts de arranque; Apache ejecuta PHP como
        // www-data. Si el archivo sigue siendo de root, la web no puede registrar nada.
        $entrypoint = $this->fuente('docker/docker-entrypoint.sh');
        $this->assertStringContainsString('chown www-data:www-data "$LOG_PHP"', $entrypoint);
        $this->assertStringContainsString('LOG_PHP="/var/log/apache2/php_errors.log"', $entrypoint);
        $this->assertTrue(
            strpos($entrypoint, 'chown www-data:www-data "$LOG_PHP"') < strpos($entrypoint, 'exec apache2-foreground'),
            'se prepara ANTES de lanzar Apache');

        $dockerfile = $this->fuente('Dockerfile');
        $this->assertStringContainsString('error_log = /var/log/apache2/php_errors.log', $dockerfile);
        $this->assertStringContainsString('zend.exception_ignore_args = On', $dockerfile,
            'defensa extra: ni siquiera las trazas que arma PHP llevan argumentos');
    }
}

TestCase::correrSiEsScript(ManejadorErroresTest::class);
