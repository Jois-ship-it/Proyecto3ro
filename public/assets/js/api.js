/* FlexArena — api.js
 *
 * Cliente mínimo para los fetch del panel. Hace tres cosas por la página:
 *   - Manda X-Requested-With: el servidor reconoce un pedido que espera JSON y responde JSON
 *     (también ante un 403 o un 401), en lugar de redirigir a una página.
 *   - En los POST manda el token CSRF en la cabecera X-CSRF-Token, leído de <meta name="csrf-token">.
 *     Una cabecera personalizada no la puede mandar otro sitio sin permiso del navegador (CORS).
 *   - Convierte cualquier falla en un ApiError con el estado HTTP, un código estable
 *     («datos_invalidos», «sin_permiso», «no_autenticado»…) y el mensaje para la persona.
 *
 * El formato de las respuestas es el de core/Respuesta.php:
 *   {"ok": true,  "datos": …, "meta": …}
 *   {"ok": false, "error": {"codigo": "…", "mensaje": "…", "id": "…"}}
 */
'use strict';

class ApiError extends Error {
  constructor(estado, codigo, mensaje, id = null) {
    super(mensaje);          // el mensaje para la persona queda en .message
    this.name = 'ApiError';
    this.estado = estado;     // código HTTP (0 si no hubo respuesta)
    this.codigo = codigo;     // identificador estable, legible por un programa
    this.id = id;             // código de referencia de un error técnico, si lo hay
  }
}

const FlexApi = (() => {
  const token = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  };

  async function pedir(url, { metodo = 'GET', cuerpo = null, signal = null } = {}) {
    const cabeceras = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const opciones = { method: metodo, headers: cabeceras, credentials: 'same-origin' };
    if (signal) opciones.signal = signal;                 // para cancelar un pedido que ya no hace falta (búsqueda en vivo)
    if (metodo !== 'GET' && metodo !== 'HEAD') {
      cabeceras['X-CSRF-Token'] = token();
      if (cuerpo !== null) opciones.body = cuerpo;
    }

    let respuesta;
    try {
      respuesta = await fetch(url, opciones);
    } catch (e) {
      // Un pedido cancelado a propósito no es una falla de la red: quien lo canceló lo reconoce por el nombre y lo ignora.
      if (e && e.name === 'AbortError') throw e;
      throw new ApiError(0, 'sin_conexion', 'No se pudo conectar con el servidor. Revisá tu conexión e intentá de nuevo.');
    }

    let json = null;
    try { json = await respuesta.json(); } catch (_) { /* no era JSON */ }
    if (json === null || typeof json.ok !== 'boolean') {
      throw new ApiError(respuesta.status, 'respuesta_invalida', 'El servidor respondió algo inesperado. Recargá la página e intentá de nuevo.');
    }
    if (!json.ok) {
      const e = json.error || {};
      throw new ApiError(respuesta.status, e.codigo || 'error', e.mensaje || 'Ocurrió un error.', e.id || null);
    }
    return json;
  }

  return {
    ApiError,
    /** GET que devuelve {ok, datos, meta}. Con `{signal}` (AbortController) el pedido se puede cancelar: lanza un AbortError. */
    get: (url, opciones = {}) => pedir(url, { signal: opciones.signal || null }),
    /** POST de los campos de un <form> (como lo mandaría el formulario común). */
    enviarFormulario: (url, form) => pedir(url, { metodo: 'POST', cuerpo: new URLSearchParams(new FormData(form)) }),
  };
})();
