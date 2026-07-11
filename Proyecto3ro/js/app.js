/* ============================================================================
 * app.js — Núcleo de la capa JavaScript de los mockups FlexArena / SGDM
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Definir el espacio de nombres global `FA` (FlexArena) para evitar
 *     contaminar `window` y permitir que todos los módulos compartan utilidades.
 *   - Exponer helpers reutilizables (selección de DOM, eventos, formateo).
 *   - Implementar el sistema de mensajes flash / toast dinámicos.
 *   - Orquestar la inicialización de los demás módulos en DOMContentLoaded.
 *
 * Debe cargarse SIEMPRE primero (antes que el resto de los módulos), porque
 * todos dependen del objeto `window.FA` que se crea aquí.
 *
 * Vanilla JS ES6+. Sin dependencias externas. Sin jQuery.
 * ==========================================================================*/

window.FA = (function () {
  'use strict';

  /* ----- Helpers de selección de DOM -------------------------------------- */

  /** Devuelve el primer elemento que coincide con el selector. */
  const $ = (selector, context = document) => context.querySelector(selector);

  /** Devuelve un array (no NodeList) con todos los elementos coincidentes. */
  const $$ = (selector, context = document) =>
    Array.from(context.querySelectorAll(selector));

  /** Atajo seguro para addEventListener (ignora elementos nulos). */
  const on = (element, event, handler, options) => {
    if (element) element.addEventListener(event, handler, options);
  };

  /* ----- Cola de inicialización ------------------------------------------- */

  const initQueue = [];

  /**
   * Registra una función para ejecutarse cuando el DOM esté listo.
   * Cada módulo usa FA.ready(fn) en lugar de su propio listener, de modo que
   * exista un único punto de arranque y un orden de inicialización predecible.
   */
  const ready = (fn) => {
    if (typeof fn === 'function') initQueue.push(fn);
  };

  function runInit() {
    initQueue.forEach((fn) => {
      try {
        fn();
      } catch (err) {
        // Un módulo que falle no debe romper al resto.
        console.error('[FA] Error inicializando un módulo:', err);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runInit);
  } else {
    // El script se cargó después de DOMContentLoaded: ejecutar en microtask.
    Promise.resolve().then(runInit);
  }

  /* ----- Sistema de mensajes flash / toast -------------------------------- */

  let flashContainer = null;

  /** Crea (una sola vez) el contenedor flotante donde se apilan los toasts. */
  function ensureFlashContainer() {
    if (flashContainer) return flashContainer;
    flashContainer = document.createElement('div');
    flashContainer.className = 'flash-stack';
    flashContainer.setAttribute('aria-live', 'polite');
    flashContainer.setAttribute('role', 'status');
    document.body.appendChild(flashContainer);
    return flashContainer;
  }

  /**
   * Muestra un mensaje flash dinámico (toast) que se autodescarta.
   * @param {string} message  Texto a mostrar.
   * @param {string} type     'info' | 'success' | 'warning' | 'danger'.
   * @param {number} timeout  Milisegundos antes de desaparecer (0 = manual).
   */
  function flash(message, type = 'info', timeout = 4200) {
    const container = ensureFlashContainer();

    const icons = { info: 'ℹ', success: '✓', warning: '⚠', danger: '✕' };
    const toast = document.createElement('div');
    toast.className = `flash-toast flash-${type}`;
    toast.innerHTML =
      `<span class="flash-icon">${icons[type] || icons.info}</span>` +
      `<span class="flash-text"></span>` +
      `<button class="flash-close" aria-label="Cerrar">×</button>`;
    // Insertamos el texto vía textContent para evitar inyección de HTML.
    toast.querySelector('.flash-text').textContent = message;

    container.appendChild(toast);
    // Forzamos reflow para que la transición de entrada se anime.
    requestAnimationFrame(() => toast.classList.add('show'));

    const dismiss = () => {
      toast.classList.remove('show');
      on(toast, 'transitionend', () => toast.remove());
      // Respaldo por si no hay transición.
      setTimeout(() => toast.remove(), 350);
    };

    on(toast.querySelector('.flash-close'), 'click', dismiss);
    if (timeout > 0) setTimeout(dismiss, timeout);

    return { dismiss };
  }

  /* ----- Utilidades varias ------------------------------------------------- */

  /** Normaliza texto para búsquedas: minúsculas y sin acentos. */
  function normalize(text) {
    return (text || '')
      .toString()
      .toLowerCase()
      .normalize('NFD') // descompone letras acentuadas en base + diacrítico
      .replace(/[̀-ͯ]/g, ''); // elimina marcas diacríticas combinantes
  }

  /** Debounce: retrasa la ejecución hasta que pasen `wait` ms sin llamadas. */
  function debounce(fn, wait = 200) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  /* ----- API pública del namespace ---------------------------------------- */
  return { $, $$, on, ready, flash, normalize, debounce };
})();
