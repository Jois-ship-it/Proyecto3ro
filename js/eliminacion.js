/* ============================================================================
 * eliminacion.js — Bracket de Eliminación Directa: scroll horizontal inteligente
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Permitir desplazamiento horizontal del bracket con:
 *       · arrastre con el mouse (click & drag / "grab").
 *       · botones laterales ‹ ›.
 *       · rueda del mouse (scroll vertical → horizontal).
 *       · teclado (flechas ← →) cuando el bracket tiene foco.
 *   - Mostrar/ocultar los botones según haya contenido desbordado.
 *   - Resaltar el camino del campeón al pasar el mouse sobre una tarjeta.
 *
 * Marcado esperado:
 *   <div class="bk-scroll" data-bracket>
 *     <div class="bk"> … tarjetas .bk-card … </div>
 *   </div>
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  FA.ready(function initBracket() {
    FA.$$('[data-bracket]').forEach(setupBracket);
  });

  function setupBracket(scroller) {
    scroller.setAttribute('tabindex', '0'); // navegable por teclado
    scroller.classList.add('bracket-grab');

    /* ----- Arrastre con el mouse ------------------------------------------ */
    let isDown = false;
    let startX = 0;
    let startScroll = 0;

    FA.on(scroller, 'mousedown', (e) => {
      isDown = true;
      scroller.classList.add('grabbing');
      startX = e.pageX - scroller.offsetLeft;
      startScroll = scroller.scrollLeft;
    });

    const stop = () => {
      isDown = false;
      scroller.classList.remove('grabbing');
    };
    FA.on(scroller, 'mouseleave', stop);
    FA.on(scroller, 'mouseup', stop);

    FA.on(scroller, 'mousemove', (e) => {
      if (!isDown) return;
      e.preventDefault();
      const x = e.pageX - scroller.offsetLeft;
      // Factor 1.4 → desplazamiento un poco más ágil que el cursor.
      scroller.scrollLeft = startScroll - (x - startX) * 1.4;
    });

    /* ----- Rueda del mouse: vertical → horizontal ------------------------- */
    FA.on(
      scroller,
      'wheel',
      (e) => {
        if (e.deltaY === 0) return;
        e.preventDefault();
        scroller.scrollLeft += e.deltaY;
      },
      { passive: false }
    );

    /* ----- Teclado --------------------------------------------------------- */
    FA.on(scroller, 'keydown', (e) => {
      const step = 240;
      if (e.key === 'ArrowRight') scroller.scrollLeft += step;
      if (e.key === 'ArrowLeft') scroller.scrollLeft -= step;
    });

    /* ----- Botones laterales ‹ › ------------------------------------------ */
    const prev = makeNavButton('‹', 'bracket-nav prev');
    const next = makeNavButton('›', 'bracket-nav next');
    // El contenedor debe ser relative para posicionar los botones.
    const wrap = scroller.parentNode;
    wrap.style.position = wrap.style.position || 'relative';
    wrap.appendChild(prev);
    wrap.appendChild(next);

    FA.on(prev, 'click', () => smoothScroll(scroller, -320));
    FA.on(next, 'click', () => smoothScroll(scroller, 320));

    /** Actualiza la visibilidad de los botones según el desplazamiento. */
    function updateButtons() {
      const overflow = scroller.scrollWidth > scroller.clientWidth + 4;
      prev.hidden = !overflow || scroller.scrollLeft <= 4;
      next.hidden =
        !overflow ||
        scroller.scrollLeft >= scroller.scrollWidth - scroller.clientWidth - 4;
    }

    FA.on(scroller, 'scroll', FA.debounce(updateButtons, 60));
    FA.on(window, 'resize', FA.debounce(updateButtons, 120));
    updateButtons();
  }

  /* ----- Helpers ----------------------------------------------------------- */

  function makeNavButton(symbol, className) {
    const btn = document.createElement('button');
    btn.className = className;
    btn.type = 'button';
    btn.textContent = symbol;
    btn.setAttribute('aria-label', symbol === '‹' ? 'Anterior' : 'Siguiente');
    return btn;
  }

  function smoothScroll(el, amount) {
    el.scrollBy({ left: amount, behavior: 'smooth' });
  }
})(window.FA);
