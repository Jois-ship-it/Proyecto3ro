<?php
declare(strict_types=1);

class Csrf
{
    private const TOKEN_KEY = 'csrf_token';

    public static function generate(): string
    {
        if (!Session::has(self::TOKEN_KEY)) {
            Session::set(self::TOKEN_KEY, bin2hex(random_bytes(32)));
        }
        return (string) Session::get(self::TOKEN_KEY);
    }

    public static function validate(string $token): bool
    {
        $stored = Session::get(self::TOKEN_KEY, '');
        if (!$stored) return false;
        return hash_equals((string)$stored, $token);
    }

    /** Campo HTML hidden listo para insertar en formularios */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::generate() . '">';
    }

    /** Nombre de la cabecera con la que un fetch manda el token (X-CSRF-Token). */
    public const CABECERA = 'HTTP_X_CSRF_TOKEN';

    /**
     * El token que trae el pedido: el de la cabecera X-CSRF-Token (lo que manda un fetch) o, si no
     * hay cabecera, el campo oculto `csrf_token` del formulario. Si la cabecera viene, es la que vale:
     * una cabecera equivocada no se «salva» con un campo correcto.
     */
    public static function tokenDelPedido(?array $servidor = null, ?array $post = null): string
    {
        $servidor ??= $_SERVER;
        $post     ??= $_POST;

        $cabecera = $servidor[self::CABECERA] ?? '';
        if (is_string($cabecera) && $cabecera !== '') {
            return $cabecera;
        }
        $campo = $post[self::TOKEN_KEY] ?? '';
        return is_string($campo) ? $campo : '';
    }

    /** Valida el token del pedido (cabecera o campo del formulario) o lanza AccesoDenegado (403). */
    public static function checkOrFail(): void
    {
        $token = self::tokenDelPedido();
        if ($token === '' || !self::validate($token)) {
            throw new AccesoDenegado('El formulario venció o no es válido. Volvé a cargar la página e intentá de nuevo.');
        }
    }
}
