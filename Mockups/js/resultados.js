/* ============================================================================
 * resultados.js — Carga y corrección de resultados
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Abrir el modal "Cargar resultado" precargando los nombres de los rivales.
 *   - Abrir el modal "Corregir resultado" precargando puntajes y exigiendo
 *     un motivo de corrección.
 *   - Validar que el motivo de corrección tenga longitud mínima.
 *   - Dar feedback visual al confirmar (mockup, sin backend).
 *
 * Marcado esperado (botón disparador):
 *   <button data-result-load
 *           data-jugador-a="García, Luis"
 *           data-jugador-b="Martínez, Ana">Cargar</button>
 *
 *   <button data-result-fix
 *           data-jugador-a="García, Luis" data-jugador-b="Martínez, Ana"
 *           data-puntos-a="1" data-puntos-b="0">Corregir</button>
 *
 * Modales esperados: #modalCargar y #modalCorregir con campos data-*.
 * Depende de: app.js (FA), modals.js (FA.modal).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  FA.ready(function initResultados() {
    /* ----- Cargar resultado ---------------------------------------------- */
    const modalCargar = document.getElementById('modalCargar');

    FA.$$('[data-result-load]').forEach((btn) => {
      FA.on(btn, 'click', () => {
        if (!modalCargar) return;
        const a = btn.getAttribute('data-jugador-a') || 'Local';
        const b = btn.getAttribute('data-jugador-b') || 'Visitante';

        setText(modalCargar, '[data-titulo]', `${a} vs ${b}`);
        setText(modalCargar, '[data-label-a]', a);
        setText(modalCargar, '[data-label-b]', b);
        resetInputs(modalCargar);

        FA.modal.open('modalCargar');
      });
    });

    /* ----- Corregir resultado -------------------------------------------- */
    const modalCorregir = document.getElementById('modalCorregir');

    FA.$$('[data-result-fix]').forEach((btn) => {
      FA.on(btn, 'click', () => {
        if (!modalCorregir) return;
        const a = btn.getAttribute('data-jugador-a') || 'Local';
        const b = btn.getAttribute('data-jugador-b') || 'Visitante';

        setText(modalCorregir, '[data-label-a]', a);
        setText(modalCorregir, '[data-label-b]', b);
        setValue(modalCorregir, '[data-input-a]', btn.getAttribute('data-puntos-a') || '');
        setValue(modalCorregir, '[data-input-b]', btn.getAttribute('data-puntos-b') || '');
        const motivo = modalCorregir.querySelector('[data-input-motivo]');
        if (motivo) motivo.value = '';

        FA.modal.open('modalCorregir');
      });
    });

    /* ----- Confirmaciones de envío (mockup) ------------------------------ */

    // Cargar resultado: feedback simple.
    bindConfirm(modalCargar, '[data-confirm-cargar]', () => {
      FA.flash('Resultado cargado. Ranking actualizado.', 'success');
      FA.modal.close('modalCargar');
      // Marca visual: dispara recálculo de la tabla si liga.js está presente.
      document.dispatchEvent(new CustomEvent('fa:resultado-cargado'));
    });

    // Corregir resultado: exige motivo de al menos 10 caracteres.
    bindConfirm(modalCorregir, '[data-confirm-corregir]', () => {
      const motivo = modalCorregir.querySelector('[data-input-motivo]');
      if (motivo && motivo.value.trim().length < 10) {
        motivo.classList.add('field-error');
        FA.flash('El motivo debe tener al menos 10 caracteres.', 'warning');
        return;
      }
      FA.flash('Resultado corregido. Tabla recalculada.', 'success');
      FA.modal.close('modalCorregir');
      document.dispatchEvent(new CustomEvent('fa:resultado-cargado'));
    });
  });

  /* ----- Helpers ----------------------------------------------------------- */

  function setText(scope, sel, text) {
    const el = scope.querySelector(sel);
    if (el) el.textContent = text;
  }

  function setValue(scope, sel, value) {
    const el = scope.querySelector(sel);
    if (el) el.value = value;
  }

  function resetInputs(scope) {
    FA.$$('input[type="number"]', scope).forEach((i) => (i.value = ''));
  }

  function bindConfirm(scope, sel, handler) {
    if (!scope) return;
    const btn = scope.querySelector(sel);
    FA.on(btn, 'click', handler);
  }
})(window.FA);
