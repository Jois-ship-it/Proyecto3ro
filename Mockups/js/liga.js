/* ============================================================================
 * liga.js — Interacciones de la tabla de posiciones (formato Liga)
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Reordenar visualmente la tabla de posiciones por puntos y recalcular
 *     la columna de posición (#) tras un cambio de resultado.
 *   - Resaltar las filas de zona destacada (líder / podio).
 *   - Reaccionar al evento `fa:resultado-cargado` emitido por resultados.js
 *     para simular la actualización en vivo de la tabla.
 *
 * Marcado esperado:
 *   <table data-liga-tabla>
 *     <tbody>
 *       <tr data-pts="16"> <td data-pos></td> … </tr>
 *       …
 *     </tbody>
 *   </table>
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  FA.ready(function initLiga() {
    const table = FA.$('[data-liga-tabla]');
    if (!table) return;
    const tbody = table.tBodies[0];
    if (!tbody) return;

    /** Reordena filas por puntos (desc) y renumera la columna de posición. */
    function recalcular() {
      const rows = FA.$$('tr', tbody);
      rows.sort(
        (a, b) =>
          parseFloat(b.getAttribute('data-pts') || 0) -
          parseFloat(a.getAttribute('data-pts') || 0)
      );

      rows.forEach((row, i) => {
        tbody.appendChild(row); // reinserta en orden
        const posCell = row.querySelector('[data-pos]');
        if (posCell) posCell.textContent = i + 1;
        // Marca podio para estilos (oro/plata/bronce).
        row.classList.toggle('liga-leader', i === 0);
        row.classList.toggle('liga-podium', i < 3);
      });
    }

    // Recalcula al cargar y cuando un resultado cambia.
    recalcular();
    FA.on(document, 'fa:resultado-cargado', () => {
      recalcular();
      FA.flash('Tabla de posiciones actualizada.', 'info', 2500);
    });
  });
})(window.FA);
