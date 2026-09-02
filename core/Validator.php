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

    /** Lanza RuntimeException con el primer error si la contraseña no cumple. */
    public static function assertPassword(string $password, string $confirmacion = null): void
    {
        $errores = self::passwordErrors($password);
        if ($confirmacion !== null && $password !== $confirmacion) {
            $errores[] = 'Las contraseñas no coinciden.';
        }
        if ($errores) {
            throw new RuntimeException('Contraseña insegura: ' . $errores[0]);
        }
    }

    public static function email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
