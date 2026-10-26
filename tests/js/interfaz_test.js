// Prueba de los scripts de la interfaz con un DOM simulado (Node, sin dependencias):
// app.js (confirmar antes de enviar, imprimir), modales.js, gestion-torneo.js, bracket.js, registro.js,
// correcciones.js y proximos-partidos.js.
//
// Antes esos comportamientos eran scripts escritos DENTRO de cada página y manejadores `onclick="…"` en el HTML;
// con la política de seguridad de contenido (core/Csp.php) ya no pueden estar ahí. Este test los ejecuta de verdad:
// qué pasa al abrir un diálogo, al cancelar una confirmación, al elegir un partido del bracket…
//
// Ejecutar (hace falta Node 18 o más nuevo; no se instala nada):
//   node tests/js/interfaz_test.js
const { crearDocumento, nuevo, crearVentana, correr } = require('./dom_falso.js');

let fallos = 0;
const ok = (nombre, cond, detalle = '') => { console.log((cond ? '  OK    ' : '  FALLA ') + nombre + (cond ? '' : '  ' + detalle)); if (!cond) fallos++; };

// ─────────────────────────────────────────────────────────────────────────────
console.log('app.js — confirmar y imprimir');
{
  const doc = crearDocumento();
  const form = nuevo(doc, 'form', { action: '/admin/modulos/toggle/1', 'data-confirmar': '¿Cambiar estado del módulo?' });
  const libre = nuevo(doc, 'form', { action: '/admin/otra' });
  const boton = nuevo(doc, 'button', { 'data-imprimir': '' });
  const dentro = nuevo(doc, 'span', {}, boton);
  const v = crearVentana(doc);
  correr('app.js', v);

  let ev = form.despachar('submit');
  ok('un formulario con data-confirmar pregunta con su texto', v.confirmaciones.length === 1 && v.confirmaciones[0] === '¿Cambiar estado del módulo?', JSON.stringify(v.confirmaciones));
  ok('si se acepta, el envío sigue', ev.defaultPrevented === false);

  v.respuestaConfirm = false;
  ev = form.despachar('submit');
  ok('si se cancela, el formulario NO se envía', ev.defaultPrevented === true);

  ev = libre.despachar('submit');
  ok('un formulario sin data-confirmar no pregunta nada', v.confirmaciones.length === 2 && ev.defaultPrevented === false);

  dentro.despachar('click');
  ok('data-imprimir abre el diálogo de impresión (también con un clic en un hijo)', v.impresiones === 1, v.impresiones);
  nuevo(doc, 'a').despachar('click');
  ok('un clic en otro lado no imprime', v.impresiones === 1);
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('modales.js');
{
  const doc = crearDocumento();
  const abrir = nuevo(doc, 'button', { 'data-abrir-modal': 'm1' });
  const m1 = nuevo(doc, 'div', { id: 'm1', class: 'modal-fondo', 'data-cierra-fuera': '', 'aria-hidden': 'true' });
  const tarjeta = nuevo(doc, 'div', { class: 'card' }, m1);
  const cancelar = nuevo(doc, 'button', { 'data-cerrar-modal': '' }, tarjeta);
  const m2 = nuevo(doc, 'div', { id: 'm2', class: 'modal-fondo', 'aria-hidden': 'true' });   // sin data-cierra-fuera
  const v = crearVentana(doc);
  correr('modales.js', v);

  abrir.despachar('click');
  ok('un botón data-abrir-modal abre el diálogo (clase abierto y aria-hidden=false)', m1.clases().includes('abierto') && m1.getAttribute('aria-hidden') === 'false');
  cancelar.despachar('click');
  ok('data-cerrar-modal lo cierra', !m1.clases().includes('abierto') && m1.getAttribute('aria-hidden') === 'true');

  v.FlexModales.abrir('m1');
  tarjeta.despachar('click');
  ok('un clic DENTRO de la tarjeta no lo cierra', m1.clases().includes('abierto'));
  m1.despachar('click');
  ok('un clic en el fondo lo cierra (data-cierra-fuera)', !m1.clases().includes('abierto'));

  v.FlexModales.abrir('m2');
  m2.despachar('click');
  ok('un diálogo sin data-cierra-fuera no se cierra con un clic en el fondo', m2.clases().includes('abierto'));
  v.FlexModales.cerrar('m2');
  ok('FlexModales.cerrar() lo cierra por código', !m2.clases().includes('abierto'));
  ok('abrir/cerrar un id que no existe no rompe', (() => { v.FlexModales.abrir('nada'); v.FlexModales.cerrar('nada'); return true; })());
}

console.log('modales.js — teclado y foco');
{
  const doc = crearDocumento();
  const abre = nuevo(doc, 'button', { 'data-abrir-modal': 'm1' });
  const fuera = nuevo(doc, 'button', { id: 'fuera' });
  const m1 = nuevo(doc, 'div', { id: 'm1', class: 'modal-fondo', 'aria-hidden': 'true' });
  const tarjeta = nuevo(doc, 'div', { class: 'card', role: 'dialog', 'aria-modal': 'true' }, m1);
  nuevo(doc, 'button', { id: 'cerrar-arriba', type: 'button' }, tarjeta);
  const campo = nuevo(doc, 'input', { id: 'campo', type: 'text' }, tarjeta);
  nuevo(doc, 'input', { id: 'oculto', type: 'hidden' }, tarjeta);
  const ultimo = nuevo(doc, 'button', { id: 'guardar', type: 'submit' }, tarjeta);
  const v = crearVentana(doc);
  correr('modales.js', v);

  abre.focus();
  abre.click();
  ok('al abrir, el foco va al primer CAMPO del diálogo (no al primer botón)', doc.activeElement === campo, doc.activeElement.id);

  let ev = doc.despachar('keydown', { key: 'Tab', shiftKey: false });
  ok('Tab desde el último control vuelve al primero', (() => { ultimo.focus(); ev = doc.despachar('keydown', { key: 'Tab', shiftKey: false }); return ev.defaultPrevented && doc.activeElement.id === 'cerrar-arriba'; })(), doc.activeElement.id);
  ok('Mayús+Tab desde el primero va al último', (() => { doc.getElementById('cerrar-arriba').focus(); ev = doc.despachar('keydown', { key: 'Tab', shiftKey: true }); return ev.defaultPrevented && doc.activeElement.id === 'guardar'; })(), doc.activeElement.id);
  ok('Tab en el medio sigue su camino normal (no se frena)', (() => { campo.focus(); ev = doc.despachar('keydown', { key: 'Tab', shiftKey: false }); return !ev.defaultPrevented; })());
  ok('si el foco se fue afuera del diálogo, Tab lo trae de vuelta', (() => { fuera.focus(); ev = doc.despachar('keydown', { key: 'Tab', shiftKey: false }); return ev.defaultPrevented && doc.activeElement.id === 'cerrar-arriba'; })(), doc.activeElement.id);

  doc.despachar('keydown', { key: 'Escape' });
  ok('Escape cierra el diálogo', !m1.clases().includes('abierto') && m1.getAttribute('aria-hidden') === 'true');
  ok('y el foco vuelve al botón que lo abrió', doc.activeElement === abre, doc.activeElement.id);

  doc.despachar('keydown', { key: 'Escape' });
  ok('Escape sin diálogo abierto no hace nada', true);
  ev = doc.despachar('keydown', { key: 'Tab', shiftKey: false });
  ok('con el diálogo cerrado, Tab no se toca', !ev.defaultPrevented);

  v.FlexModales.abrir('m1', fuera);
  v.FlexModales.cerrar('m1');
  ok('si se dijo un origen, el foco vuelve a ese origen', doc.activeElement === fuera, doc.activeElement.id);
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('gestion-torneo.js — los diálogos de cargar, corregir y programar');
function paginaGestion({ conTituloCargar = true, prefijoCorregir = null } = {}) {
  const doc = crearDocumento();
  const btnCargar = nuevo(doc, 'button', { 'data-modal-cargar': '', 'data-enf-id': '146', 'data-nombre-a': 'Valentina García', 'data-nombre-b': 'Sofía Méndez' });
  const btnCorregir = nuevo(doc, 'button', { 'data-modal-corregir': '', 'data-enf-id': '134', 'data-nombre-a': 'Ana', 'data-nombre-b': 'Beto', 'data-puntos-a': '3', 'data-puntos-b': '1' });
  const btnProgramar = nuevo(doc, 'button', { 'data-modal-programar': '', 'data-enf-id': '150', 'data-nombre-a': 'Cami', 'data-nombre-b': 'Dani', 'data-fecha': '2030-05-01T18:30' });
  const sinNombres = nuevo(doc, 'button', { 'data-modal-cargar': '', 'data-enf-id': '9' });
  for (const [id, extra] of [['modalCargar', {}], ['modalCorregir', {}], ['modalProgramar', {}]]) nuevo(doc, 'div', { id, class: 'modal-fondo', 'aria-hidden': 'true', ...extra });
  nuevo(doc, 'input', { id: 'cargarEnfId' }); nuevo(doc, 'input', { id: 'cargarPuntosA' }).value = '7'; nuevo(doc, 'input', { id: 'cargarPuntosB' }).value = '8';
  nuevo(doc, 'label', { id: 'lblCargarA' }); nuevo(doc, 'label', { id: 'lblCargarB' });
  if (conTituloCargar) nuevo(doc, 'h3', { id: 'modalCargarTitulo', 'data-prefijo-titulo': '' });
  nuevo(doc, 'input', { id: 'corregirEnfId' }); nuevo(doc, 'input', { id: 'corregirPuntosA' }); nuevo(doc, 'input', { id: 'corregirPuntosB' });
  nuevo(doc, 'label', { id: 'lblCorregirA' }); nuevo(doc, 'label', { id: 'lblCorregirB' });
  nuevo(doc, 'h3', { id: 'modalCorregirTitulo', ...(prefijoCorregir === null ? {} : { 'data-prefijo-titulo': prefijoCorregir }) }).textContent = 'Corregir resultado';
  nuevo(doc, 'input', { id: 'programarEnfId' }); nuevo(doc, 'input', { id: 'programarFecha' });
  nuevo(doc, 'h3', { id: 'modalProgramarTitulo', 'data-prefijo-titulo': 'Programar · ' });
  const v = crearVentana(doc);
  correr('modales.js', v);
  correr('gestion-torneo.js', v);
  const el = (id) => doc.getElementById(id);
  return { doc, v, el, btnCargar, btnCorregir, btnProgramar, sinNombres };
}
{
  const p = paginaGestion();
  p.btnCargar.click();
  ok('cargar: completa el partido, los nombres y el título', p.el('cargarEnfId').value === '146' && p.el('lblCargarA').textContent === 'Valentina García' && p.el('lblCargarB').textContent === 'Sofía Méndez' && p.el('modalCargarTitulo').textContent === 'Valentina García vs Sofía Méndez');
  ok('cargar: deja los puntos en blanco y abre el diálogo', p.el('cargarPuntosA').value === '' && p.el('cargarPuntosB').value === '' && p.el('modalCargar').clases().includes('abierto'));
  ok('cargar: no abre los otros diálogos', !p.el('modalCorregir').clases().includes('abierto') && !p.el('modalProgramar').clases().includes('abierto'));

  p.sinNombres.click();
  ok('cargar sin nombres usa «Local» y «Visitante»', p.el('lblCargarA').textContent === 'Local' && p.el('lblCargarB').textContent === 'Visitante');

  p.btnCorregir.click();
  ok('corregir: completa el partido, los nombres y los puntos actuales', p.el('corregirEnfId').value === '134' && p.el('lblCorregirA').textContent === 'Ana' && p.el('corregirPuntosA').value === '3' && p.el('corregirPuntosB').value === '1');
  ok('corregir: sin data-prefijo-titulo el título queda como estaba', p.el('modalCorregirTitulo').textContent === 'Corregir resultado');
  ok('corregir: abre su diálogo', p.el('modalCorregir').clases().includes('abierto'));

  p.btnProgramar.click();
  ok('programar: partido, fecha y título «Programar · A vs B»', p.el('programarEnfId').value === '150' && p.el('programarFecha').value === '2030-05-01T18:30' && p.el('modalProgramarTitulo').textContent === 'Programar · Cami vs Dani');
  ok('programar: abre su diálogo', p.el('modalProgramar').clases().includes('abierto'));
}
{
  const org = paginaGestion({ prefijoCorregir: 'Solicitar corrección · ' });
  org.btnCorregir.click();
  ok('un título con data-prefijo-titulo lleva su prefijo («Solicitar corrección · A vs B»)', org.el('modalCorregirTitulo').textContent === 'Solicitar corrección · Ana vs Beto');
  const sinTitulo = paginaGestion({ conTituloCargar: false });
  sinTitulo.btnCargar.click();
  ok('si la página no tiene título dinámico, no falla', sinTitulo.el('modalCargar').clases().includes('abierto'));
  org.btnProgramar.dataset.fecha = '';
  org.btnProgramar.click();
  ok('programar sin fecha deja el campo vacío', org.el('programarFecha').value === '');
  ok('FlexGestion queda a mano del menú del bracket', typeof org.v.FlexGestion.cargar === 'function' && typeof org.v.FlexGestion.programar === 'function');
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('bracket.js — el menú de acciones');
function paginaBracket() {
  const p = paginaGestion();
  const tarjeta = nuevo(p.doc, 'div', { class: 'bk-card clickable', 'aria-expanded': 'false' });
  tarjeta.caja = { top: 100, bottom: 160, left: 300, right: 500 };
  const menu = nuevo(p.doc, 'div', { class: 'bk-action-menu', 'aria-hidden': 'true' }, tarjeta);
  menu.offsetHeight = 80; menu.offsetWidth = 150;
  const bCargar = nuevo(p.doc, 'button', { 'data-action': 'cargar', 'data-enf-id': '274', 'data-nombre-a': 'Valentina García', 'data-nombre-b': 'Sofía Méndez' }, menu);
  const bProg = nuevo(p.doc, 'button', { 'data-action': 'programar', 'data-enf-id': '274', 'data-nombre-a': 'Valentina García', 'data-nombre-b': 'Sofía Méndez', 'data-fecha': '' }, menu);
  const otra = nuevo(p.doc, 'div', { class: 'bk-card clickable', 'aria-expanded': 'false' });
  otra.caja = { top: 300, bottom: 360, left: 300, right: 500 };
  const menu2 = nuevo(p.doc, 'div', { class: 'bk-action-menu', 'aria-hidden': 'true' }, otra);
  menu2.offsetHeight = 80; menu2.offsetWidth = 150;
  correr('bracket.js', p.v);
  return { ...p, tarjeta, menu, bCargar, bProg, otra, menu2 };
}
{
  const b = paginaBracket();
  ok('al cargar la página los menús arrancan cerrados', b.menu.style.display === 'none' && b.menu.getAttribute('aria-hidden') === 'true' && b.menu2.style.display === 'none');

  b.tarjeta.click();
  ok('un clic en la tarjeta abre su menú, con aria', b.menu.style.display === 'block' && b.menu.getAttribute('aria-hidden') === 'false' && b.tarjeta.getAttribute('aria-expanded') === 'true' && b.menu.dataset.portal === 'true');
  ok('el menú se coloca fijo, debajo de la tarjeta y alineado a su borde derecho', b.menu.style.position === 'fixed' && b.menu.style.top === '166px' && b.menu.style.left === '342px', JSON.stringify(b.menu.style));
  ok('el foco va al primer botón', b.bCargar.focoRecibido === true);

  b.tarjeta.click();
  ok('otro clic en la tarjeta lo cierra', b.menu.style.display === 'none' && b.tarjeta.getAttribute('aria-expanded') === 'false');

  b.tarjeta.click();
  b.otra.click();
  ok('abrir otra tarjeta cierra la anterior', b.menu.style.display === 'none' && b.menu2.style.display === 'block');
  b.otra.click();

  // sin lugar abajo: se abre hacia arriba
  b.v.innerHeight = 200;
  b.tarjeta.click();
  ok('si abajo no entra y arriba sí, se abre hacia arriba', b.menu.style.top === '14px', b.menu.style.top);
  b.tarjeta.click();
  b.v.innerHeight = 800;

  // contra el borde izquierdo de la ventana
  b.tarjeta.caja = { top: 100, bottom: 160, left: 0, right: 100 };
  b.tarjeta.click();
  ok('el menú no se sale de la ventana por la izquierda', b.menu.style.left === '8px', b.menu.style.left);
  b.tarjeta.click();

  b.tarjeta.click();
  b.bCargar.despachar('click');
  ok('el botón «cargar» abre el diálogo de cargar resultado con los datos del partido', b.el('cargarEnfId').value === '274' && b.el('lblCargarA').textContent === 'Valentina García' && b.el('modalCargar').clases().includes('abierto'));
  ok('y el menú se cierra', b.menu.style.display === 'none');

  b.tarjeta.click();
  b.bProg.despachar('click');
  ok('el botón «programar» abre el de programar', b.el('programarEnfId').value === '274' && b.el('modalProgramarTitulo').textContent === 'Programar · Valentina García vs Sofía Méndez' && b.el('modalProgramar').clases().includes('abierto'));

  b.tarjeta.click();
  b.doc.despachar('keydown', { key: 'Escape' });
  ok('Escape cierra el menú', b.menu.style.display === 'none');
  b.tarjeta.click();
  nuevo(b.doc, 'p').click();
  ok('un clic fuera de las tarjetas cierra el menú', b.menu.style.display === 'none');
  b.tarjeta.click();
  b.v.escuchas.scroll.forEach((f) => f());
  ok('el scroll cierra el menú (si no, quedaría descolocado)', b.menu.style.display === 'none');
  const e = b.tarjeta.despachar('keydown', { key: 'Enter', preventDefault() {} });
  ok('Enter sobre la tarjeta enfocada la abre', b.menu.style.display === 'block');
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('registro.js — las reglas de la contraseña y la casilla de la política de privacidad');
{
  const doc = crearDocumento();
  const clave = nuevo(doc, 'input', { id: 'password' });
  const conf = nuevo(doc, 'input', { id: 'password_confirm' });
  const reglas = nuevo(doc, 'ul', { id: 'pwHints' });
  const li = {};
  for (const r of ['len', 'upper', 'lower', 'num', 'sym', 'match']) li[r] = nuevo(doc, 'li', { 'data-rule': r }, reglas);
  const boton = nuevo(doc, 'button', { id: 'submitBtn' });
  const acepto = nuevo(doc, 'input', { id: 'acepto_politicas' });
  acepto.checked = false;
  const v = crearVentana(doc);
  correr('registro.js', v);
  const estado = () => Object.fromEntries(Object.entries(li).map(([r, e]) => [r, e.clases().includes('cumple')]));

  ok('al cargar, ninguna regla se cumple y el botón está apagado', Object.values(estado()).every((x) => !x) && boton.disabled === true && boton.clases().includes('apagado'));

  clave.value = 'abc';
  clave.despachar('input');
  ok('«abc» cumple solo la minúscula', JSON.stringify(estado()) === JSON.stringify({ len: false, upper: false, lower: true, num: false, sym: false, match: false }), JSON.stringify(estado()));

  clave.value = 'Abcdef1!'; conf.value = 'Abcdef1!';
  clave.despachar('input');
  ok('una clave completa y repetida cumple todas las reglas, pero sin aceptar la política el botón sigue apagado', Object.values(estado()).every(Boolean) && boton.disabled === true && boton.clases().includes('apagado'));
  acepto.checked = true;
  acepto.despachar('change');
  ok('al marcar la casilla de la política, con la clave completa, se habilita el botón', boton.disabled === false && !boton.clases().includes('apagado'));
  acepto.checked = false;
  acepto.despachar('change');
  ok('al desmarcarla, se apaga otra vez aunque la clave esté bien', boton.disabled === true && Object.values(estado()).every(Boolean));
  acepto.checked = true;
  acepto.despachar('change');
  ok('cada regla guarda su estado en data-ok', li.len.dataset.ok === '1');

  conf.value = 'Abcdef1?';
  conf.despachar('input');
  ok('si la confirmación no coincide, se apaga otra vez (aunque la casilla esté marcada)', estado().match === false && boton.disabled === true && acepto.checked === true);

  clave.value = 'Ñandú2025#'; conf.value = 'Ñandú2025#';
  clave.despachar('input');
  ok('acepta mayúsculas y minúsculas con tildes y eñes', Object.values(estado()).every(Boolean));
}
{
  const vacia = crearVentana(crearDocumento());
  ok('en una página sin ese formulario, no hace nada ni falla', (() => { correr('registro.js', vacia); return true; })());
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('correcciones.js — rechazar una solicitud');
{
  const doc = crearDocumento();
  const form = nuevo(doc, 'form', { id: 'formRechazar', action: '' });
  const b51 = nuevo(doc, 'button', { 'data-rechazar': '', 'data-id': '51', 'data-abrir-modal': 'modalRechazar' });
  const b7 = nuevo(doc, 'button', { 'data-rechazar': '', 'data-id': '7"><x', 'data-abrir-modal': 'modalRechazar' });
  const modal = nuevo(doc, 'div', { id: 'modalRechazar', class: 'modal-fondo' });
  const v = crearVentana(doc);
  correr('modales.js', v);
  correr('correcciones.js', v);
  b51.click();
  ok('el formulario apunta a la solicitud elegida y el diálogo se abre', form.action === '/admin/correcciones/51/rechazar' && modal.clases().includes('abierto'), form.action);
  b7.click();
  ok('el id se codifica (no se puede armar otra dirección)', form.action === '/admin/correcciones/7%22%3E%3Cx/rechazar', form.action);
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('proximos-partidos.js — la lista de la ficha del torneo');
function paginaProximos(partidos, mostrarDatos = true) {
  const doc = crearDocumento();
  const cont = nuevo(doc, 'div', { id: 'calendarMatches' });
  if (mostrarDatos) nuevo(doc, 'script', { id: 'partidos-calendario', type: 'application/json' }).textContent = JSON.stringify(partidos);
  const v = crearVentana(doc);
  const reales = Date;
  correr('formato.js', v); correr('proximos-partidos.js', v);
  return { cont, doc, v };
}
{
  const hoy = new Date();
  const f = (dias, hora = '') => { const d = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() + dias); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}${hora}`; };

  let p = paginaProximos([]);
  ok('sin partidos: «No hay partidos programados.»', p.cont.innerHTML.includes('No hay partidos programados.') && p.cont.innerHTML.includes('proximo-vacio'));

  p = paginaProximos([{ a: 'A', b: 'B', ronda: 1, orden: 1, fecha: f(-3) }]);
  ok('solo partidos pasados: «No hay próximos partidos programados.»', p.cont.innerHTML.includes('No hay próximos partidos programados.'));

  p = paginaProximos([{ a: 'A', b: 'B', ronda: 1, orden: 1, fecha: f(-3) }, { a: 'C', b: 'D', ronda: 2, orden: 3, fecha: f(5, ' 18:30:00') }, { a: 'E', b: 'F', ronda: 2, orden: 1, fecha: f(2) }, { a: 'G', b: 'H', ronda: 3, orden: 1, fecha: null }]);
  const lista = p.cont.hijos[0];
  ok('muestra solo los partidos de hoy en adelante, del más cercano al más lejano', lista && lista.className === 'proximos-lista' && lista.hijos.length === 2, String(lista && lista.hijos.length));
  const primero = lista.hijos[0]; const segundo = lista.hijos[1];
  ok('cada partido es una tarjeta con equipos, ronda/orden y fecha', primero.className === 'card proximo' && primero.hijos[0].innerHTML.includes('E') && primero.hijos[1].innerHTML === 'Ronda 2 · Orden 1');
  ok('la fecha con hora lleva la hora («· 18:30»)', segundo.hijos[2].textContent.includes('· 18:30'), segundo.hijos[2].textContent);
  ok('la tarjeta no lleva estilos en línea', Object.keys(primero.style).length === 0 && !primero.hasAttribute('style'));

  p = paginaProximos([{ a: '<img src=x onerror=alert(1)>', b: '"><script>x</script>', ronda: '<b>', orden: 1, fecha: f(1) }]);
  const eq = p.cont.hijos[0].hijos[0].hijos[0].innerHTML;
  ok('los nombres se escapan (no se inyecta HTML)', !eq.includes('<img') && eq.includes('&lt;img src=x onerror=alert(1)&gt;') && eq.includes('&quot;&gt;&lt;script&gt;'), eq);
  ok('la ronda también', p.cont.hijos[0].hijos[0].hijos[1].innerHTML.includes('&lt;b&gt;'));

  p = paginaProximos(null, false);
  ok('sin el bloque JSON no hace nada ni falla', p.cont.innerHTML === '' && p.cont.hijos.length === 0);
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('calendar.js — el calendario público');
function paginaCalendario(torneos, conDatos = true) {
  const doc = crearDocumento();
  const destino = nuevo(doc, 'div', { id: 'calendar' });
  if (conDatos) nuevo(doc, 'script', { id: 'torneos-cal', type: 'application/json' }).textContent = typeof torneos === 'string' ? torneos : JSON.stringify(torneos);
  const v = crearVentana(doc);
  correr('formato.js', v); correr('calendar.js', v);
  const por = (clase) => destino.descendientes().filter((e) => e.clases().includes(clase));
  // cada clic vuelve a dibujar todo: los botones se buscan de nuevo cada vez
  const pulsar = (nombre) => destino.descendientes().find((e) => e.tagName === 'BUTTON' && e.getAttribute('aria-label') === nombre).onclick();
  return { doc, destino, por, pulsar, v };
}
{
  const hoy = new Date();
  const anio = hoy.getFullYear(); const mes = hoy.getMonth();
  const dos = (n) => String(n).padStart(2, '0');
  const f = (dia, m = mes) => { const d = new Date(anio, m, dia); return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`; };
  const diasDelMes = new Date(anio, mes + 1, 0).getDate();
  const huecos = (new Date(anio, mes, 1).getDay() + 6) % 7;
  const nombreMes = (m) => new Date(anio, m, 1).toLocaleString('es', { month: 'long', year: 'numeric' });
  const torneos = [
    { id: 9, nombre: 'Copa Primavera', inicio: f(12), fin: f(20) },
    { id: 4, nombre: 'Abierto de Ajedrez', inicio: f(12), fin: f(12) },
    { id: 7, nombre: '<img src=x onerror=alert(1)>', inicio: f(3), fin: f(5) },
    { id: 11, nombre: 'Del mes que viene', inicio: f(5, mes + 1), fin: f(9, mes + 1) },
    { id: 12, nombre: 'Del mes pasado', inicio: f(28, mes - 1), fin: f(2) },
  ];

  const c = paginaCalendario(torneos);
  const botones = c.destino.descendientes().filter((e) => e.tagName === 'BUTTON');
  ok('la cabecera tiene los dos botones de mes, con nombre accesible y sin enviar nada', botones.length === 2 && botones[0].getAttribute('aria-label') === 'Mes anterior' && botones[1].getAttribute('aria-label') === 'Mes siguiente' && botones.every((b) => b.type === 'button'));
  const titulo = c.por('cal-mes')[0];
  ok('el mes y el año salen en español, y el cambio se anuncia (aria-live)', titulo.hijos[0].textContent === nombreMes(mes) && titulo.getAttribute('aria-live') === 'polite', titulo.hijos[0].textContent);
  ok('la grilla lleva los siete días de la semana, desde el lunes', c.por('cal-dia-semana').map((e) => e.textContent).join(',') === 'Lun,Mar,Mié,Jue,Vie,Sáb,Dom');
  ok('una celda por cada día del mes', c.por('cal-dia').length === diasDelMes, `${c.por('cal-dia').length} contra ${diasDelMes}`);
  ok('el día 1 cae en su columna (huecos antes del primer día)', c.por('cal-grilla')[0].hijos.length === 7 + huecos + diasDelMes, `${c.por('cal-grilla')[0].hijos.length} contra ${7 + huecos + diasDelMes}`);
  const celda = (dia) => c.por('cal-dia').find((e) => e.hijos[0].textContent === String(dia));
  ok('un torneo está en el día en que EMPIEZA, y solo ahí', celda(12).querySelectorAll('.cal-enlace').length === 2 && celda(13).querySelectorAll('.cal-enlace').length === 0 && celda(20).querySelectorAll('.cal-enlace').length === 0);
  const enl = celda(12).querySelectorAll('.cal-enlace');
  ok('cada torneo es un enlace a su ficha, con el nombre completo como ayuda', enl[0].href === '/torneo/9' && enl[0].title === 'Copa Primavera', enl[0].href + ' ' + enl[0].title);
  ok('la tarjeta dice el nombre y el rango de fechas', enl[0].querySelector('.cal-titulo').textContent === 'Copa Primavera' && enl[0].querySelector('.cal-rango').textContent === `12/${dos(mes + 1)} – 20/${dos(mes + 1)}`, enl[0].querySelector('.cal-rango').textContent);
  ok('un torneo de un solo día muestra una sola fecha', enl[1].querySelector('.cal-rango').textContent === `12/${dos(mes + 1)}`, enl[1].querySelector('.cal-rango').textContent);
  ok('en pantalla chica cada día lleva un punto por torneo (decorativo para los lectores)', celda(12).querySelectorAll('.cal-punto').length === 2 && celda(12).querySelector('.cal-puntos').getAttribute('aria-hidden') === 'true' && celda(13).querySelectorAll('.cal-punto').length === 0);
  ok('un nombre con HTML se muestra como texto (no se inyecta nada)', celda(3).querySelector('.cal-titulo').textContent === '<img src=x onerror=alert(1)>' && celda(3).querySelector('.cal-titulo').innerHTML === '');
  ok('el día de hoy lleva su marca, y solo ese', c.por('hoy').length === 1 && c.por('hoy')[0].hijos[0].textContent === String(hoy.getDate()));
  ok('ningún elemento lleva estilos en línea (la CSP no los admite)', c.destino.descendientes().every((e) => Object.keys(e.style).length === 0 && !e.hasAttribute('style')));

  const agenda = c.por('cal-agenda')[0];
  const nombresLista = (cal) => cal.por('cal-agenda')[0].querySelectorAll('strong').map((e) => e.textContent);
  ok('la lista del mes: los que empiezan ese mes, por fecha y luego por nombre', nombresLista(c).join(' | ') === '<img src=x onerror=alert(1)> | Abierto de Ajedrez | Copa Primavera', nombresLista(c).join(' | '));
  const filaCopa = agenda.querySelectorAll('li')[2];
  ok('cada fila enlaza a la ficha y muestra el día de inicio', filaCopa.querySelector('a').href === '/torneo/9' && filaCopa.querySelector('.chip').textContent === `12/${dos(mes + 1)}`, filaCopa.querySelector('.chip').textContent);
  ok('el título de la lista es un h2 (sin saltear niveles)', agenda.querySelector('h2').textContent === 'Torneos que empiezan este mes');

  c.pulsar('Mes siguiente');
  ok('«mes siguiente» muestra el mes que viene, con su grilla y su título', c.por('cal-dia').length === new Date(anio, mes + 2, 0).getDate() && c.por('cal-mes')[0].hijos[0].textContent === nombreMes(mes + 1));
  ok('y su lista trae solo lo de ese mes', nombresLista(c).join('|') === 'Del mes que viene', nombresLista(c).join('|'));
  ok('en otro mes no hay marca de «hoy»', c.por('hoy').length === 0);
  c.pulsar('Mes anterior'); c.pulsar('Mes anterior');
  ok('«mes anterior» llega al mes pasado, con lo suyo', c.por('cal-mes')[0].hijos[0].textContent === nombreMes(mes - 1) && nombresLista(c).join('|') === 'Del mes pasado', nombresLista(c).join('|'));
  c.pulsar('Mes siguiente');
  for (let i = 0; i < 12; i++) c.pulsar('Mes siguiente');
  ok('doce meses después es el mismo mes del año que viene (no se saltea ni se repite ninguno)', c.por('cal-mes')[0].hijos[0].textContent === new Date(anio + 1, mes, 1).toLocaleString('es', { month: 'long', year: 'numeric' }), c.por('cal-mes')[0].hijos[0].textContent);

  const vacio = paginaCalendario([]);
  ok('sin torneos: se dibuja la grilla y la lista lo dice', vacio.por('cal-dia').length === diasDelMes && vacio.por('cal-agenda')[0].querySelector('p').textContent === 'No hay torneos que empiecen este mes.');
  ok('y sin torneos tampoco hay puntos ni enlaces', vacio.por('cal-punto').length === 0 && vacio.por('cal-enlace').length === 0);
  const roto = paginaCalendario('esto no es json');
  ok('un JSON roto no rompe la página: calendario vacío', roto.por('cal-dia').length === diasDelMes);
  const sinBloque = paginaCalendario([], false);
  ok('sin el bloque de datos tampoco falla', sinBloque.por('cal-dia').length === diasDelMes);
  const raros = paginaCalendario([null, { id: 1 }, { id: 2, nombre: 'Sin fin', inicio: f(8) }, { id: 3, nombre: 'Fecha mala', inicio: 20261012 }]);
  ok('las entradas incompletas se ignoran y la válida se dibuja con una sola fecha', raros.por('cal-enlace').length === 1 && raros.por('cal-rango')[0].textContent === `08/${dos(mes + 1)}`, raros.por('cal-rango').map((e) => e.textContent).join(','));
  const muchos = paginaCalendario([1, 2, 3, 4, 5].map((i) => ({ id: i, nombre: 'T' + i, inicio: f(15), fin: f(16) })));
  const celda15 = muchos.por('cal-dia').find((e) => e.hijos[0].textContent === '15');
  ok('cinco torneos el mismo día: cinco tarjetas pero a lo sumo tres puntos', celda15.querySelectorAll('.cal-enlace').length === 5 && celda15.querySelectorAll('.cal-punto').length === 3);
  ok('una página sin #calendar no hace nada ni falla', (() => { { const w = crearVentana(crearDocumento()); correr('formato.js', w); correr('calendar.js', w); } return true; })());
}

console.log(fallos === 0 ? '\nTodo OK' : `\n${fallos} fallo(s)`);
process.exit(fallos === 0 ? 0 : 1);
