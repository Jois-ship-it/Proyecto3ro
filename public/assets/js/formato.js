/* FlexArena — formato.js
 *
 * Cómo se escriben las fechas, los rangos y los estados en el navegador, en un solo lugar. Antes cada script traía su propia copia
 * (el calendario y los próximos partidos separaban y rellenaban fechas cada uno a su manera); ahora lo usan los tres que dibujan
 * datos: calendar.js, proximos-partidos.js y torneos-en-vivo.js. Va ANTES de ellos en la lista `extraJs` del controlador.
 *
 * Nada acá crea fechas con `new Date('2026-10-12')`: ese texto se interpreta en UTC y, según la zona horaria, corre el día. Las fechas
 * se separan en partes y, cuando hace falta un Date, se arma con (año, mes, día), que es hora local.
 *
 * Los estados repiten `View::estadoChip()` (PHP) —mismo texto, misma clase—, así una tarjeta que dibuja el navegador queda igual a
 * la que dibujó el servidor. tests/js/formato_test.js prueba las dos mitades con los mismos valores.
 */
'use strict';

const FlexFormato = (() => {
  const dos = (n) => String(n).padStart(2, '0');

  /**
   * «2026-10-12», «2026-10-12 18:30:00» o «2026-10-12T18:30» → {anio, mes, dia, hora, minuto, segundo}.
   * null si lo que llega no es una fecha (vacío, null, «hoy», «0000-00-00»).
   */
  function partesDeFecha(entrada) {
    if (entrada === null || entrada === undefined) return null;
    const cruda = String(entrada).trim();
    if (!cruda) return null;

    const soloFecha = cruda.includes('T') ? cruda.split('T')[0] : cruda.split(' ')[0];
    const [anio, mes, dia] = soloFecha.split('-').map(Number);
    if (!anio || !mes || !dia) return null;

    const horaCruda = cruda.includes('T') ? (cruda.split('T')[1] || '00:00:00') : (cruda.includes(' ') ? (cruda.split(' ')[1] || '00:00:00') : '00:00:00');
    const [hora = '00', minuto = '00', segundo = '00'] = (horaCruda || '00:00:00').split(':').map(String);

    return { anio, mes, dia, hora: Number(hora), minuto: Number(minuto), segundo: Number(segundo) };
  }

  /** «2026-10-12» → «12/10», sin crear fechas. Lo que no tiene forma de fecha se devuelve como vino. */
  function diaMes(texto) {
    if (!texto) return '';
    const partes = String(texto).split('-');
    return partes.length < 3 ? String(texto) : `${dos(partes[2])}/${dos(partes[1])}`;
  }

  /** «12/10 – 20/10»; con una sola fecha, o con inicio y fin iguales, solo «12/10». */
  function rango(inicio, fin) {
    if (!inicio) return '';
    if (!fin || inicio === fin) return diaMes(inicio);
    return `${diaMes(inicio)} – ${diaMes(fin)}`;
  }

  /** «Lun 12 de oct», y con hora «Lun 12 de oct · 18:30». Sin fecha válida: «Sin fecha». */
  function etiquetaDeFecha(valor) {
    const partes = partesDeFecha(valor);
    if (!partes) return 'Sin fecha';

    const dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const fecha = new Date(partes.anio, partes.mes - 1, partes.dia);
    const dia = dias[fecha.getDay()];
    const mes = meses[fecha.getMonth()];
    const base = `${dia.charAt(0).toUpperCase() + dia.slice(1)} ${fecha.getDate()} de ${mes}`;

    const tieneHora = partes.hora !== 0 || partes.minuto !== 0 || partes.segundo !== 0;
    return tieneHora ? `${base} · ${dos(partes.hora)}:${dos(partes.minuto)}` : base;
  }

  /** «2026-10-24 → 2026-11-23», con «—» donde falte una de las dos (así lo escribe la lista del servidor). */
  function periodo(inicio, fin) {
    return `${inicio || '—'} → ${fin || '—'}`;
  }

  /** La clase de color de cada estado: la misma tabla que View::estadoChip(). Lo que no está lleva el color por defecto (azul). */
  const COLOR_DEL_ESTADO = {
    activo: 'success', finalizado: 'success', en_curso: '', pendiente: 'warning', inscripcion: 'warning', corregido: 'warning',
    cerrada: 'success', borrador: 'warning', suspendido: 'danger', cancelado: 'danger', bloqueada: 'danger', inactivo: 'danger',
    rechazado: 'danger', bye: 'warning',
  };

  /** «en_curso» → {texto: 'En curso', clase: 'chip'}; «inscripcion» → {texto: 'Inscripcion', clase: 'chip warning'}. */
  function estado(valor) {
    const clave = String(valor ?? '');
    const texto = clave.replace(/_/g, ' ');
    const color = Object.prototype.hasOwnProperty.call(COLOR_DEL_ESTADO, clave) ? COLOR_DEL_ESTADO[clave] : '';
    return { texto: texto.charAt(0).toUpperCase() + texto.slice(1), clase: color ? `chip ${color}` : 'chip' };
  }

  /** «Individual», «Equipos»: la primera letra en mayúscula (lo que hace ucfirst() en PHP). */
  function mayusculaInicial(texto) {
    const s = String(texto ?? '');
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  return { dos, partesDeFecha, diaMes, rango, etiquetaDeFecha, periodo, estado, mayusculaInicial };
})();
