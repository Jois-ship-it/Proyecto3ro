<?php
declare(strict_types=1);

/**
 * El único lugar donde termina todo lo que nadie atrapó.
 *
 * Qué pasaba antes: en producción el error se ocultaba con `error_reporting(0)`, que además
 * apagaba el registro; una excepción sin atrapar daba una página en blanco y nada en el log.
 * Y los controladores mostraban el mensaje de cualquier RuntimeException, incluidas las de la
 * base de datos.
 *
 * Qué hace ahora, para cada excepción sin atrapar (y para los errores fatales de PHP):
 *
 *   1. La REGISTRA siempre, con un código de referencia, la clase, el mensaje, el archivo, la
 *      traza (sin argumentos: pueden ser contraseñas) y el pedido que la provocó.
 *   2. Responde con una página de error con el estilo del sitio (o con JSON si el pedido es de
 *      la API o de un fetch). La persona ve el mensaje de un ErrorNegocio, o un aviso genérico
 *      y el código de referencia; el detalle técnico solo se muestra en desarrollo.
 *
 * Los avisos y notas de PHP (warnings, notices) no pasan por acá: PHP los registra por su
 * cuenta mientras `log_errors` esté activo (config/app.php).
 */
final class ManejadorErrores
{
    public const MENSAJE_GENERICO = 'Ocurrió un error inesperado y ya quedó registrado.';

    private static bool $debug        = false;
    private static bool $registrado   = false;
    private static bool $respondiendo = false;

    /** Engancha el manejador. Se llama una vez, desde public/index.php. */
    public static function registrar(bool $debug): void
    {
        if (self::$registrado) return;
        self::$registrado = true;
        self::$debug      = $debug;

        set_exception_handler([self::class, 'alExcepcion']);
        register_shutdown_function([self::class, 'alApagar']);
    }

    // ─── Qué clase de pedido es ─────────────────────────────────────────────

    /**
     * ¿El pedido espera JSON? Sí si es de la API (`/api/…`), si lo hace un fetch
     * (X-Requested-With) o si pide JSON y no HTML.
     *
     * @param string|null $url      la ruta ya sin dominio (la que usa el Router); por defecto la del pedido
     * @param array|null  $servidor $_SERVER, o un arreglo equivalente
     */
    public static function esPeticionApi(?string $url = null, ?array $servidor = null): bool
    {
        $servidor ??= $_SERVER;
        if ($url === null) {
            $url = isset($_GET['url']) && is_string($_GET['url']) ? $_GET['url'] : '';
        }

        $ruta = trim($url, '/');
        if ($ruta === 'api' || str_starts_with($ruta, 'api/')) return true;

        if (strtolower((string) ($servidor['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return true;

        $acepta = strtolower((string) ($servidor['HTTP_ACCEPT'] ?? ''));
        return str_contains($acepta, 'application/json') && !str_contains($acepta, 'text/html');
    }

    // ─── Registro ───────────────────────────────────────────────────────────

    /**
     * Escribe la excepción en el log de PHP y devuelve su código de referencia, el mismo que
     * ve la persona. Lo usan también los controladores para los errores técnicos que atrapan
     * (BaseController::mensajeDe()).
     */
    public static function registrarEnLog(Throwable $e, ?array $servidor = null): string
    {
        $id = bin2hex(random_bytes(4));

        $partes = [
            sprintf('[FlexArena] ERROR %s %s', $id, self::resumen($e)),
            '  pedido: ' . self::pedido($servidor ?? $_SERVER),
            "  traza:\n" . self::traza($e),
        ];
        for ($previo = $e->getPrevious(); $previo !== null; $previo = $previo->getPrevious()) {
            $partes[] = '  causa: ' . self::resumen($previo) . "\n" . self::traza($previo);
        }

        error_log(implode("\n", $partes));
        return $id;
    }

    /**
     * Anota un acceso denegado en una sola línea: el pedido (método, ruta, IP y usuario) y el
     * motivo. Sin traza ni código: no es un error del sistema, es algo que conviene poder
     * buscar después («¿quién estuvo probando rutas de administración?»). Devuelve '' (no hay id).
     */
    public static function registrarAcceso(AccesoDenegado $e, ?array $servidor = null): string
    {
        error_log(sprintf('[FlexArena] ACCESO_DENEGADO %s | %s', self::pedido($servidor ?? $_SERVER), $e->getMessage()));
        return '';
    }

    private static function resumen(Throwable $e): string
    {
        return sprintf('%s: %s en %s:%d', get_class($e), $e->getMessage(), self::rutaCorta($e->getFile()), $e->getLine());
    }

    /**
     * La traza SIN los argumentos de cada llamada. getTraceAsString() los incluye
     * abreviados, y con `zend.exception_ignore_args` apagado (el valor por defecto) el
     * comienzo de una contraseña que pasó por password_verify() quedaba escrito en el log.
     */
    private static function traza(Throwable $e): string
    {
        $lineas = [];
        foreach ($e->getTrace() as $i => $paso) {
            $donde   = isset($paso['file']) ? self::rutaCorta((string) $paso['file']) . ':' . ($paso['line'] ?? 0) : '[interno]';
            $funcion = ($paso['class'] ?? '') . ($paso['type'] ?? '') . ($paso['function'] ?? '?') . '()';
            $lineas[] = sprintf('    #%d %s %s', $i, $donde, $funcion);
        }
        return $lineas === [] ? '    (sin traza)' : implode("\n", $lineas);
    }

    /** Método, ruta (sin query string: puede traer datos), IP y usuario del pedido. */
    private static function pedido(array $servidor): string
    {
        if (!isset($servidor['REQUEST_METHOD'])) return 'consola';

        $ruta    = parse_url((string) ($servidor['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '-';
        $usuario = $_SESSION['user_id'] ?? null;

        return sprintf('%s %s | ip %s | usuario %s',
            $servidor['REQUEST_METHOD'], $ruta, $servidor['REMOTE_ADDR'] ?? '-', $usuario ?? 'anónimo');
    }

    /** La ruta relativa al proyecto: más corta y sin mostrar cómo está armado el servidor. */
    private static function rutaCorta(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        if (defined('BASE_PATH')) {
            $base = rtrim(str_replace('\\', '/', BASE_PATH), '/') . '/';
            if (str_starts_with($ruta, $base)) return substr($ruta, strlen($base));
        }
        return $ruta;
    }

    // ─── Qué se le responde a la persona ────────────────────────────────────

    /**
     * Qué mostrar de una excepción. Un ErrorNegocio es un mensaje para la persona (400); todo
     * lo demás es un error nuestro (500) y se muestra el aviso genérico.
     *
     * @return array{estado:int, codigo:string, titulo:string, mensaje:string, id:string, detalle:?string}
     */
    public static function describir(Throwable $e, string $id, bool $debug): array
    {
        $negocio = $e instanceof ErrorNegocio;
        $acceso  = $e instanceof AccesoDenegado;
        $sesion  = $e instanceof SesionRequerida;
        $estado  = $sesion ? 401 : ($acceso ? 403 : ($negocio ? 400 : 500));

        return [
            'estado'  => $estado,
            'codigo'  => Respuesta::codigoPara($estado),
            'titulo'  => $sesion ? 'Hace falta iniciar sesión'
                       : ($acceso ? 'Sin permisos' : ($negocio ? 'No se pudo completar la operación' : 'Algo salió mal')),
            'mensaje' => ($negocio || $acceso || $sesion) ? $e->getMessage() : self::MENSAJE_GENERICO,
            'id'      => $id,
            'detalle' => $debug ? self::resumen($e) . "\n" . self::traza($e) : null,
        ];
    }

    /**
     * Registra la excepción y arma la respuesta, SIN enviarla (así se puede probar).
     *
     * Si falta la sesión y el pedido es de una página, no hay cuerpo: la respuesta es una redirección
     * (`ubicacion`) a /login, con el aviso que Auth::requireLogin() ya dejó en la sesión.
     *
     * @return array{estado:int, tipo:string, cuerpo:string, id:string, ubicacion?:string}
     */
    public static function responder(Throwable $e, bool $debug, bool $api, string $vista = 'shared/error'): array
    {
        if ($e instanceof SesionRequerida && !$api) {
            return ['estado' => 302, 'tipo' => 'text/html; charset=utf-8', 'cuerpo' => '', 'id' => '', 'ubicacion' => '/login'];
        }

        // Un acceso denegado no es un error del sistema: no lleva código de referencia ni traza,
        // pero queda anotado quién intentó qué (ver registrarAcceso()). Que falte la sesión es lo
        // más normal del mundo (una pestaña vieja): ni siquiera se anota.
        $acceso = $e instanceof AccesoDenegado;
        $id     = $e instanceof SesionRequerida ? '' : ($acceso ? self::registrarAcceso($e) : self::registrarEnLog($e));
        $d      = self::describir($e, $id, $debug);
        if ($acceso && $vista === 'shared/error') $vista = 'shared/403';

        if ($api) {
            $extra = ($id !== '' ? ['id' => $id] : []) + ($d['detalle'] !== null ? ['detalle' => $d['detalle']] : []);
            return [
                'estado' => $d['estado'],
                'tipo'   => 'application/json; charset=utf-8',
                'cuerpo' => Respuesta::codificar(Respuesta::cuerpoError($d['codigo'], $d['mensaje'], $extra)),
                'id'     => $id,
            ];
        }

        try {
            $html = self::construirHtml($d, $vista);
        } catch (Throwable $falla) {
            // La página de error usa el layout del sitio, que puede depender de la misma base
            // que acaba de fallar. Si no se puede dibujar, va una página mínima: nunca en blanco.
            error_log(sprintf('[FlexArena] ERROR %s no se pudo dibujar la página de error: %s', $id, $falla->getMessage()));
            $html = self::paginaMinima($d);
        }

        return ['estado' => $d['estado'], 'tipo' => 'text/html; charset=utf-8', 'cuerpo' => $html, 'id' => $id];
    }

    private static function construirHtml(array $d, string $vista): string
    {
        $nivel = ob_get_level();
        ob_start();
        try {
            View::render($vista, $d + ['pageTitle' => $d['titulo']], 'public');
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $nivel) ob_end_clean();
            throw $e;
        }
    }

    /** Una página que no depende de nada: ni layout, ni base, ni hojas de estilo. */
    public static function paginaMinima(array $d): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Los estilos van en un <style> con el nonce del pedido (el CSP no admite style="…") y no en una hoja:
        // esta página tiene que verse bien aunque no se pueda cargar nada más.
        $estilos = 'body{margin:0;min-height:100vh;display:grid;place-items:center;text-align:center;'
                 . 'background:#07111f;color:#eaf4ff;font-family:system-ui,sans-serif}'
                 . 'main{padding:1.5rem}p{color:#a8b8cc}'
                 . '.tipo{color:#6bc5ff;letter-spacing:.16em;text-transform:uppercase;font-weight:800;font-size:.8rem}'
                 . 'a{color:#6bc5ff}';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
             . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
             . '<title>' . $e($d['titulo']) . '</title>'
             . '<style nonce="' . $e(Csp::nonce()) . '">' . $estilos . '</style></head>'
             . '<body><main><p class="tipo">Error ' . (int) $d['estado'] . '</p>'
             . '<h1>' . $e($d['titulo']) . '</h1><p>' . $e($d['mensaje']) . '</p>'
             . '<p>Código de referencia: <strong>' . $e($d['id']) . '</strong></p>'
             . '<p><a href="/">Ir al inicio</a></p></main></body></html>';
    }

    // ─── Los dos ganchos de PHP ─────────────────────────────────────────────

    /** set_exception_handler(): toda excepción que ningún controlador atrapó. */
    public static function alExcepcion(Throwable $e): void
    {
        if (self::$respondiendo) {
            error_log('[FlexArena] ERROR dentro del manejador de errores: ' . $e->getMessage());
            return;
        }
        self::$respondiendo = true;

        try {
            self::enviar(self::responder($e, self::$debug, self::esPeticionApi()));
        } catch (Throwable $falla) {
            error_log('[FlexArena] ERROR no se pudo responder a una excepción: ' . $falla->getMessage());
            if (!headers_sent()) http_response_code(500);
            echo 'Error interno.';
        }
    }

    /**
     * register_shutdown_function(): los errores fatales de PHP (memoria agotada, error de
     * sintaxis en un archivo) no son excepciones y no pasan por alExcepcion(). PHP ya los
     * registró; acá se les pone la página y un código que una el log con lo que vio la persona.
     * En desarrollo PHP ya los mostró en pantalla.
     */
    public static function alApagar(): void
    {
        $ultimo = error_get_last();
        if ($ultimo === null || self::$respondiendo || self::$debug) return;
        if (!($ultimo['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) return;

        self::$respondiendo = true;
        $id = bin2hex(random_bytes(4));
        error_log(sprintf('[FlexArena] FATAL %s %s en %s:%d',
            $id, $ultimo['message'], self::rutaCorta((string) $ultimo['file']), $ultimo['line']));

        $d = [
            'estado' => 500, 'codigo' => 'error_interno', 'titulo' => 'Algo salió mal',
            'mensaje' => self::MENSAJE_GENERICO, 'id' => $id, 'detalle' => null,
        ];
        if (self::esPeticionApi()) {
            $r = [
                'estado' => 500, 'tipo' => 'application/json; charset=utf-8', 'id' => $id,
                'cuerpo' => Respuesta::codificar(Respuesta::cuerpoError($d['codigo'], $d['mensaje'], ['id' => $id])),
            ];
        } else {
            $r = ['estado' => 500, 'tipo' => 'text/html; charset=utf-8', 'cuerpo' => self::paginaMinima($d), 'id' => $id];
        }
        self::enviar($r);
    }

    /** @param array{estado:int, tipo:string, cuerpo:string, ubicacion?:string} $r */
    private static function enviar(array $r): void
    {
        while (ob_get_level() > 0) ob_end_clean();     // lo que la página alcanzó a escribir no sirve
        if (!headers_sent()) {
            http_response_code($r['estado']);
            header('Content-Type: ' . $r['tipo']);
            header('Cache-Control: no-store');
            if (!empty($r['ubicacion'])) header('Location: ' . $r['ubicacion']);
        }
        echo $r['cuerpo'];
    }
}
