<?php
declare(strict_types=1);

/**
 * El formato único de las respuestas JSON del sistema (la API y las llamadas con fetch).
 *
 *   éxito  {"ok": true,  "datos": …, "meta": {…}}      «meta» solo si hay algo que decir
 *   error  {"ok": false, "error": {"codigo": "…", "mensaje": "…", "id": "…"}}
 *
 * El cliente decide con `ok` y con el código HTTP; `codigo` es estable y legible por un
 * programa («no_encontrado»), `mensaje` es para una persona. Los errores técnicos traen
 * además `id`, el código de referencia con el que se busca el detalle en el registro.
 *
 * Las funciones cuerpo*() y codificar() no envían nada: sirven para probar el formato sin
 * cabeceras ni exit.
 */
final class Respuesta
{
    /** Código estable para cada estado HTTP que el sistema devuelve. */
    private const CODIGOS = [
        400 => 'peticion_invalida',
        401 => 'no_autenticado',
        403 => 'sin_permiso',
        404 => 'no_encontrado',
        405 => 'metodo_no_permitido',
        422 => 'datos_invalidos',
        429 => 'demasiados_intentos',
        500 => 'error_interno',
    ];

    public static function codigoPara(int $estado): string
    {
        return self::CODIGOS[$estado] ?? ($estado >= 500 ? 'error_interno' : 'peticion_invalida');
    }

    /** @return array<string, mixed> */
    public static function cuerpoOk(mixed $datos = null, array $meta = []): array
    {
        $cuerpo = ['ok' => true, 'datos' => $datos];
        if ($meta !== []) $cuerpo['meta'] = $meta;
        return $cuerpo;
    }

    /** @return array<string, mixed> */
    public static function cuerpoError(string $codigo, string $mensaje, array $extra = []): array
    {
        return ['ok' => false, 'error' => ['codigo' => $codigo, 'mensaje' => $mensaje] + $extra];
    }

    /**
     * El JSON de un cuerpo. Un texto con bytes inválidos se sustituye en vez de hacer
     * fallar toda la respuesta: una API que se cae por un nombre mal codificado es peor
     * que una que muestra un carácter de reemplazo.
     */
    public static function codificar(array $cuerpo): string
    {
        $json = json_encode(
            $cuerpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $json !== false
            ? $json
            : '{"ok":false,"error":{"codigo":"error_interno","mensaje":"No se pudo armar la respuesta."}}';
    }

    public static function ok(mixed $datos = null, array $meta = [], int $estado = 200): never
    {
        self::enviar(self::cuerpoOk($datos, $meta), $estado);
    }

    public static function error(string $codigo, string $mensaje, int $estado = 400, array $extra = []): never
    {
        self::enviar(self::cuerpoError($codigo, $mensaje, $extra), $estado);
    }

    public static function enviar(array $cuerpo, int $estado = 200): never
    {
        while (ob_get_level() > 0) ob_end_clean();     // nada de HTML mezclado con el JSON
        if (!headers_sent()) {
            http_response_code($estado);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo self::codificar($cuerpo);
        exit;
    }
}
