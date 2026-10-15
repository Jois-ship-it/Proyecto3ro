/* FlexArena — resultados.js
 *
 * Los formularios de resultados (cargar, corregir, solicitar corrección, programar) se mandan por
 * fetch, con el token CSRF en la cabecera. Un formulario con `data-api` queda así:
 *
 *   - Mientras se guarda, el botón se desactiva y dice «Guardando…».
 *   - Si el servidor lo rechaza (marcador inválido, ronda cerrada, sin permiso…), el motivo aparece
 *     DENTRO del diálogo, que sigue abierto con lo que la persona escribió. Antes la página se
 *     recargaba y el motivo aparecía arriba, detrás del diálogo.
 *   - Si sale bien, se recarga la página: el servidor dejó el aviso de éxito (y, si el campeón
 *     cambió, ese aviso) listo para mostrarse, y la tabla o el bracket aparecen al día.
 *
 * Sin JavaScript (o si api.js no cargó) el formulario se manda como siempre: no se pierde nada.
 */
'use strict';

(() => {
  if (typeof FlexApi === 'undefined') return;

  function mostrarError(contenedor, mensaje) {
    if (!contenedor) return;
    contenedor.textContent = mensaje;
    contenedor.hidden = !mensaje;
  }

  document.querySelectorAll('form[data-api]').forEach((form) => {
    form.addEventListener('submit', async (evento) => {
      evento.preventDefault();
      if (!form.reportValidity()) return;

      const boton = form.querySelector('button[type="submit"]');
      const textoOriginal = boton ? boton.textContent : '';
      const error = form.querySelector('[data-api-error]');
      mostrarError(error, '');
      if (boton) { boton.disabled = true; boton.textContent = 'Guardando…'; }

      try {
        await FlexApi.enviarFormulario(form.action, form);
        window.location.reload();
      } catch (e) {
        if (e.estado === 401) { window.location.href = '/login'; return; }
        mostrarError(error, e.message);
        if (boton) { boton.disabled = false; boton.textContent = textoOriginal; }
      }
    });
  });
})();
