/* FlexArena — app.js */
'use strict';

// ─── Active links (sidebar/nav) ──────────────────────
const currentPath = window.location.pathname;
document.querySelectorAll('[href]').forEach(link => {
  if (link.getAttribute('href') === currentPath) {
    link.classList.add('active');
  }
});

// ─── Navegación móvil pública ─────────────────────────
const navToggle = document.querySelector('[data-nav-toggle]');
const publicNav  = document.querySelector('[data-public-nav]');
if (navToggle && publicNav) {
  navToggle.addEventListener('click', () => {
    publicNav.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', String(publicNav.classList.contains('open')));
  });
}

// ─── Sidebar del panel (mobile) ───────────────────────
const menuBtn = document.querySelector('[data-sidebar-toggle]');
const sidebar  = document.querySelector('[data-sidebar]');
if (menuBtn && sidebar) {
  menuBtn.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    menuBtn.setAttribute('aria-expanded', String(sidebar.classList.contains('open')));
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      sidebar.classList.remove('open');
      menuBtn.setAttribute('aria-expanded', 'false');
    }
  });
  // Overlay al hacer clic fuera en mobile
  document.addEventListener('click', e => {
    if (window.innerWidth <= 980 &&
        sidebar.classList.contains('open') &&
        !sidebar.contains(e.target) &&
        e.target !== menuBtn) {
      sidebar.classList.remove('open');
    }
  });
}

// ─── Auto-dismiss de flash messages ───────────────────
document.querySelectorAll('.flash-msg').forEach(msg => {
  setTimeout(() => { msg.style.opacity = '0'; msg.style.transition = 'opacity .6s'; }, 5000);
});
