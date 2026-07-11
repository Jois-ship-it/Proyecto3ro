/* ============================================================================
 * confirm.js — Confirmaciones de acciones críticas
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Interceptar acciones peligrosas/irreversibles y pedir confirmación
 *     mediante un modal propio (no el confirm() nativo del navegador).
 *   - Cubre los flujos críticos del sistema:
 *       · generar fixture (Liga)
 *       · generar bracket (Eliminación Directa)
 *       · generar ronda (Sistema Suizo)
 *       · cargar resultado
 *       · corregir resultado
 *       · finalizar torneo
 *       · activar / desactivar usuarios y participantes
 *
 * Marcado esperado (declarativo, sin JS inline):
 *   <button data-confirm="¿Generar el bracket? No se puede deshacer."
 *           data-confirm-type="warning"
 *           data-confirm-ok="Generar"
 *           data-flash="Bracket generado">Generar bracket</button>
 *
 *   También funciona sobre <a href="…"> (sigue el enlace al confirmar).
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  let dialog = null; // referencia al modal inyectado
  let pendingTrigger = null; // elemento que disparó la confirmación

  /** Construye una sola vez el modal de confirmación y lo añade al body. */
  function buildDialog() {
    dialog = document.createElement('div');
    dialog.className = 'modal-overlay';
    dialog.id = 'fa-confirm-dialog';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-hidden', 'true');
    dialog.innerHTML =
      '<div class="modal-card" style="width:min(100%,420px)">' +
      '  <h3 class="confirm-title">Confirmar acción</h3>' +
      '  <p class="confirm-message muted"></p>' +
      '  <div class="form-actions">' +
      '    <button class="btn" data-confirm-cancel>Cancelar</button>' +
      '    <button class="btn primary" data-confirm-accept>Confirmar</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(dialog);

    FA.on(dialog.querySelector('[data-confirm-cancel]'), 'click', cancel);
    FA.on(dialog.querySelector('[data-confirm-accept]'), 'click', accept);
    FA.on(dialog, 'click', (e) => {
      if (e.target === dialog) cancel();
    });
  }

  /** Muestra el diálogo configurado según los data-* del disparador. */
  function show(trigger) {
    if (!dialog) buildDialog();
    pendingTrigger = trigger;

    const msg = trigger.getAttribute('data-confirm') || '¿Confirmás la acción?';
    const okLabel = trigger.getAttribute('data-confirm-ok') || 'Confirmar';
    const type = trigger.getAttribute('data-confirm-type') || 'primary';

    dialog.querySelector('.confirm-message').textContent = msg;

    const okBtn = dialog.querySelector('[data-confirm-accept]');
    okBtn.textContent = okLabel;
    // El botón de aceptar adopta el color del tipo de acción.
    okBtn.className = 'btn ' + (type === 'danger' ? 'danger' : 'primary');

    dialog.classList.add('open');
    dialog.setAttribute('aria-hidden', 'false');
    okBtn.focus();
  }

  function hide() {
    if (!dialog) return;
    dialog.classList.remove('open');
    dialog.setAttribute('aria-hidden', 'true');
  }

  function cancel() {
    hide();
    pendingTrigger = null;
  }

  /** El usuario confirmó: ejecuta la acción real del disparador. */
  function accept() {
    const trigger = pendingTrigger;
    hide();
    if (!trigger) return;

    const flashMsg = trigger.getAttribute('data-flash');
    const flashType = trigger.getAttribute('data-confirm-type') === 'danger'
      ? 'danger'
      : 'success';

    // En el mockup no hay backend: mostramos feedback visual.
    if (flashMsg) FA.flash(flashMsg, flashType);

    // Si era un enlace real, lo seguimos tras confirmar.
    const href = trigger.getAttribute('href');
    if (href && href !== '#') {
      window.location.href = href;
    }
    pendingTrigger = null;
  }

  FA.ready(function initConfirm() {
    // Delegación global: cualquier elemento con data-confirm queda cubierto,
    // incluso si se agrega dinámicamente al DOM más tarde.
    FA.on(document, 'click', (e) => {
      const trigger = e.target.closest('[data-confirm]');
      if (!trigger) return;
      e.preventDefault(); // frenamos la acción hasta que el usuario confirme
      show(trigger);
    });
  });

  // API pública por si otro módulo necesita confirmar programáticamente.
  FA.confirm = show;
})(window.FA);
