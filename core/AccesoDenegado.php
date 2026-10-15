<?php
declare(strict_types=1);

/**
 * La persona intentó algo que su cuenta no puede hacer: otro rol, un permiso que su rol no tiene,
 * un formulario con el token CSRF vencido. Su mensaje se muestra tal cual en la página 403.
 *
 * Antes cada compuerta (Auth::requireRole, BaseController::requirePermiso, Csrf::checkOrFail)
 * dibujaba la página 403 y hacía `exit` ahí mismo. Dos problemas:
 *
 *   - No se podía probar: un `exit` en medio de un controlador mata el proceso del test, así que
 *     las pruebas de autorización solo podían mirar el código, no ejecutarlo. Las compuertas
 *     pasaron años sin una prueba que las atravesara.
 *   - No dejaba rastro: un acceso denegado es justo lo que conviene poder buscar en el registro.
 *
 * Ahora se lanza esta excepción y la atiende ManejadorErrores: página 403 (o JSON `sin_permiso`
 * si el pedido es de la API) y una línea de registro con quién intentó qué.
 *
 * Hereda de Exception y NO de RuntimeException a propósito: los controladores atrapan
 * RuntimeException alrededor de las llamadas a los servicios, y una compuerta no debe poder
 * quedar tragada ahí como si fuera un aviso más.
 */
final class AccesoDenegado extends Exception
{
}
