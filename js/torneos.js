/* ============================================================================
 * torneos.js — Filtros dinámicos del listado de torneos
 * ----------------------------------------------------------------------------
 * Responsabilidad:
 *   - Filtrar las tarjetas de torneos por formato y estado sin recargar.
 *   - Búsqueda por nombre en vivo.
 *   - Mostrar un mensaje cuando ningún torneo coincide con los filtros.
 *   - Contador de resultados visibles.
 *
 * Marcado esperado:
 *   <div data-torneos-grid>
 *     <article class="card" data-tipo="suizo" data-estado="en_curso"
 *              data-nombre="Copa Ajedrez">…</article>
 *     …
 *   </div>
 *   <select data-torneo-filter="tipo">…</select>
 *   <select data-torneo-filter="estado">…</select>
 *   <input  data-torneo-search>
 *   <span   data-torneos-count></span>
 *
 * Depende de: app.js (FA).
 * ==========================================================================*/

(function (FA) {
  'use strict';

  FA.ready(function initTorneoFilters() {
    const grid = FA.$('[data-torneos-grid]');
    if (!grid) return;

    const cards = FA.$$('[data-tipo]', grid);
    const filters = FA.$$('[data-torneo-filter]');
    const search = FA.$('[data-torneo-search]');
    const counter = FA.$('[data-torneos-count]');

    // Mensaje "sin resultados" (se crea una sola vez).
    const emptyMsg = document.createElement('div');
    emptyMsg.className = 'card empty-state';
    emptyMsg.hidden = true;
    emptyMsg.innerHTML =
      '<p class="muted" style="margin:0">Ningún torneo coincide con los filtros seleccionados.</p>';
    grid.parentNode.insertBefore(emptyMsg, grid.nextSibling);

    /** Aplica todos los filtros activos sobre las tarjetas. */
    function apply() {
      const tipo = getFilterValue('tipo');
      const estado = getFilterValue('estado');
      const query = search ? FA.normalize(search.value) : '';
      let visibles = 0;

      cards.forEach((card) => {
        const matchTipo = !tipo || card.getAttribute('data-tipo') === tipo;
        const matchEstado =
          !estado || card.getAttribute('data-estado') === estado;
        const matchNombre =
          !query ||
          FA.normalize(card.getAttribute('data-nombre')).includes(query);

        const visible = matchTipo && matchEstado && matchNombre;
        card.hidden = !visible;
        if (visible) visibles++;
      });

      emptyMsg.hidden = visibles !== 0;
      grid.hidden = visibles === 0;
      if (counter) {
        counter.textContent =
          visibles + (visibles === 1 ? ' torneo' : ' torneos');
      }
    }

    /** Lee el valor de un select de filtro por su nombre lógico. */
    function getFilterValue(name) {
      const sel = filters.find(
        (f) => f.getAttribute('data-torneo-filter') === name
      );
      return sel ? sel.value : '';
    }

    filters.forEach((f) => FA.on(f, 'change', apply));
    if (search) FA.on(search, 'input', FA.debounce(apply, 120));

    apply(); // estado inicial
  });
})(window.FA);
