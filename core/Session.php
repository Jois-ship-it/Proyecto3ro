<?php
declare(strict_types=1);

class Session
{
    /**
     * Cuánto puede pasar sin actividad antes de cerrar una sesión iniciada. Una hora: un
     * organizador que carga resultados entre partido y partido no tiene que volver a entrar
     * a cada rato, pero una pestaña olvidada en una PC compartida no queda abierta para siempre.
     */
    public const INACTIVIDAD_SEGUNDOS = 3600;

    /** Cada cuánto se cambia el identificador de una sesión iniciada (contra el robo de la cookie). */
    public const REGENERAR_CADA_SEGUNDOS = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        if (headers_sent()) return;   // consola o tests: ya se escribió salida y no se pueden mandar cookies

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '7200');

        // Cookie Secure cuando la conexión es HTTPS — incluye el caso detrás
        // de un proxy/túnel (Cloudflare) que reenvía vía X-Forwarded-Proto.
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
        self::controlarVida(time());
    }

    /**
     * En qué estado está la sesión: `anonima` (nadie inició sesión), `vigente`, `regenerar` (toca
     * cambiar el identificador) o `vencida` (pasó demasiado tiempo sin actividad).
     *
     * Es una función pura sobre el contenido de la sesión y la hora, así se prueba sin sesiones
     * reales. Antes ninguna de las dos reglas se aplicaba, aunque la documentación decía que la
     * sesión se regeneraba cada 30 minutos.
     */
    public static function evaluarVida(array $sesion, int $ahora): string
    {
        if (!isset($sesion['user_id'])) return 'anonima';

        $ultima = (int) ($sesion['_ultima_actividad'] ?? $ahora);
        if ($ahora - $ultima > self::INACTIVIDAD_SEGUNDOS) return 'vencida';

        $regenerada = (int) ($sesion['_last_regenerated'] ?? 0);
        return $ahora - $regenerada > self::REGENERAR_CADA_SEGUNDOS ? 'regenerar' : 'vigente';
    }

    private static function controlarVida(int $ahora): void
    {
        switch (self::evaluarVida($_SESSION, $ahora)) {
            case 'vencida':
                self::destroy();
                self::start();
                self::flash('warning', 'Tu sesión se cerró por inactividad. Iniciá sesión de nuevo.');
                return;
            case 'regenerar':
                self::regenerate();
                break;
        }
        if (isset($_SESSION['user_id'])) {
            $_SESSION['_ultima_actividad'] = $ahora;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Almacena mensaje para la siguiente solicitud */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /** Lee y elimina el flash */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    }

    /**
     * Cierra lo que había y deja una sesión NUEVA y vacía (otro identificador): quien se va llega al login con una sesión limpia, en
     * la que cabe el aviso de despedida. A diferencia de destroy() + start() manda UNA sola cookie (la nueva) y no dos en la misma
     * respuesta (una vencida y otra nueva): hay clientes —el navegador de Cypress, por ejemplo— que las aplican en cualquier orden y
     * se quedan sin la nueva, y el aviso se pierde.
     */
    public static function reiniciar(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
