<?php
declare(strict_types=1);

/**
 * Un error cuyo mensaje está escrito PARA LA PERSONA que usa el sistema.
 *
 * «Ya existe un equipo con ese nombre», «El torneo no está abierto para inscripciones»,
 * «Contraseña insegura: …». Los servicios lanzan esto cuando una regla de negocio no se
 * cumple, y el controlador muestra el mensaje tal cual.
 *
 * Todo lo demás —un error de la base de datos, un tipo equivocado, un archivo que no se
 * puede escribir— NO es un ErrorNegocio: su mensaje habla de tablas, rutas y consultas, y no
 * es para mostrarse. Esos van al registro con un código de referencia, y la persona ve un
 * aviso genérico con ese código (ver ManejadorErrores y BaseController::mensajeDe()).
 *
 * Antes los servicios lanzaban RuntimeException a secas, y los controladores mostraban el
 * mensaje de cualquier RuntimeException. PDOException también lo es: un nick de 200
 * caracteres terminaba en pantalla como «SQLSTATE[22001]: Data too long for column…».
 *
 * Hereda de RuntimeException a propósito: un `catch (RuntimeException $e)` sigue atrapándolo.
 */
class ErrorNegocio extends RuntimeException
{
}
