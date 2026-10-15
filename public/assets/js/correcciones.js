/* FlexArena — correcciones.js
 *
 * La lista de solicitudes de corrección: el botón «Rechazar» de cada fila (data-rechazar, con el id de la
 * solicitud) apunta el formulario del diálogo a la solicitud elegida. El diálogo lo abre modales.js
 * (data-abrir-modal) y se cierra con su botón Cancelar o con un clic en el fondo.
 */
'use strict';

(() => {
  const formulario = document.getElementById('formRechazar');
  if (!formulario) return;

  document.querySelectorAll('[data-rechazar]').forEach((boton) => {
    boton.addEventListener('click', () => {
      formulario.action = '/admin/correcciones/' + encodeURIComponent(boton.dataset.id) + '/rechazar';
    });
  });
})();
