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

// ─── Confirmar antes de enviar un formulario ──────────
// <form data-confirmar="¿Seguro?"> pregunta antes de enviar; si la persona cancela, no se envía.
// (Antes cada formulario llevaba un onsubmit="return confirm(…)" escrito en el HTML: con el texto de una persona
// adentro era una forma de inyectar código, y la política de seguridad de contenido no admite manejadores en línea.)
document.addEventListener('submit', (evento) => {
  const formulario = evento.target;
  if (formulario instanceof HTMLFormElement && formulario.dataset.confirmar && !window.confirm(formulario.dataset.confirmar)) {
    evento.preventDefault();
  }
});

// ─── Imprimir ─────────────────────────────────────────
// <button data-imprimir> abre el diálogo de impresión del navegador (también guarda en PDF).
document.addEventListener('click', (evento) => {
  if (evento.target instanceof Element && evento.target.closest('[data-imprimir]')) window.print();
});

// ─── Auto-dismiss de flash messages ───────────────────
document.querySelectorAll('.flash-msg').forEach(msg => {
  setTimeout(() => { msg.style.opacity = '0'; msg.style.transition = 'opacity .6s'; }, 5000);
});
