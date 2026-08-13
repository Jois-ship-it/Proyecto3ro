/* ============================================================================
 * tables.js — Búsqueda y ordenamiento en tablas
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Búsqueda/filtrado de filas en vivo mediante un input de texto.
 *   - Ordenamiento ascendente/descendente al hacer clic en encabezados.
 *   - Mensaje de "sin resultados" cuando el filtro no encuentra coincidencias.
 *
 * Marcado esperado:
 *   - Búsqueda:
 *       <input data-table-search="#idTabla" placeholder="Buscar…">
 *   - Ordenamiento:
 *       <th data-sort>Nombre</th>           (texto)
 *       <th data-sort="number">Puntos</th>  (numérico)
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  /* ----- Búsqueda en vivo -------------------------------------------------- */

  function initSearch() {
    FA.$$('[data-table-search]').forEach((input) => {
      const table = FA.$(input.getAttribute('data-table-search'));
      if (!table) return;
      const tbody = table.tBodies[0];
      if (!tbody) return;

      // Fila reutilizable para el estado "sin resultados".
      let emptyRow = null;

      const run = FA.debounce(() => {
        const query = FA.normalize(input.value);
        let visibles = 0;

        FA.$$('tr', tbody).forEach((row) => {
          if (row === emptyRow) return;
          const match = FA.normalize(row.textContent).includes(query);
          row.hidden = !match;
          if (match) visibles++;
        });

        toggleEmpty(visibles === 0);
      }, 120);

      function toggleEmpty(show) {
        if (show && !emptyRow) {
          const cols = table.tHead
            ? table.tHead.rows[0].cells.length
            : 1;
          emptyRow = document.createElement('tr');
          emptyRow.innerHTML =
            `<td colspan="${cols}" class="muted" style="text-align:center">` +
            'Sin coincidencias para la búsqueda.</td>';
          tbody.appendChild(emptyRow);
        }
        if (emptyRow) emptyRow.hidden = !show;
      }

      FA.on(input, 'input', run);
    });
  }

  /* ----- Ordenamiento por columnas ---------------------------------------- */

  function initSort() {
    FA.$$('table').forEach((table) => {
      const headers = FA.$$('th[data-sort]', table);
      if (!headers.length) return;
      const tbody = table.tBodies[0];
      if (!tbody) return;

      headers.forEach((th, colIndex) => {
        // El índice real de la columna en la fila completa.
        const realIndex = Array.from(th.parentNode.children).indexOf(th);
        th.classList.add('sortable');

        FA.on(th, 'click', () => {
          const type = th.getAttribute('data-sort') || 'text';
          const asc = th.getAttribute('data-sort-dir') !== 'asc';

          // Limpia indicadores de las demás columnas.
          headers.forEach((h) => h.removeAttribute('data-sort-dir'));
          th.setAttribute('data-sort-dir', asc ? 'asc' : 'desc');

          const rows = FA.$$('tr', tbody).filter((r) => !r.hidden);
          rows.sort((a, b) => {
            const av = cellValue(a, realIndex, type);
            const bv = cellValue(b, realIndex, type);
            if (av < bv) return asc ? -1 : 1;
            if (av > bv) return asc ? 1 : -1;
            return 0;
          });

          // Reinsertar en el nuevo orden.
          rows.forEach((r) => tbody.appendChild(r));
        });
      });
    });
  }

  /** Extrae el valor comparable de una celda según el tipo de columna. */
  function cellValue(row, index, type) {
    const cell = row.cells[index];
    const raw = cell ? cell.textContent.trim() : '';
    if (type === 'number') {
      const n = parseFloat(raw.replace(/[^\d.-]/g, ''));
      return isNaN(n) ? -Infinity : n;
    }
    return FA.normalize(raw);
  }

  FA.ready(initSearch);
  FA.ready(initSort);
})(window.FA);
