/* ============================================================================
 * forms.js — Gestión de formularios y validación visual
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Medidor de fortaleza de contraseña en tiempo real (registro).
 *   - Validación visual de campos requeridos antes del envío.
 *   - Mostrar/ocultar campos según el formato de torneo seleccionado
 *     (Liga / Eliminación Directa / Sistema Suizo).
 *   - Feedback con mensajes flash al "guardar" (mockup, sin backend).
 *
 * Marcado esperado:
 *   - Reglas de contraseña:
 *       <input data-password> + <input data-password-confirm>
 *       <ul data-password-hints><li data-rule="len">…</li>…</ul>
 *       <button data-password-submit>
 *   - Campos condicionales por formato:
 *       <select data-format-select>…</select>
 *       <div data-format-field data-format-show="suizo">…</div>
 *       <div data-format-field data-format-show="liga">…</div>
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  /* ----- 1. Medidor de fortaleza de contraseña --------------------------- */

  function initPasswordStrength() {
    const pw = FA.$('[data-password]');
    const pc = FA.$('[data-password-confirm]');
    const hints = FA.$('[data-password-hints]');
    const submit = FA.$('[data-password-submit]');
    if (!pw || !hints) return;

    // Cada regla evalúa el valor y devuelve true/false.
    const rules = {
      len: (v) => v.length >= 8,
      upper: (v) => /[A-ZÁÉÍÓÚÑ]/.test(v),
      lower: (v) => /[a-záéíóúñ]/.test(v),
      num: (v) => /[0-9]/.test(v),
      sym: (v) => /[^A-Za-z0-9]/.test(v),
      match: (v) => v.length > 0 && v === (pc ? pc.value : ''),
    };

    function evaluate() {
      const value = pw.value;
      let allOk = true;

      FA.$$('li', hints).forEach((li) => {
        const ruleName = li.getAttribute('data-rule');
        const ok = rules[ruleName] ? rules[ruleName](value) : true;
        li.classList.toggle('rule-ok', ok);
        li.classList.toggle('rule-pending', !ok);
        if (!ok) allOk = false;
      });

      if (submit) {
        submit.disabled = !allOk;
        submit.style.opacity = allOk ? '1' : '.55';
      }
    }

    FA.on(pw, 'input', evaluate);
    FA.on(pc, 'input', evaluate);
    evaluate(); // estado inicial
  }

  /* ----- 2. Validación visual de campos requeridos ----------------------- */

  function initRequiredValidation() {
    FA.$$('form[data-validate]').forEach((form) => {
      FA.on(form, 'submit', (e) => {
        let valid = true;

        FA.$$('[required]', form).forEach((field) => {
          const empty = !field.value.trim();
          field.classList.toggle('field-error', empty);
          if (empty) valid = false;
        });

        if (!valid) {
          e.preventDefault();
          FA.flash('Completá los campos obligatorios resaltados.', 'warning');
          return;
        }

        // Mockup: evitamos el envío real y damos feedback / redirección.
        if (form.hasAttribute('data-mock')) {
          e.preventDefault();
          const redirect = form.getAttribute('data-redirect');
          if (redirect) {
            // Login simulado: navegamos a la pantalla destino.
            window.location.href = redirect;
            return;
          }
          FA.flash(
            form.getAttribute('data-flash') || 'Datos guardados correctamente.',
            'success'
          );
          // Si el formulario vive dentro de un modal, lo cerramos.
          const modal = form.closest('.modal-overlay');
          if (modal && FA.modal) FA.modal.close(modal.id);
          form.reset();
        }
      });

      // Quita el resaltado de error en cuanto el usuario corrige el campo.
      FA.$$('[required]', form).forEach((field) =>
        FA.on(field, 'input', () => field.classList.remove('field-error'))
      );
    });
  }

  /* ----- 3. Campos condicionales según formato de torneo ----------------- */

  function initConditionalFields() {
    const select = FA.$('[data-format-select]');
    if (!select) return;

    const fields = FA.$$('[data-format-field]');

    function apply() {
      const current = select.value;
      fields.forEach((field) => {
        // data-format-show admite varios formatos separados por coma.
        const show = (field.getAttribute('data-format-show') || '')
          .split(',')
          .map((s) => s.trim());
        const visible = show.includes(current);
        field.hidden = !visible;
        // Desactivamos inputs ocultos para que no "ensucien" el envío.
        FA.$$('input, select, textarea', field).forEach((inp) => {
          inp.disabled = !visible;
        });
      });
    }

    FA.on(select, 'change', apply);
    apply(); // estado inicial según el valor por defecto
  }

  /* ----- Inicialización --------------------------------------------------- */

  FA.ready(initPasswordStrength);
  FA.ready(initRequiredValidation);
  FA.ready(initConditionalFields);
})(window.FA);
