/* ============================================================================
 * dashboard.js — Animaciones y gestión visual de los paneles
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Animar los contadores KPI (count-up) al entrar en viewport.
 *   - Gestión visual de estados: aprobar/rechazar registros y solicitudes,
 *     activar/desactivar filas, actualizando el badge de estado en vivo.
 *
 * Marcado esperado:
 *   - KPI animados:
 *       <div class="stat-card"><strong data-count="412">0</strong>…</div>
 *   - Acciones de estado en tarjetas/filas:
 *       <button data-state-action="aprobar" data-target="#fila-3"
 *               data-flash="Registro aprobado">Aprobar</button>
 *     El contenedor objetivo puede tener [data-state-badge] para actualizar.
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  /* ----- Contadores KPI animados ------------------------------------------ */

  function initCounters() {
    const counters = FA.$$('[data-count]');
    if (!counters.length) return;

    // IntersectionObserver: animamos cuando el KPI entra en pantalla.
    const observer = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            animate(entry.target);
            obs.unobserve(entry.target); // anima una sola vez
          }
        });
      },
      { threshold: 0.4 }
    );

    counters.forEach((el) => observer.observe(el));
  }

  /** Cuenta desde 0 hasta el valor objetivo conservando prefijos (🏆, etc.). */
  function animate(el) {
    const target = parseFloat(el.getAttribute('data-count')) || 0;
    const suffix = el.getAttribute('data-count-suffix') || '';
    const prefix = el.getAttribute('data-count-prefix') || '';
    const duration = 900;
    const start = performance.now();

    function frame(now) {
      const progress = Math.min((now - start) / duration, 1);
      // Easing easeOutCubic para una desaceleración natural.
      const eased = 1 - Math.pow(1 - progress, 3);
      const value = Math.round(target * eased);
      el.textContent = prefix + value + suffix;
      if (progress < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }

  /* ----- Gestión visual de estados ---------------------------------------- */

  function initStateActions() {
    FA.on(document, 'click', (e) => {
      const btn = e.target.closest('[data-state-action]');
      if (!btn) return;

      const action = btn.getAttribute('data-state-action');
      const targetSel = btn.getAttribute('data-target');
      const target = targetSel ? FA.$(targetSel) : btn.closest('tr, .card, [data-state-item]');

      // Actualiza el badge de estado si el objetivo lo tiene.
      if (target) {
        const badge = target.querySelector('[data-state-badge]');
        if (badge) updateBadge(badge, action);

        // Aprobar/rechazar registros: la tarjeta desaparece de la cola.
        if (action === 'aprobar' || action === 'rechazar') {
          target.style.transition = 'opacity .3s, transform .3s';
          target.style.opacity = '0';
          target.style.transform = 'scale(.96)';
          setTimeout(() => (target.hidden = true), 320);
        }
      }

      const msg = btn.getAttribute('data-flash');
      if (msg) {
        FA.flash(msg, action === 'rechazar' ? 'danger' : 'success');
      }
    });
  }

  /** Cambia el texto y la clase del badge de estado según la acción. */
  function updateBadge(badge, action) {
    const map = {
      activar: { text: 'Activo', cls: 'chip success' },
      desactivar: { text: 'Inactivo', cls: 'chip danger' },
      aprobar: { text: 'Aprobado', cls: 'chip success' },
      rechazar: { text: 'Rechazado', cls: 'chip danger' },
    };
    const cfg = map[action];
    if (!cfg) return;
    badge.className = cfg.cls;
    badge.textContent = cfg.text;
  }

  FA.ready(initCounters);
  FA.ready(initStateActions);
})(window.FA);
