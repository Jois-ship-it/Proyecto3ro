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

    /** Valida el token del POST o devuelve 403 */
    public static function checkOrFail(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!self::validate($token)) {
            http_response_code(403);
            View::render('shared/403', [], 'public');
            exit;
        }
    }
}
