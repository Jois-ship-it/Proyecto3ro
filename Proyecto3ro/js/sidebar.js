/* ============================================================================
 * sidebar.js — Navegación lateral responsive y menú mobile
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Abrir/cerrar el sidebar en viewports mobile (off-canvas).
 *   - Gestionar el botón hamburguesa de la topbar.
 *   - Cerrar el sidebar al hacer clic fuera, al pulsar Escape o al navegar.
 *   - Mantener accesibilidad básica (aria-expanded, foco).
 *
 * Marcado esperado:
 *   <button class="menu-toggle" data-sidebar-toggle>…</button>   (en .topbar)
 *   <aside class="sidebar" data-sidebar> … </aside>
 *   El overlay se inyecta dinámicamente.
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  FA.ready(function initSidebar() {
    const sidebar = FA.$('[data-sidebar]');
    if (!sidebar) return; // Páginas públicas no tienen sidebar.

    const toggleBtn = FA.$('[data-sidebar-toggle]');

    // Overlay semitransparente que aparece detrás del sidebar en mobile.
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    /** Abre el sidebar (mobile). */
    function open() {
      sidebar.classList.add('open');
      overlay.classList.add('show');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden'; // evita scroll de fondo
    }

    /** Cierra el sidebar (mobile). */
    function close() {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }

    /** Alterna el estado actual. */
    function toggle() {
      sidebar.classList.contains('open') ? close() : open();
    }

    // Botón hamburguesa.
    FA.on(toggleBtn, 'click', toggle);

    // Clic en el overlay cierra.
    FA.on(overlay, 'click', close);

    // Escape cierra.
    FA.on(document, 'keydown', (e) => {
      if (e.key === 'Escape') close();
    });

    // Al hacer clic en un enlace del sidebar, lo cerramos (UX mobile).
    FA.$$('.side-link', sidebar).forEach((link) =>
      FA.on(link, 'click', () => {
        if (window.innerWidth <= 980) close();
      })
    );

    // Si se agranda la ventana a desktop, garantizamos estado limpio.
    FA.on(
      window,
      'resize',
      FA.debounce(() => {
        if (window.innerWidth > 980) close();
      }, 150)
    );
  });
})(window.FA);
