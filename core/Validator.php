<?php
declare(strict_types=1);

/**
 * Validaciones reutilizables (servidor = autoritativo).
 * El frontend replica estas reglas para feedback inmediato,
 * pero la validación real ocurre acá.
 */
class Validator
{
    public const PW_MIN = 8;

    /**
     * Devuelve la lista de errores de una contraseña según la política.
     * Array vacío = contraseña válida.
     */
    public static function passwordErrors(string $password): array
    {
        $errores = [];
        if (mb_strlen($password) < self::PW_MIN) {
            $errores[] = 'Debe tener al menos ' . self::PW_MIN . ' caracteres.';
        }
        if (!preg_match('/[A-ZÁÉÍÓÚÑ]/u', $password)) {
            $errores[] = 'Debe incluir al menos una letra mayúscula.';
        }
        if (!preg_match('/[a-záéíóúñ]/u', $password)) {
            $errores[] = 'Debe incluir al menos una letra minúscula.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errores[] = 'Debe incluir al menos un número.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errores[] = 'Debe incluir al menos un símbolo especial (!@#$%&*…).';
        }
        return $errores;
    }

    /** Lanza ErrorNegocio con el primer error si la contraseña no cumple. */
    public static function assertPassword(string $password, string $confirmacion = null): void
    {
        $errores = self::passwordErrors($password);
        if ($confirmacion !== null && $password !== $confirmacion) {
            $errores[] = 'Las contraseñas no coinciden.';
        }
        if ($errores) {
            throw new ErrorNegocio('Contraseña insegura: ' . $errores[0]);
        }
    }

    /**
     * Lanza ErrorNegocio si algún texto es más largo que la columna donde se va a guardar.
     *
     * Sin esto la base lo rechazaba con un error técnico («Data too long for column 'nick'») y
     * la persona se quedaba sin saber qué corregir. El largo se cuenta en caracteres, como
     * VARCHAR(n) en utf8mb4.
     *
     * @param array<string, array{0: mixed, 1: int}> $campos etiqueta => [valor, máximo]
     */
    public static function assertLargos(array $campos): void
    {
        foreach ($campos as $etiqueta => [$valor, $maximo]) {
            if (mb_strlen(trim((string) $valor)) > $maximo) {
                throw new ErrorNegocio("{$etiqueta} no puede superar los {$maximo} caracteres.");
            }
        }
    }

    public static function email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
