/* ============================================================================
 * suizo.js — Interacciones del Sistema Suizo
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Cambio de vista entre rondas mediante pestañas (tabs).
 *   - Reordenamiento visual del ranking por puntaje y desempate (Buchholz).
 *   - Renumeración de la posición tras una actualización.
 *   - Reacciona a `fa:resultado-cargado` para refrescar el ranking.
 *
 * Marcado esperado:
 *   - Pestañas de ronda:
 *       <div data-ronda-tabs>
 *         <button data-ronda-tab="5" class="active">Ronda 5</button>
 *         <button data-ronda-tab="4">Ronda 4</button>
 *       </div>
 *       <div data-ronda-panel="5">…</div>
 *       <div data-ronda-panel="4" hidden>…</div>
 *   - Ranking:
 *       <table data-suizo-ranking>
 *         <tbody>
 *           <tr data-pts="9" data-buchholz="22"><td data-pos></td>…</tr>
 *         </tbody>
 *       </table>
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  /* ----- Pestañas de ronda ------------------------------------------------- */

  function initRondaTabs() {
    const tabsWrap = FA.$('[data-ronda-tabs]');
    if (!tabsWrap) return;

    const tabs = FA.$$('[data-ronda-tab]', tabsWrap);
    const panels = FA.$$('[data-ronda-panel]');

    function activar(ronda) {
      tabs.forEach((t) =>
        t.classList.toggle('active', t.getAttribute('data-ronda-tab') === ronda)
      );
      panels.forEach((p) => {
        p.hidden = p.getAttribute('data-ronda-panel') !== ronda;
      });
    }

    tabs.forEach((tab) =>
      FA.on(tab, 'click', () => activar(tab.getAttribute('data-ronda-tab')))
    );
  }

  /* ----- Ranking ----------------------------------------------------------- */

  function initRanking() {
    const table = FA.$('[data-suizo-ranking]');
    if (!table) return;
    const tbody = table.tBodies[0];
    if (!tbody) return;

    /** Ordena por puntos y, a igualdad, por Buchholz; renumera posiciones. */
    function recalcular() {
      const rows = FA.$$('tr', tbody);
      rows.sort((a, b) => {
        const pa = parseFloat(a.getAttribute('data-pts') || 0);
        const pb = parseFloat(b.getAttribute('data-pts') || 0);
        if (pb !== pa) return pb - pa;
        // Desempate por Buchholz.
        return (
          parseFloat(b.getAttribute('data-buchholz') || 0) -
          parseFloat(a.getAttribute('data-buchholz') || 0)
        );
      });

      rows.forEach((row, i) => {
        tbody.appendChild(row);
        const posCell = row.querySelector('[data-pos]');
        if (posCell) posCell.textContent = i + 1;
        row.classList.toggle('suizo-leader', i === 0);
      });
    }

    recalcular();
    FA.on(document, 'fa:resultado-cargado', () => {
      recalcular();
      FA.flash('Ranking Suizo actualizado.', 'info', 2500);
    });
  }

  FA.ready(initRondaTabs);
  FA.ready(initRanking);
})(window.FA);
