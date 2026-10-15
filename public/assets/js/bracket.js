/* FlexArena — bracket.js
 *
 * Menú de acciones de las tarjetas del bracket (Programar / Cargar resultado), para quien puede editar.
 * Cada tarjeta `.bk-card.clickable` lleva su menú `.bk-action-menu`; al hacer clic (o Enter / Espacio) se
 * abre al lado de la tarjeta, y sus botones abren los diálogos de gestion-torneo.js.
 *
 * El menú se coloca con `position: fixed` y coordenadas calculadas en ese momento, para que ningún
 * contenedor con `overflow: hidden` lo recorte. Cerrar: clic fuera, Escape, scroll o cambio de tamaño.
 * Necesita modales.js y gestion-torneo.js, que se cargan antes.
 */
'use strict';

(() => {
  function cerrarTodos() {
    document.querySelectorAll('.bk-action-menu').forEach((menu) => {
      menu.style.display = 'none';
      menu.style.visibility = '';
      menu.style.position = 'absolute';
      menu.style.left = '';
      menu.style.top = '';
      menu.style.bottom = '';
      menu.removeAttribute('data-portal');
      const tarjeta = menu.closest('.bk-card');
      if (tarjeta) tarjeta.setAttribute('aria-expanded', 'false');
      menu.setAttribute('aria-hidden', 'true');
    });
  }

  /** Abre el menú de una tarjeta junto a ella, hacia arriba si abajo no entra. */
  function abrirMenu(tarjeta, menu) {
    // Se muestra oculto un instante para medir su tamaño natural.
    menu.style.display = 'block';
    menu.style.visibility = 'hidden';
    menu.style.position = 'absolute';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.top = '';
    menu.style.bottom = '';

    const alto = menu.offsetHeight;
    const ancho = menu.offsetWidth;
    const caja = tarjeta.getBoundingClientRect();
    const hayLugarAbajo = window.innerHeight - caja.bottom >= alto;
    const abreHaciaArriba = !hayLugarAbajo && caja.top > alto;

    menu.style.position = 'fixed';
    menu.style.top = abreHaciaArriba
      ? Math.max(6, caja.top - alto - 6) + 'px'
      : Math.min(window.innerHeight - alto - 6, caja.bottom + 6) + 'px';

    // Alineado al borde derecho de la tarjeta, sin salirse de la ventana.
    let izquierda = caja.right - 8 - ancho;
    if (izquierda < 8) izquierda = 8;
    if (izquierda + ancho > window.innerWidth - 8) izquierda = window.innerWidth - ancho - 8;
    menu.style.left = izquierda + 'px';

    menu.style.visibility = 'visible';
    menu.setAttribute('aria-hidden', 'false');
    tarjeta.setAttribute('aria-expanded', 'true');
    menu.dataset.portal = 'true';

    const primero = menu.querySelector('button');
    if (primero) primero.focus();
  }

  document.querySelectorAll('.bk-card.clickable').forEach((tarjeta) => {
    tarjeta.addEventListener('click', (evento) => {
      if (evento.target.closest('.bk-action-menu')) return;      // un clic dentro del menú no lo abre ni lo cierra
      const menu = tarjeta.querySelector('.bk-action-menu');
      if (!menu) return;
      const estabaAbierto = menu.style.display === 'block';
      cerrarTodos();
      if (!estabaAbierto) abrirMenu(tarjeta, menu);
    });
    tarjeta.addEventListener('keydown', (evento) => {
      if (evento.key === 'Enter' || evento.key === ' ') {
        evento.preventDefault();
        tarjeta.click();
      } else if (evento.key === 'Escape') {
        cerrarTodos();
      }
    });
  });

  ['scroll', 'resize'].forEach((nombre) => window.addEventListener(nombre, cerrarTodos));

  // Los botones del menú abren el diálogo que corresponda con los datos del partido.
  document.addEventListener('click', (evento) => {
    const boton = evento.target.closest('.bk-action-menu button');
    if (!boton) return;
    if (typeof FlexGestion !== 'undefined') {
      const tarjeta = boton.closest('.bk-card');      // al cerrar el diálogo, el foco vuelve a la tarjeta
      if (boton.dataset.action === 'cargar') FlexGestion.cargar(boton.dataset, tarjeta);
      if (boton.dataset.action === 'programar') FlexGestion.programar(boton.dataset, tarjeta);
    }
    cerrarTodos();
  });

  // Clic fuera de una tarjeta: se cierran los menús (en la fase de captura, como antes).
  document.addEventListener('click', (evento) => {
    if (evento.target.closest('.bk-card')) return;
    cerrarTodos();
  }, true);

  document.addEventListener('keydown', (evento) => { if (evento.key === 'Escape') cerrarTodos(); });

  // Los menús arrancan cerrados (evita verlos abiertos tras recargar).
  cerrarTodos();
})();
