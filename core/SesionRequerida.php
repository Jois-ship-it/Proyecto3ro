<?php
declare(strict_types=1);

/**
 * La acción pide una sesión iniciada y no la hay (o la cuenta dejó de estar activa).
 *
 * Igual que AccesoDenegado, reemplaza a un `header('Location: /login'); exit;` metido en medio
 * del controlador, que no se podía probar. ManejadorErrores la convierte en una redirección a
 * /login para una página —con el aviso «Debés iniciar sesión…» que Auth::requireLogin() deja
 * en la sesión— o en un 401 con JSON para la API y los fetch.
 *
 * Hereda de Exception y no de RuntimeException por la misma razón que AccesoDenegado: los
 * controladores atrapan RuntimeException alrededor de los servicios.
 */
final class SesionRequerida extends Exception
{
}
