// Se carga antes de cada especificación.
//
// A propósito NO se silencian las excepciones de la página (`uncaught:exception`): Cypress hace fallar la prueba ante un error de
// JavaScript no atrapado, y eso es justo lo que se quiere enterar (un script roto, una violación de la política de seguridad que
// apague un menú). Si una página tira una, se arregla la página.
import './commands';
