<?php
declare(strict_types=1);

/**
 * Decide si una URL es un destino de redirección seguro.
 *
 * El problema que resuelve: varios controladores redirigen a un valor que viene
 * del pedido —la cabecera `Referer`, un campo `return` del formulario— para
 * devolver al usuario a la pantalla de la que vino. Como esos valores los
 * controla quien manda el pedido, sin filtrar convierten al sistema en un
 * trampolín: un enlace preparado puede hacer que FlexArena rebote al visitante
 * hacia un sitio ajeno, que es la base de una estafa de phishing («me lo mandó
 * la página del torneo, tiene que ser legítimo»).
 *
 * La regla es una sola: solo se redirige adentro del propio sitio. Todo lo demás
 * cae en el destino de reserva que indique quien llama.
 */
final class Url
{
    private function __construct() {}

    /**
     * La ruta interna equivalente a $url, o null si apunta afuera del sitio.
     *
     * Devuelve siempre una ruta relativa (`/admin/torneos`), incluso cuando le
     * entra una URL absoluta de este mismo host: redirigir por ruta evita
     * arrastrar el esquema y el puerto, que pueden no coincidir con los que el
     * visitante está usando (por ejemplo detrás de un proxy).
     */
    public static function rutaInterna(?string $url): ?string
    {
        if (!is_string($url)) return null;

        $url = trim($url);
        if ($url === '') return null;

        // Caracteres de control: `header()` ya rechaza los saltos de línea para
        // evitar que se inyecten cabeceras, pero no hay ninguna razón para
        // dejarlos llegar hasta ahí.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) return null;

        if ($url[0] === '/') {
            // «//otro-sitio.com» y «/\otro-sitio.com» son URLs de protocolo
            // relativo: el navegador las resuelve contra OTRO dominio aunque
            // empiecen con barra. Es el disfraz clásico de un redirect abierto.
            if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) return null;
            return $url;
        }

        // URL absoluta: se acepta solo si el host es el nuestro.
        $partes = parse_url($url);
        if (!is_array($partes) || !isset($partes['host'])) return null;

        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        if (!in_array($esquema, ['http', 'https'], true)) return null;

        if (!self::esHostPropio((string) $partes['host'])) return null;

        $ruta = $partes['path'] ?? '/';
        if (isset($partes['query']))    $ruta .= '?' . $partes['query'];
        if (isset($partes['fragment'])) $ruta .= '#' . $partes['fragment'];

        return $ruta;
    }

    /** $candidata si es un destino interno; si no, $fallback. */
    public static function interna(?string $candidata, string $fallback): string
    {
        return self::rutaInterna($candidata) ?? $fallback;
    }

    /**
     * ¿El host es el de este sitio?
     *
     * Se comparan el host del pedido en curso y el de APP_URL. El primero cubre
     * el caso normal (el visitante vuelve a donde estaba) y el segundo sirve
     * cuando no hay pedido HTTP, como en los tests.
     */
    private static function esHostPropio(string $host): bool
    {
        $host = self::sinPuerto($host);
        if ($host === '') return false;

        $propios = [];

        if (!empty($_SERVER['HTTP_HOST'])) {
            $propios[] = self::sinPuerto((string) $_SERVER['HTTP_HOST']);
        }
        if (defined('APP_URL')) {
            $deConfig = parse_url((string) APP_URL, PHP_URL_HOST);
            if (is_string($deConfig) && $deConfig !== '') {
                $propios[] = self::sinPuerto($deConfig);
            }
        }

        return in_array($host, $propios, true);
    }

    /** Baja el host a minúsculas y le saca el puerto (incluye el formato IPv6). */
    private static function sinPuerto(string $host): string
    {
        $host = strtolower(trim($host));

        // [::1]:8080 → ::1
        if (str_starts_with($host, '[')) {
            $cierre = strpos($host, ']');
            return $cierre === false ? $host : substr($host, 1, $cierre - 1);
        }

        return (string) preg_replace('/:\d+$/', '', $host);
    }
}
