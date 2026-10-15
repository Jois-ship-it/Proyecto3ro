// Prueba de api.js y resultados.js con un DOM y un fetch simulados (Node, sin dependencias).
//
// Los tests de PHP solo pueden LEER el texto de estos scripts; este los EJECUTA: qué cabeceras manda
// el fetch, qué hace el diálogo ante un 422, un 403, un 401, una caída de red o una respuesta que no
// es JSON. (Con él se encontró que el motivo del rechazo no llegaba al diálogo: se leía una propiedad
// que no existía.)
//
// Ejecutar (hace falta Node 18 o más nuevo; no se instala nada):
//   node tests/js/resultados_cliente_test.js
const fs = require('fs');
const vm = require('vm');
const RAIZ = require('path').join(__dirname, '..', '..', 'public', 'assets', 'js') + require('path').sep;

let fallos = 0;
const ok = (nombre, cond, detalle = '') => { console.log((cond ? '  OK    ' : '  FALLA ') + nombre + (cond ? '' : '  ' + detalle)); if (!cond) fallos++; };

function entorno(respuestas) {
  const llamadas = [];
  const estadoFormulario = {};
  const boton = { textContent: 'Confirmar resultado', disabled: false };
  const error = { textContent: '', hidden: true };
  let handler = null;
  const form = {
    action: 'https://localhost:8543/admin/resultados/cargar',
    campos: { enfrentamiento_id: '7', puntos_a: '2', puntos_b: '1', torneo_id: '3' },
    reportValidity: () => true,
    addEventListener: (t, h) => { if (t === 'submit') handler = h; },
    querySelector: (sel) => sel.includes('submit') ? boton : sel.includes('data-api-error') ? error : null,
  };
  const location = { reloads: 0, href: '/torneo', reload() { this.reloads++; } };
  const contexto = {
    document: {
      querySelector: (sel) => sel.includes('csrf-token') ? { content: 'tok-de-prueba' } : null,
      querySelectorAll: (sel) => sel === 'form[data-api]' ? [form] : [],
    },
    window: { location },
    FormData: class { constructor(f) { this.f = f; } *[Symbol.iterator]() { yield* Object.entries(this.f.campos); } },
    URLSearchParams,
    fetch: async (url, opciones) => {
      llamadas.push({ url, opciones });
      const r = respuestas.shift();
      if (r === 'red') throw new TypeError('network');
      if (r === 'abortado') { const abort = new Error('The operation was aborted.'); abort.name = 'AbortError'; throw abort; }
      return { status: r.estado, json: async () => { if (r.noJson) throw new Error('no json'); return r.cuerpo; } };
    },
    console,
  };
  vm.createContext(contexto);
  vm.runInContext(fs.readFileSync(RAIZ + 'api.js', 'utf8'), contexto);
  vm.runInContext(fs.readFileSync(RAIZ + 'resultados.js', 'utf8'), contexto);
  return { enviar: () => handler({ preventDefault() { this.parado = true; } }), llamadas, boton, error, location, contexto };
}

(async () => {
  console.log('Cliente (api.js)');
  {
    const e = entorno([{ estado: 200, cuerpo: { ok: true, datos: { mensaje: 'listo' } } }]);
    await e.enviar();
    const c = e.llamadas[0];
    ok('el POST va a la dirección del formulario', c.url === 'https://localhost:8543/admin/resultados/cargar', c.url);
    ok('con método POST y las cookies del propio sitio', c.opciones.method === 'POST' && c.opciones.credentials === 'same-origin');
    ok('manda el token en X-CSRF-Token', c.opciones.headers['X-CSRF-Token'] === 'tok-de-prueba', JSON.stringify(c.opciones.headers));
    ok('manda X-Requested-With y Accept: json', c.opciones.headers['X-Requested-With'] === 'XMLHttpRequest' && c.opciones.headers['Accept'] === 'application/json');
    ok('el cuerpo son los campos del formulario, urlencoded', String(c.opciones.body) === 'enfrentamiento_id=7&puntos_a=2&puntos_b=1&torneo_id=3', String(c.opciones.body));
  }

  console.log('Formulario (resultados.js)');
  {
    const e = entorno([{ estado: 200, cuerpo: { ok: true, datos: { mensaje: 'listo', aviso: null } } }]);
    const p = e.enviar();
    ok('mientras guarda, el botón se desactiva y avisa', e.boton.disabled === true && e.boton.textContent === 'Guardando…');
    await p;
    ok('si sale bien, recarga la página (el aviso queda en el servidor)', e.location.reloads === 1);
  }
  {
    const e = entorno([{ estado: 422, cuerpo: { ok: false, error: { codigo: 'datos_invalidos', mensaje: 'Los puntos no pueden ser negativos.' } } }]);
    await e.enviar();
    ok('un rechazo de negocio muestra el motivo DENTRO del diálogo', e.error.textContent === 'Los puntos no pueden ser negativos.' && e.error.hidden === false, e.error.textContent);
    ok('no recarga y deja el botón como estaba', e.location.reloads === 0 && e.boton.disabled === false && e.boton.textContent === 'Confirmar resultado');
  }
  {
    const e = entorno([{ estado: 403, cuerpo: { ok: false, error: { codigo: 'sin_permiso', mensaje: 'El formulario venció o no es válido. Volvé a cargar la página e intentá de nuevo.' } } }]);
    await e.enviar();
    ok('un 403 (token vencido) también se muestra en el diálogo', e.error.textContent.startsWith('El formulario venció') && e.location.reloads === 0);
  }
  {
    const e = entorno([{ estado: 401, cuerpo: { ok: false, error: { codigo: 'no_autenticado', mensaje: 'Iniciá sesión.' } } }]);
    await e.enviar();
    ok('sin sesión (401) lleva a /login', e.location.href === '/login' && e.location.reloads === 0, e.location.href);
  }
  {
    const e = entorno(['red']);
    await e.enviar();
    ok('si no hay conexión lo dice y se puede reintentar', e.error.textContent.startsWith('No se pudo conectar') && e.boton.disabled === false);
  }
  {
    const e = entorno([{ estado: 500, noJson: true }]);
    await e.enviar();
    ok('una respuesta que no es JSON (una página de error) no rompe: da un mensaje', e.error.textContent.startsWith('El servidor respondió algo inesperado') && e.location.reloads === 0, e.error.textContent);
  }
  {
    const e = entorno([{ estado: 500, cuerpo: { ok: false, error: { codigo: 'error_interno', mensaje: 'Ocurrió un error inesperado. (código ab12cd34)', id: 'ab12cd34' } } }]);
    await e.enviar();
    ok('un error técnico muestra el código de referencia', e.error.textContent.includes('ab12cd34'));
  }
  {
    const e = entorno([{ estado: 200, cuerpo: { ok: true, datos: {} } }]);
    await e.enviar();
    ok('GET de la API: get() devuelve {ok,datos,meta} y no manda token', await (async () => {
      const e2 = entorno([{ estado: 200, cuerpo: { ok: true, datos: [1], meta: { total: 1 } } }]);
      const r = await vm.runInContext('FlexApi', e2.contexto).get('/api/torneos');
      const c = e2.llamadas[0];
      return r.ok === true && r.meta.total === 1 && c.opciones.method === 'GET' && !('X-CSRF-Token' in c.opciones.headers);
    })());
  }
  console.log('Cliente (api.js) — cancelar un pedido');
  {
    const senal = { aborted: false };
    const e1 = entorno([{ estado: 200, cuerpo: { ok: true, datos: [] } }]);
    await vm.runInContext('FlexApi', e1.contexto).get('/api/torneos', { signal: senal });
    ok('get() con {signal} se lo pasa al fetch', e1.llamadas[0].opciones.signal === senal);
    const e2 = entorno([{ estado: 200, cuerpo: { ok: true, datos: [] } }]);
    await vm.runInContext('FlexApi', e2.contexto).get('/api/torneos');
    ok('sin señal no agrega la clave (get(url) sigue igual que antes)', !('signal' in e2.llamadas[0].opciones));
    const e3 = entorno(['abortado']);
    let error = null;
    try { await vm.runInContext('FlexApi', e3.contexto).get('/api/torneos', { signal: {} }); } catch (x) { error = x; }
    ok('un pedido cancelado a propósito sale como AbortError, no como «sin conexión»', error && error.name === 'AbortError' && !error.codigo, error && error.name + ' ' + error.codigo);
    const e4 = entorno(['red']);
    let caida = null;
    try { await vm.runInContext('FlexApi', e4.contexto).get('/api/torneos', { signal: {} }); } catch (x) { caida = x; }
    ok('y una caída de red de verdad sigue siendo «sin_conexion»', caida && caida.name === 'ApiError' && caida.codigo === 'sin_conexion' && caida.estado === 0, caida && caida.name + ' ' + caida.codigo);
  }
  console.log(fallos === 0 ? '\nTodo OK' : `\n${fallos} fallo(s)`);
  process.exit(fallos === 0 ? 0 : 1);
})();
