/* FlexArena — gestion-torneo.js
 *
 * Los diálogos de la gestión de un torneo: cargar resultado, corregirlo (o solicitar su corrección) y
 * programar un partido. Cada botón de la pantalla trae los datos del partido en atributos `data-*`;
 * acá se copian a los campos del diálogo y se abre.
 *
 *   data-modal-cargar    data-enf-id  data-nombre-a  data-nombre-b
 *   data-modal-corregir  data-enf-id  data-nombre-a  data-nombre-b  data-puntos-a  data-puntos-b
 *   data-modal-programar data-enf-id  data-nombre-a  data-nombre-b  data-fecha
 *
 * Un título que cambia con el partido lleva `data-prefijo-titulo` («Programar · » + «A vs B»).
 * El menú del bracket (bracket.js) llama a las mismas funciones por `FlexGestion`; el segundo argumento es el control
 * al que vuelve el foco cuando se cierra el diálogo.
 * Necesita modales.js, que se carga antes.
 */
'use strict';

(() => {
  if (typeof FlexModales === 'undefined') return;

  const elemento = (id) => document.getElementById(id);
  const poner = (id, valor) => { const e = elemento(id); if (e) e.value = valor ?? ''; };
  const escribir = (id, valor) => { const e = elemento(id); if (e) e.textContent = valor; };

  function titulo(id, d) {
    const e = elemento(id);
    if (e && e.hasAttribute('data-prefijo-titulo')) {
      e.textContent = e.dataset.prefijoTitulo + (d.nombreA || '') + ' vs ' + (d.nombreB || '');
    }
  }

  function cargar(d, origen) {
    poner('cargarEnfId', d.enfId);
    titulo('modalCargarTitulo', d);
    escribir('lblCargarA', d.nombreA || 'Local');
    escribir('lblCargarB', d.nombreB || 'Visitante');
    poner('cargarPuntosA', '');
    poner('cargarPuntosB', '');
    FlexModales.abrir('modalCargar', origen);
  }

  function corregir(d, origen) {
    poner('corregirEnfId', d.enfId);
    titulo('modalCorregirTitulo', d);
    escribir('lblCorregirA', d.nombreA || 'Local');
    escribir('lblCorregirB', d.nombreB || 'Visitante');
    poner('corregirPuntosA', d.puntosA);
    poner('corregirPuntosB', d.puntosB);
    FlexModales.abrir('modalCorregir', origen);
  }

  function programar(d, origen) {
    poner('programarEnfId', d.enfId);
    poner('programarFecha', d.fecha || '');
    titulo('modalProgramarTitulo', d);
    FlexModales.abrir('modalProgramar', origen);
  }

  window.FlexGestion = { cargar, corregir, programar };

  const abridores = { 'data-modal-cargar': cargar, 'data-modal-corregir': corregir, 'data-modal-programar': programar };
  for (const [atributo, abrirDialogo] of Object.entries(abridores)) {
    document.querySelectorAll(`[${atributo}]`).forEach((boton) => {
      boton.addEventListener('click', () => abrirDialogo(boton.dataset, boton));
    });
  }
})();
