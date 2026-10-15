/* FlexArena — modales.js
 *
 * Abrir y cerrar diálogos (los `.modal-fondo`) sin estilos ni manejadores escritos en el HTML:
 *
 *   <button data-abrir-modal="id">                    abre el diálogo con ese id
 *   <button data-cerrar-modal>                        cierra el diálogo que lo contiene
 *   <div class="modal-fondo" data-cierra-fuera ...>   además se cierra al hacer clic en el fondo
 *
 * Otros scripts abren uno por código con `FlexModales.abrir('id', origen)` (por ejemplo gestion-torneo.js,
 * después de completar sus campos). Un diálogo abierto lleva la clase `abierto`; el CSS decide cómo se ve.
 *
 * Teclado y lectores de pantalla (un diálogo es una ventana dentro de la página):
 *   - Al abrir, el foco pasa al primer campo (o al primer control) del diálogo.
 *   - Tab y Mayús+Tab dan la vuelta DENTRO del diálogo: no se puede ir a la página de atrás.
 *   - Escape lo cierra.
 *   - Al cerrar, el foco vuelve al control que lo abrió (o al `origen` que se haya dicho).
 * Su tarjeta lleva role="dialog", aria-modal="true" y aria-labelledby (ver las vistas).
 */
'use strict';

(() => {
  const ENFOCABLES = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /** El diálogo abierto y quién lo abrió (para devolverle el foco). Solo hay uno a la vez. */
  let actual = null;

  const visible = (e) => !e.hidden && (typeof e.getClientRects !== 'function' || e.getClientRects().length > 0);
  const enfocables = (modal) => [...modal.querySelectorAll(ENFOCABLES)].filter(visible);

  function abrir(id, origen) {
    const modal = document.getElementById(id);
    if (!modal) return;
    const activo = origen || document.activeElement;
    actual = { modal, origen: activo instanceof Element && activo !== document.body ? activo : null };
    modal.classList.add('abierto');
    modal.setAttribute('aria-hidden', 'false');

    const dentro = enfocables(modal);
    const primero = dentro.find((e) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.tagName)) || dentro[0];
    if (primero) primero.focus();
  }

  function cerrar(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('abierto');
    modal.setAttribute('aria-hidden', 'true');
    if (actual && actual.modal === modal) {
      const origen = actual.origen;
      actual = null;
      if (origen && typeof origen.focus === 'function') origen.focus();
    }
  }

  window.FlexModales = { abrir, cerrar };

  document.addEventListener('click', (evento) => {
    const objetivo = evento.target;
    if (!(objetivo instanceof Element)) return;

    const abre = objetivo.closest('[data-abrir-modal]');
    if (abre) {
      abrir(abre.dataset.abrirModal, abre);
      return;
    }

    const cierra = objetivo.closest('[data-cerrar-modal]');
    if (cierra) {
      const modal = cierra.closest('.modal-fondo');
      if (modal) cerrar(modal.id);
      return;
    }

    // Clic en el fondo (no en la tarjeta): solo los diálogos que lo piden.
    if (objetivo.classList.contains('modal-fondo') && objetivo.hasAttribute('data-cierra-fuera')) {
      cerrar(objetivo.id);
    }
  });

  document.addEventListener('keydown', (evento) => {
    if (!actual) return;

    if (evento.key === 'Escape') {
      cerrar(actual.modal.id);
      return;
    }

    if (evento.key === 'Tab') {
      const dentro = enfocables(actual.modal);
      if (dentro.length === 0) { evento.preventDefault(); return; }
      const primero = dentro[0];
      const ultimo = dentro[dentro.length - 1];
      const activo = document.activeElement;
      if (!actual.modal.contains(activo)) {
        evento.preventDefault();
        primero.focus();
      } else if (evento.shiftKey && activo === primero) {
        evento.preventDefault();
        ultimo.focus();
      } else if (!evento.shiftKey && activo === ultimo) {
        evento.preventDefault();
        primero.focus();
      }
    }
  });
})();
