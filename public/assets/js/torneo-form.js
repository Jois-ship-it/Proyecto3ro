/* FlexArena — torneo-form.js
   Muestra/oculta campos según el formato de torneo seleccionado */
'use strict';

(function () {
  const tipoSelect      = document.getElementById('tipoTorneo');
  const modalidadSelect = document.getElementById('modalidad');
  const campoEmpates    = document.getElementById('campoEmpates');
  const campoPuntuacion = document.getElementById('campoPuntuacion');
  const campoRondas     = document.getElementById('campoRondasSuizo');
  const campoBye        = document.getElementById('campoByeSuizo');
  const campoPuntsBye   = document.getElementById('campoPuntsBye');
  const campoMinEquipo  = document.getElementById('campoMinEquipo');
  const byeSelect       = document.getElementById('byeSuizo');
  const minEquipoInput  = campoMinEquipo ? campoMinEquipo.querySelector('input') : null;

  if (!tipoSelect) return;

  function actualizarCampos() {
    const slug = tipoSelect.options[tipoSelect.selectedIndex]?.dataset?.slug ?? '';

    const esLiga        = slug === 'liga';
    const esSuizo       = slug === 'suizo';
    const esEliminacion = slug === 'eliminacion_directa';

    // Puntuación tabla (V/E/D): oculta en Eliminación (no hay tabla de posiciones)
    mostrar(campoPuntuacion, !esEliminacion);

    // Empates: visible en Liga y Suizo, oculto en Eliminación
    mostrar(campoEmpates, esLiga || esSuizo);

    // Rondas y bye: solo Suizo
    mostrar(campoRondas, esSuizo);
    mostrar(campoBye,    esSuizo);

    // Puntos personalizados bye: solo si selector = 'personalizado'
    if (esSuizo && byeSelect) {
      mostrar(campoPuntsBye, byeSelect.value === 'personalizado');
    } else {
      mostrar(campoPuntsBye, false);
    }

    // Mínimo de integrantes: solo modalidad equipos
    const esEquipos = modalidadSelect && modalidadSelect.value === 'equipos';
    mostrar(campoMinEquipo, esEquipos);
    if (minEquipoInput) minEquipoInput.required = !!esEquipos;
  }

  function mostrar(el, visible) {
    if (!el) return;
    el.style.display = visible ? '' : 'none';
  }

  tipoSelect.addEventListener('change', actualizarCampos);
  if (byeSelect) byeSelect.addEventListener('change', actualizarCampos);
  if (modalidadSelect) modalidadSelect.addEventListener('change', actualizarCampos);

  // Ejecutar al cargar (para modo edición)
  actualizarCampos();
})();
