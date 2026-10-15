// Prueba de public/assets/js/torneos-en-vivo.js: la búsqueda en vivo de la lista pública de torneos, con un DOM simulado, un fetch
// simulado y un reloj simulado (Node, sin dependencias).
//
// Qué se comprueba: cuándo pide (después de una pausa al tipear, enseguida al elegir un filtro), qué pide (la dirección, con los
// filtros en el mismo orden que el servidor), qué dibuja (el mismo marcado que public/torneos.php y partials/paginacion.php), y los casos
// difíciles: una respuesta vieja que llega tarde, un pedido cancelado, una falla de red, el volver a lo que ya se ve.
//
// Ejecutar:  node tests/js/torneos_en_vivo_test.js
const { crearDocumento, nuevo, crearVentana, correr } = require('./dom_falso.js');

let fallos = 0;
const ok = (nombre, cond, detalle = '') => { console.log((cond ? '  OK    ' : '  FALLA ') + nombre + (cond ? '' : '  ' + detalle)); if (!cond) fallos++; };
const tick = () => new Promise((r) => setImmediate(r));          // deja correr las promesas pendientes

/** Una página de /torneos con su formulario, su lista «del servidor» y la región para los lectores de pantalla. */
function pagina({ q = '', tipo = '', estado = '', search = '', conFormulario = true, conApi = true } = {}) {
  const doc = crearDocumento();
  const form = nuevo(doc, 'form', { class: 'filtros card', method: 'GET', action: '/torneos', role: 'search', ...(conFormulario ? { 'data-en-vivo': '/api/torneos' } : {}) });
  const entrada = nuevo(doc, 'input', { id: 'f-q', type: 'search', name: 'q' }, form); entrada.value = q;
  const selTipo = nuevo(doc, 'select', { id: 'f-tipo', name: 'tipo' }, form); selTipo.value = tipo;
  const selEstado = nuevo(doc, 'select', { id: 'f-estado', name: 'estado' }, form); selEstado.value = estado;
  const acciones = nuevo(doc, 'div', { class: 'actions' }, form);
  nuevo(doc, 'button', { class: 'btn small primary', type: 'submit' }, acciones).textContent = 'Buscar';
  if (q || tipo || estado) { const l = nuevo(doc, 'a', { class: 'btn small ghost' }, acciones); l.href = '/torneos'; l.textContent = 'Limpiar'; }
  const aviso = nuevo(doc, 'p', { id: 'estado-lista', class: 'solo-lectores', role: 'status' });
  const zona = nuevo(doc, 'div', { id: 'lista-torneos' });
  nuevo(doc, 'div', { class: 'grid cols-3', 'data-servidor': '1' }, zona);

  // el reloj, el fetch y la historia simulados
  const temporizadores = []; let idTemp = 0;
  const llamadas = []; const historia = [];
  const abortado = () => { const e = new Error('The operation was aborted.'); e.name = 'AbortError'; return e; };
  const ventana = crearVentana(doc, {
    AbortController,
    location: { pathname: '/torneos', href: '/torneos' + search, search },
    history: { replaceState: (_a, _b, url) => historia.push(url) },
    setTimeout: (f, ms) => { temporizadores.push({ id: ++idTemp, f, ms }); return idTemp; },
    clearTimeout: (id) => { const i = temporizadores.findIndex((t) => t.id === id); if (i >= 0) temporizadores.splice(i, 1); },
    fetch: (url, opciones) => new Promise((resolver, rechazar) => {
      const llamada = { url, opciones, resolver, rechazar, cancelada: false };
      llamadas.push(llamada);
      if (opciones && opciones.signal) opciones.signal.addEventListener('abort', () => { llamada.cancelada = true; rechazar(abortado()); });
    }),
  });
  if (conApi) correr('api.js', ventana);
  correr('formato.js', ventana);
  correr('torneos-en-vivo.js', ventana);

  const por = (sel, desde = doc) => desde.querySelectorAll(sel);
  return {
    doc, form, entrada, selTipo, selEstado, acciones, aviso, zona, ventana, temporizadores, llamadas, historia, por,
    /** Se tipeó en «Buscar». */
    tipear(texto) { entrada.value = texto; entrada.despachar('input'); },
    /** Pasa el tiempo: corre lo que estaba esperando. */
    avanzar() { const pend = temporizadores.splice(0); pend.forEach((t) => t.f()); },
    /** El servidor contesta el pedido n con este JSON. */
    responder(n, json, estadoHttp = 200) { llamadas[n].resolver({ status: estadoHttp, json: async () => json }); },
    clases: (el) => el.clases().join(' '),
  };
}

const torneo = (id, extra = {}) => ({ id, nombre: 'Torneo ' + id, formato: { slug: 'liga', nombre: 'Liga' }, modalidad: 'individual', estado: 'inscripcion', inscritos: 5, fecha_inicio: '2026-10-24', fecha_fin: '2026-11-23', url: '/torneo/' + id, ...extra });
const respuesta = (datos, { pagina = 1, porPagina = 12, total = datos.length, filtros = { q: '', tipo: '', estado: '' } } = {}) => ({
  ok: true, datos, meta: { pagina, por_pagina: porPagina, total, paginas: Math.max(1, Math.ceil(total / porPagina)), filtros },
});

(async () => {
  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — cuándo y qué pide');
  {
    const p = pagina();
    ok('al cargar no pide nada (la lista del servidor ya está)', p.llamadas.length === 0 && p.temporizadores.length === 0);
    p.tipear('liga');
    ok('al tipear no pide enseguida: espera una pausa de 300 ms', p.llamadas.length === 0 && p.temporizadores.length === 1 && p.temporizadores[0].ms === 300);
    p.avanzar(); await tick();
    ok('pasada la pausa pide la lista a la API, con el máximo por página del sitio', p.llamadas.length === 1 && p.llamadas[0].url === '/api/torneos?q=liga&por_pagina=12', p.llamadas[0] && p.llamadas[0].url);
    ok('el pedido pide JSON como lo hace api.js', p.llamadas[0].opciones.headers['X-Requested-With'] === 'XMLHttpRequest' && p.llamadas[0].opciones.method === 'GET');
  }
  {
    const p = pagina();
    p.tipear('l'); p.tipear('li'); p.tipear('lig');
    ok('varias teclas seguidas dejan un solo temporizador (el último)', p.temporizadores.length === 1);
    p.avanzar(); await tick();
    ok('y un solo pedido, con lo último escrito', p.llamadas.length === 1 && p.llamadas[0].url === '/api/torneos?q=lig&por_pagina=12');
  }
  {
    const p = pagina();
    p.selTipo.value = 'liga'; p.selTipo.despachar('change'); await tick();
    ok('al elegir un formato pide enseguida, sin esperar', p.llamadas.length === 1 && p.temporizadores.length === 0 && p.llamadas[0].url === '/api/torneos?tipo=liga&por_pagina=12');
  }
  {
    const p = pagina();
    p.entrada.value = 'copa'; p.selTipo.value = 'liga'; p.selEstado.value = 'en_curso'; p.selEstado.despachar('change'); await tick();
    ok('los filtros viajan en el orden del servidor (q, tipo, estado)', p.llamadas[0].url === '/api/torneos?q=copa&tipo=liga&estado=en_curso&por_pagina=12', p.llamadas[0].url);
  }
  {
    const p = pagina();
    p.tipear('  ñandú & co  '); p.avanzar(); await tick();
    ok('el texto se recorta y se codifica (la dirección no lleva nada crudo)', p.llamadas[0].url === '/api/torneos?q=%C3%B1and%C3%BA+%26+co&por_pagina=12', p.llamadas[0].url);
  }
  {
    const p = pagina();
    p.tipear('a&b=c d"<'); p.avanzar(); await tick();
    ok('la misma codificación que PHP (Paginador::enlace): «a%26b%3Dc+d%22%3C»', p.llamadas[0].url === '/api/torneos?q=a%26b%3Dc+d%22%3C&por_pagina=12', p.llamadas[0].url);
  }
  {
    const p = pagina();
    p.form.despachar('submit'); await tick();
    ok('si no hay nada nuevo que pedir, «Buscar» no hace un pedido de balde', p.llamadas.length === 0);
    p.tipear('liga');
    const ev = p.form.despachar('submit'); await tick();
    ok('«Buscar» (o Enter) pide enseguida, sin recargar la página, y descarta la pausa pendiente', ev.defaultPrevented && p.llamadas.length === 1 && p.temporizadores.length === 0);
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — lo que dibuja');
  {
    const p = pagina();
    p.tipear('t'); p.avanzar(); await tick();
    ok('mientras espera la lista se atenúa y queda marcada como ocupada', p.clases(p.zona).includes('cargando') && p.zona.getAttribute('aria-busy') === 'true');
    p.responder(0, respuesta([torneo(9, { estado: 'en_curso', modalidad: 'equipos', inscritos: 8, formato: { slug: 'suizo', nombre: 'Sistema Suizo' } }), torneo(4)], { total: 2 })); await tick();
    ok('al responder deja de estar ocupada', !p.clases(p.zona).includes('cargando') && !p.zona.hasAttribute('aria-busy'));
    const grilla = p.por('.grid', p.zona);
    ok('la lista del servidor se reemplaza por una grilla de tres columnas con una tarjeta por torneo', grilla.length === 1 && p.clases(grilla[0]) === 'grid cols-3' && !p.zona.querySelector('[data-servidor]') && p.por('article', p.zona).length === 2);
    const a = p.por('article', p.zona)[0];
    ok('cada tarjeta lleva las mismas clases que la del servidor', p.clases(a) === 'card flex flex-col gap-06');
    ok('nombre y estado, con el color que da formato.js', p.clases(a.hijos[0]) === 'flex justify-between items-start gap-05' && a.hijos[0].hijos[0].tagName === 'STRONG' && p.clases(a.hijos[0].hijos[0]) === 'rompe-texto' && a.hijos[0].hijos[0].textContent === 'Torneo 9' && p.clases(a.hijos[0].hijos[1]) === 'chip' && a.hijos[0].hijos[1].textContent === 'En curso');
    ok('el estado «inscripcion» sale amarillo y sin tilde, como View::estadoChip()', p.clases(p.por('article', p.zona)[1].hijos[0].hijos[1]) === 'chip warning' && p.por('article', p.zona)[1].hijos[0].hijos[1].textContent === 'Inscripcion');
    ok('formato y modalidad, con el espacio que el servidor deja entre las dos etiquetas (sin él, 4 px corrido)', a.hijos[1].hijos[0].textContent === 'Sistema Suizo' && p.clases(a.hijos[1].hijos[0]) === 'chip' && a.hijos[1].hijos[1].tagName === '#TEXT' && a.hijos[1].hijos[1].textContent === ' ' && a.hijos[1].hijos[2].textContent === 'Equipos' && p.clases(a.hijos[1].hijos[2]) === 'muted ml-04');
    ok('participantes y período', a.hijos[2].textContent === '8 participantes' && p.clases(a.hijos[2]) === 'muted' && a.hijos[3].textContent === '2026-10-24 → 2026-11-23');
    const ver = a.hijos[4];
    ok('el enlace «Ver detalle →» va a la ficha', ver.tagName === 'A' && ver.href === '/torneo/9' && p.clases(ver) === 'btn small primary mt-auto' && ver.textContent === 'Ver detalle →');
    ok('sin una sola página no hay botones de página, pero sí el resumen', p.por('.paginacion', p.zona).length === 0 && p.por('.resumen', p.zona)[0].textContent === 'Mostrando 1–2 de 2 torneos');
    ok('el pie es el mismo contenedor que el del servidor', p.por('.listado-pie', p.zona).length === 1);
    ok('la región de los lectores de pantalla dice cuántos hay', p.aviso.textContent === '2 torneos encontrados.', p.aviso.textContent);
  }
  {
    const p = pagina();
    p.tipear('x'); p.avanzar(); await tick();
    p.responder(0, respuesta([torneo(3, { nombre: '<img src=x onerror=alert(1)>', url: 'javascript:alert(1)', fecha_inicio: null, fecha_fin: '', estado: 'raro' }), torneo('abc')], { total: 2 })); await tick();
    const a = p.por('article', p.zona)[0];
    ok('un nombre con HTML se muestra como texto', a.hijos[0].hijos[0].textContent === '<img src=x onerror=alert(1)>' && a.hijos[0].hijos[0].innerHTML === '' && p.por('img', p.zona).length === 0);
    ok('la dirección sale del número de la ficha: una «url» rara de la respuesta no se usa', a.hijos[4].href === '/torneo/3' && p.por('article', p.zona)[1].hijos[4].href === '/torneo/0');
    ok('fechas que faltan: «—», y un estado desconocido lleva el color por defecto', a.hijos[3].textContent === '— → —' && a.hijos[0].hijos[1].textContent === 'Raro' && p.clases(a.hijos[0].hijos[1]) === 'chip');
    ok('ningún elemento dibujado lleva estilos en línea (la CSP no los admite)', p.zona.descendientes().every((e) => Object.keys(e.style).length === 0 && !e.hasAttribute('style')));
  }
  {
    const p = pagina();
    p.tipear('zzz'); p.avanzar(); await tick();
    p.responder(0, respuesta([], { total: 0, filtros: { q: 'zzz', tipo: '', estado: '' } })); await tick();
    ok('sin resultados con filtros: el mismo cartel que el servidor, sin pie', p.por('.sin-resultados', p.zona).length === 1 && p.por('.sin-resultados p', p.zona)[0].textContent === 'No encontramos torneos con esa búsqueda.' && p.clases(p.por('.sin-resultados', p.zona)[0]) === 'card sin-resultados' && p.por('.listado-pie', p.zona).length === 0);
    ok('y los lectores de pantalla lo oyen', p.aviso.textContent === 'No se encontraron torneos.');
    p.tipear(''); p.avanzar(); await tick();
    p.responder(1, respuesta([], { total: 0 })); await tick();
    ok('sin resultados y sin filtros: «No hay torneos públicos disponibles.»', p.por('.sin-resultados p', p.zona)[0].textContent === 'No hay torneos públicos disponibles.');
  }
  {
    const p = pagina();
    p.tipear('t'); p.avanzar(); await tick();
    p.responder(0, respuesta([torneo(1)], { total: 1, filtros: { q: 't', tipo: '', estado: '' } })); await tick();
    ok('un solo resultado se anuncia en singular', p.aviso.textContent === '1 torneo encontrado.', p.aviso.textContent);
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — los botones de página (mismos vectores que tests/paginador_test.php)');
  const secuencia = async (paginaActual, paginas, filtros) => {
    const p = pagina();
    p.tipear('t'); p.avanzar(); await tick();
    p.responder(0, respuesta([torneo(1)], { pagina: paginaActual, total: paginas * 12, filtros: filtros || { q: 't', tipo: '', estado: '' } })); await tick();
    const nav = p.por('.paginacion', p.zona)[0];
    return { p, nav, texto: nav ? nav.hijos.map((h) => h.textContent) : null };
  };
  {
    const casos = [
      [1, 1, null, 'una sola página: no hay botones'],
      [3, 5, ['‹ Anterior', '1', '2', '3', '4', '5', 'Siguiente ›'], 'pocas páginas: todas'],
      [1, 20, ['‹ Anterior', '1', '2', '…', '20', 'Siguiente ›'], 'al principio'],
      [10, 20, ['‹ Anterior', '1', '…', '9', '10', '11', '…', '20', 'Siguiente ›'], 'en el medio'],
      [20, 20, ['‹ Anterior', '1', '…', '19', '20', 'Siguiente ›'], 'al final'],
      [4, 7, ['‹ Anterior', '1', '2', '3', '4', '5', '6', '7', 'Siguiente ›'], 'un hueco de UNA página se rellena con su número'],
      [1, 7, ['‹ Anterior', '1', '2', '…', '7', 'Siguiente ›'], 'al principio de 7'],
    ];
    for (const [actual, paginas, esperado, que] of casos) {
      const r = await secuencia(actual, paginas);
      ok(`ventana ${actual}/${paginas}: ${que}`, JSON.stringify(r.texto) === JSON.stringify(esperado), JSON.stringify(r.texto));
    }
    // con todas las combinaciones hasta 30 páginas: primera, última y actual, sin repetidos, en orden (como el test de PHP)
    let mal = '';
    for (let paginas = 1; paginas <= 30 && !mal; paginas++) {
      for (let actual = 1; actual <= paginas && !mal; actual++) {
        const r = await secuencia(actual, paginas);
        if (paginas === 1) { if (r.nav) mal = `${actual}/${paginas}: no debía haber botones`; continue; }
        const numeros = r.texto.filter((t) => /^\d+$/.test(t)).map(Number);
        const ordenados = [...numeros].sort((a, b) => a - b);
        if (!numeros.includes(1) || !numeros.includes(paginas) || !numeros.includes(actual) || new Set(numeros).size !== numeros.length || JSON.stringify(numeros) !== JSON.stringify(ordenados)) mal = `${actual}/${paginas}: ${JSON.stringify(numeros)}`;
      }
    }
    ok('en las 465 combinaciones hasta 30 páginas: primera, última y actual, sin repetir y en orden', mal === '', mal);
  }
  {
    const r = await secuencia(2, 4, { q: 'liga', tipo: '', estado: 'en_curso' });
    const hijos = r.nav.hijos;
    ok('el resumen cuenta desde la fila 13 en la página 2', r.p.por('.resumen', r.p.zona)[0].textContent === 'Mostrando 13–24 de 48 torneos', r.p.por('.resumen', r.p.zona)[0].textContent);
    ok('los lectores de pantalla oyen también en qué página están', r.p.aviso.textContent === '48 torneos encontrados. Página 2 de 4.', r.p.aviso.textContent);
    ok('la navegación tiene su nombre accesible',r.nav.getAttribute('aria-label') === 'Páginas de torneos' && r.nav.tagName === 'NAV' && r.p.clases(r.nav) === 'paginacion');
    ok('«Anterior» es un enlace con rel=prev a la página 1 (sin ?page=)', hijos[0].tagName === 'A' && hijos[0].getAttribute('rel') === 'prev' && hijos[0].href === '/torneos?q=liga&estado=en_curso', hijos[0].href);
    ok('la página actual es un span con aria-current', hijos[2].tagName === 'SPAN' && hijos[2].getAttribute('aria-current') === 'page' && r.p.clases(hijos[2]) === 'btn small primary');
    ok('las demás son enlaces con nombre y dirección que conservan los filtros', hijos[3].tagName === 'A' && hijos[3].getAttribute('aria-label') === 'Página 3' && hijos[3].href === '/torneos?q=liga&estado=en_curso&page=3' && r.p.clases(hijos[3]) === 'btn small');
    ok('«Siguiente» es un enlace con rel=next', hijos[hijos.length - 1].getAttribute('rel') === 'next' && hijos[hijos.length - 1].href === '/torneos?q=liga&estado=en_curso&page=3');
    const primera = await secuencia(1, 4);
    ok('en la primera página «Anterior» no es un enlace: es un span deshabilitado', primera.nav.hijos[0].tagName === 'SPAN' && primera.nav.hijos[0].getAttribute('aria-disabled') === 'true' && primera.p.clases(primera.nav.hijos[0]) === 'btn small disabled');
    const ultima = await secuencia(4, 4);
    ok('y en la última, «Siguiente»', ultima.nav.hijos[ultima.nav.hijos.length - 1].tagName === 'SPAN' && ultima.nav.hijos[ultima.nav.hijos.length - 1].getAttribute('aria-disabled') === 'true');
    const huecoR = await secuencia(10, 20);
    const gap = huecoR.nav.hijos.find((h) => h.textContent === '…');
    ok('los «…» se ocultan a los lectores de pantalla', gap.getAttribute('aria-hidden') === 'true' && huecoR.p.clases(gap) === 'paginacion-gap');
  }
  {
    const p = pagina({ q: 'liga' });
    p.tipear('liga y mas'); p.avanzar(); await tick();
    p.responder(0, respuesta([torneo(1)], { pagina: 1, total: 36, filtros: { q: 'liga y mas', tipo: '', estado: '' } })); await tick();
    const enlaceP2 = p.por('.paginacion a', p.zona).find((a) => a.textContent === '2');
    ok('una búsqueda con espacios deja la dirección de la página igual que PHP', enlaceP2.href === '/torneos?q=liga+y+mas&page=2', enlaceP2.href);
    const ev = enlaceP2.despachar('click', { ctrlKey: false, metaKey: false, shiftKey: false, button: 0 });
    await tick();
    ok('un clic en una página no recarga: pide esa página con los mismos filtros', ev.defaultPrevented && p.llamadas.length === 2 && p.llamadas[1].url === '/api/torneos?q=liga+y+mas&page=2&por_pagina=12', p.llamadas[1] && p.llamadas[1].url);
    p.responder(1, respuesta([torneo(2)], { pagina: 2, total: 36, filtros: { q: 'liga y mas', tipo: '', estado: '' } })); await tick();
    const enlaceP3 = p.por('.paginacion a', p.zona).find((a) => a.textContent === '3');
    const evCtrl = enlaceP3.despachar('click', { ctrlKey: true, metaKey: false, shiftKey: false, button: 0 });
    await tick();
    ok('con Ctrl (o Cmd, Mayús, el botón del medio) lo resuelve el navegador: abre la página normal', !evCtrl.defaultPrevented && p.llamadas.length === 2);
    const ver = p.por('article a', p.zona)[0];
    const evVer = ver.despachar('click', { ctrlKey: false, metaKey: false, shiftKey: false, button: 0 });
    ok('un clic en «Ver detalle» no se toca', !evVer.defaultPrevented && p.llamadas.length === 2);
    ok('la dirección del navegador quedó con la página 2', p.historia[p.historia.length - 1] === '/torneos?q=liga+y+mas&page=2', p.historia.join(' | '));
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — la dirección y «Limpiar»');
  {
    const p = pagina();
    p.tipear('liga'); p.avanzar(); await tick();
    p.responder(0, respuesta([torneo(1)], { total: 1, filtros: { q: 'liga', tipo: '', estado: '' } })); await tick();
    ok('la dirección del navegador se actualiza sin agregar al historial (replaceState)', p.historia.length === 1 && p.historia[0] === '/torneos?q=liga', p.historia.join(' | '));
    ok('aparece «Limpiar», con la misma forma que la del servidor', p.por('a.ghost', p.acciones).length === 1 && p.por('a.ghost', p.acciones)[0].href === '/torneos' && p.clases(p.por('a.ghost', p.acciones)[0]) === 'btn small ghost' && p.por('a.ghost', p.acciones)[0].textContent === 'Limpiar');
    p.tipear('liga2'); p.avanzar(); await tick();
    p.responder(1, respuesta([torneo(1)], { total: 1, filtros: { q: 'liga2', tipo: '', estado: '' } })); await tick();
    ok('con otra búsqueda no se duplica', p.por('a.ghost', p.acciones).length === 1);
    p.tipear(''); p.avanzar(); await tick();
    p.responder(2, respuesta([torneo(1), torneo(2)], { total: 2 })); await tick();
    ok('sin filtros desaparece «Limpiar» y la dirección vuelve a /torneos', p.por('a.ghost', p.acciones).length === 0 && p.historia[p.historia.length - 1] === '/torneos', p.historia.join(' | '));
    ok('el botón «Buscar» sigue donde estaba', p.por('button', p.acciones).length === 1);
  }
  {
    const p = pagina({ q: 'liga' });
    ok('si la página ya trae «Limpiar» del servidor, se conserva', p.por('a.ghost', p.acciones).length === 1);
    p.tipear('liga'); p.avanzar(); await tick();
    ok('volver a escribir lo mismo que ya se ve no pide nada', p.llamadas.length === 0);
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — lo difícil');
  {
    const p = pagina();
    p.tipear('a'); p.avanzar(); await tick();
    p.tipear('ab'); p.avanzar(); await tick();
    ok('un segundo pedido cancela el que estaba en camino', p.llamadas.length === 2 && p.llamadas[0].cancelada === true && p.llamadas[1].cancelada === false);
    ok('la cancelación no se muestra como un error', p.por('.alerta-lista', p.zona).length === 0);
    p.responder(1, respuesta([torneo(2, { nombre: 'Del segundo' })], { total: 1, filtros: { q: 'ab', tipo: '', estado: '' } })); await tick();
    ok('solo dibuja el último', p.por('strong', p.zona).map((e) => e.textContent).join() === 'Del segundo');
  }
  {
    // una respuesta vieja que llega DESPUÉS de la nueva (sin cancelación efectiva) no la pisa
    const p = pagina();
    p.tipear('a'); p.avanzar(); await tick();
    p.tipear('ab'); p.avanzar(); await tick();
    p.responder(1, respuesta([torneo(2, { nombre: 'Nueva' })], { total: 1, filtros: { q: 'ab', tipo: '', estado: '' } })); await tick();
    p.llamadas[0].resolver({ status: 200, json: async () => respuesta([torneo(1, { nombre: 'Vieja' })], { total: 1 }) }); await tick();
    ok('una respuesta vieja que llega tarde no pisa a la nueva', p.por('strong', p.zona).map((e) => e.textContent).join() === 'Nueva', p.por('strong', p.zona).map((e) => e.textContent).join());
    ok('ni cambia la dirección ni el aviso', p.historia[p.historia.length - 1] === '/torneos?q=ab' && p.aviso.textContent === '1 torneo encontrado.');
  }
  {
    const p = pagina();
    p.tipear('x'); p.avanzar(); await tick();
    ok('(hay un pedido en camino)', p.llamadas.length === 1 && p.clases(p.zona).includes('cargando'));
    p.tipear(''); p.avanzar(); await tick();
    ok('volver a lo que ya se ve cancela el pedido en camino y apaga el «cargando»', p.llamadas[0].cancelada === true && p.llamadas.length === 1 && !p.clases(p.zona).includes('cargando') && !p.zona.hasAttribute('aria-busy'));
    ok('y la lista del servidor sigue intacta', !!p.zona.querySelector('[data-servidor]'));
  }
  {
    const p = pagina();
    p.tipear('x'); p.avanzar(); await tick();
    p.llamadas[0].rechazar(new TypeError('Failed to fetch')); await tick();
    ok('si la red falla, la lista queda como estaba', !!p.zona.querySelector('[data-servidor]'));
    const alerta = p.por('.alerta-lista', p.zona)[0];
    ok('y arriba aparece el motivo, como alerta', alerta && alerta.getAttribute('role') === 'alert' && p.clases(alerta) === 'chip danger alerta-lista' && alerta.textContent.includes('No se pudo conectar'), alerta && alerta.textContent);
    ok('la alerta va ANTES de la lista', p.zona.hijos[0] === alerta);
    ok('el aviso de «cargando» se apagó', !p.clases(p.zona).includes('cargando'));
    p.tipear('y'); p.avanzar(); await tick();
    p.llamadas[1].rechazar(new TypeError('Failed to fetch')); await tick();
    ok('una segunda falla reemplaza la alerta (no se apilan)', p.por('.alerta-lista', p.zona).length === 1);
    p.tipear('z'); p.avanzar(); await tick();
    p.responder(2, respuesta([torneo(1)], { total: 1, filtros: { q: 'z', tipo: '', estado: '' } })); await tick();
    ok('al volver a funcionar la alerta desaparece', p.por('.alerta-lista', p.zona).length === 0 && p.por('article', p.zona).length === 1);
    ok('y la clave que falló no quedó como «ya vista»: se puede reintentar', (() => { p.tipear('x'); p.avanzar(); return p.llamadas.length === 4; })());
  }
  {
    const p = pagina();
    p.tipear('x'); p.avanzar(); await tick();
    p.responder(0, { ok: false, error: { codigo: 'datos_invalidos', mensaje: 'El filtro no es válido.' } }, 422); await tick();
    ok('un error de la API muestra su mensaje', p.por('.alerta-lista', p.zona)[0].textContent === 'El filtro no es válido.', p.por('.alerta-lista', p.zona)[0] && p.por('.alerta-lista', p.zona)[0].textContent);
  }
  {
    const p = pagina();
    p.tipear('x'); p.avanzar(); await tick();
    p.llamadas[0].resolver({ status: 200, json: async () => { throw new Error('no es JSON'); } }); await tick();
    ok('una respuesta que no es JSON también se muestra como error', p.por('.alerta-lista', p.zona).length === 1 && !!p.zona.querySelector('[data-servidor]'));
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('torneos-en-vivo.js — cuándo NO hace nada');
  {
    const p = pagina({ conFormulario: false });
    p.tipear('liga'); p.avanzar(); await tick();
    ok('un formulario sin data-en-vivo (las listas de administración) no se toca', p.llamadas.length === 0 && p.temporizadores.length === 0);
  }
  {
    const p = pagina({ conApi: false });
    p.tipear('liga'); p.avanzar(); await tick();
    ok('si api.js no se cargó, el formulario sigue siendo el GET de siempre', p.llamadas.length === 0 && p.temporizadores.length === 0 && !p.form.escuchas.submit);
  }
  {
    const doc = crearDocumento();
    const v = crearVentana(doc);
    correr('api.js', v); correr('formato.js', v);
    let fallo = null;
    try { correr('torneos-en-vivo.js', v); } catch (e) { fallo = e; }
    ok('en una página sin formulario ni lista no falla', fallo === null, String(fallo));
  }

  console.log(fallos === 0 ? '\nTodo OK' : `\n${fallos} fallo(s)`);
  process.exit(fallos === 0 ? 0 : 1);
})();
