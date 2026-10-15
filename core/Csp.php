<?php
declare(strict_types=1);

/**
 * La política de seguridad de contenido (CSP) de las páginas.
 *
 * Antes la fijaba Apache (public/.htaccess) con `'unsafe-inline'` en scripts y en estilos: el navegador
 * ejecutaba cualquier `<script>` o `onclick="…"` que apareciera en la página, y por eso un nombre con
 * código inyectado en un atributo se ejecutaba. Ahora:
 *
 *   - script-src 'self': solo los archivos de este sitio. No hay scripts ni manejadores en línea.
 *   - style-src 'self' 'nonce-…': las hojas de este sitio y UN bloque `<style nonce="…">` que arma la
 *     página con las posiciones calculadas (el bracket, las barras de porcentaje, los avatares). El
 *     nonce cambia en cada pedido, así que nadie puede escribir de antemano un bloque que lo cumpla.
 *     Los atributos `style="…"` quedan prohibidos.
 *
 * La manda PHP y no Apache porque el nonce es de cada respuesta. Ver docs/documentacion_seguridad.md.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** El nonce de este pedido (se genera una vez y es el mismo en la cabecera y en el <style>). */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function politica(): string
    {
        return "default-src 'self'; "
             . "img-src 'self' data:; "
             . "style-src 'self' 'nonce-" . self::nonce() . "'; "
             . "script-src 'self'; "
             . "font-src 'self'; "
             . "object-src 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self'; "
             . "frame-ancestors 'self'";
    }

    /** Manda la cabecera. Se llama una vez, al empezar el pedido, antes de escribir nada. */
    public static function enviar(): void
    {
        if (!headers_sent()) {
            header('Content-Security-Policy: ' . self::politica());
        }
    }

    /** Descarta el nonce (para los tests, que simulan varios pedidos en un mismo proceso). */
    public static function olvidar(): void
    {
        self::$nonce = null;
    }
}
