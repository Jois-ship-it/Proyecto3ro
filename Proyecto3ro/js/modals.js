/* ============================================================================
 * modals.js — Sistema de modales reutilizable
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Componente genérico para abrir y cerrar cualquier modal de forma
 *     declarativa, sin escribir JavaScript inline en el HTML.
 *   - Cierre por: botón, clic en el overlay, tecla Escape.
 *   - Gestión de foco y atributos ARIA.
 *
 * Marcado esperado:
 *   <button data-modal-open="idDelModal">Abrir</button>
 *   <div class="modal-overlay" id="idDelModal" role="dialog" aria-modal="true">
 *     <div class="modal-card">
 *       …
 *       <button data-modal-close>Cancelar</button>
 *     </div>
 *   </div>
 *
 * Expone API programática: FA.modal.open(id) / FA.modal.close(id).
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  let lastFocused = null; // elemento que tenía el foco antes de abrir

  /** Abre el modal indicado por id y mueve el foco a su primer campo. */
  function open(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    lastFocused = document.activeElement;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');

    // Enfoca el primer control interactivo dentro del modal.
    const focusable = modal.querySelector(
      'input, select, textarea, button:not([data-modal-close])'
    );
    if (focusable) focusable.focus();
  }

  /** Cierra el modal indicado y devuelve el foco al disparador. */
  function close(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (lastFocused) lastFocused.focus();
  }

  /** Cierra todos los modales abiertos (usado por Escape). */
  function closeAll() {
    FA.$$('.modal-overlay.open').forEach((m) => {
      m.classList.remove('open');
      m.setAttribute('aria-hidden', 'true');
    });
  }

  FA.ready(function initModals() {
    // Delegación: un único listener gestiona todos los disparadores y cierres.
    FA.on(document, 'click', (e) => {
      const opener = e.target.closest('[data-modal-open]');
      if (opener) {
        e.preventDefault();
        open(opener.getAttribute('data-modal-open'));
        return;
      }

      const closer = e.target.closest('[data-modal-close]');
      if (closer) {
        e.preventDefault();
        const modal = closer.closest('.modal-overlay');
        if (modal) close(modal.id);
        return;
      }

      // Clic directo sobre el overlay (fuera de la tarjeta) cierra.
      if (e.target.classList.contains('modal-overlay')) {
        close(e.target.id);
      }
    });

    // Escape cierra cualquier modal abierto.
    FA.on(document, 'keydown', (e) => {
      if (e.key === 'Escape') closeAll();
    });
  });

  // API pública para otros módulos (p. ej. resultados.js, confirm.js).
  FA.modal = { open, close, closeAll };
})(window.FA);
