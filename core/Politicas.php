<?php
declare(strict_types=1);

/**
 * La Política de Privacidad y los Términos y Condiciones vigentes (app/views/public/privacidad.php y terminos.php).
 *
 * Quien se registra tiene que aceptarlos, y la auditoría guarda QUÉ versión aceptó (ver RegistroService): si el
 * texto cambia, se sube VERSION y VIGENTE_DESDE acá, y así queda claro quién aceptó cuál.
 */
final class Politicas
{
    /** La versión del texto. Cambiarla cada vez que cambie lo que dicen las dos páginas. */
    public const VERSION = '1.0';

    /** Desde cuándo rige esa versión, como se muestra en las páginas. */
    public const VIGENTE_DESDE = '25 de octubre de 2026';

    /** Quién es el responsable del tratamiento de los datos. */
    public const OPERADOR = 'Guemanelli Tech';

    /** El nombre del campo (la casilla del formulario de registro) con el que se acepta. */
    public const CAMPO_ACEPTACION = 'acepto_politicas';

    /** El mensaje cuando se intenta registrar sin aceptar. */
    public const MENSAJE_FALTA_ACEPTAR = 'Para crear la cuenta tenés que aceptar la Política de Privacidad y los Términos y Condiciones.';

    /** ¿Aceptó? Vale lo que manda la casilla marcada del formulario («1», «on» o true); vacío, no. */
    public static function aceptada(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        return in_array(is_string($valor) ? strtolower(trim($valor)) : $valor, ['1', 'on', 'true', 'si', 'sí', 1], true);
    }
}
