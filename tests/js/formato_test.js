// Prueba de public/assets/js/formato.js (FlexFormato): las fechas, los rangos y los estados que escribe el navegador.
//
// Lo más importante: la tabla de estados tiene que ser la MISMA que View::estadoChip() de PHP. Una tarjeta que dibuja el navegador
// (búsqueda en vivo) y la que dibuja el servidor tienen que verse igual; si alguien agrega un estado en uno y no en el otro, esto falla.
//
// Ejecutar (hace falta Node 18 o más nuevo; no se instala nada):
//   node tests/js/formato_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { crearDocumento, crearVentana, correr } = require('./dom_falso.js');

let fallos = 0;
const ok = (nombre, cond, detalle = '') => { console.log((cond ? '  OK    ' : '  FALLA ') + nombre + (cond ? '' : '  ' + detalle)); if (!cond) fallos++; };

const ventana = crearVentana(crearDocumento());
correr('formato.js', ventana);
const F = vm.runInContext('FlexFormato', ventana);
const raiz = path.join(__dirname, '..', '..');

console.log('formato.js — fechas');
ok('dos(): rellena con un cero', F.dos(5) === '05' && F.dos(12) === '12' && F.dos('7') === '07');
{
  const p = F.partesDeFecha('2026-10-12');
  ok('una fecha sola: hora cero', p && p.anio === 2026 && p.mes === 10 && p.dia === 12 && p.hora === 0 && p.minuto === 0 && p.segundo === 0);
  const h = F.partesDeFecha('2026-10-12 18:30:45');
  ok('con hora (espacio): la separa', h && h.hora === 18 && h.minuto === 30 && h.segundo === 45);
  const t = F.partesDeFecha('2026-10-12T07:05');
  ok('con hora (T): la separa', t && t.hora === 7 && t.minuto === 5 && t.segundo === 0);
  ok('lo que no es fecha da null', [null, undefined, '', '   ', 'hoy', '0000-00-00', '2026-13', 'abc-de-fg'].every((x) => F.partesDeFecha(x) === null),
     JSON.stringify([null, undefined, '', 'hoy', '0000-00-00'].map((x) => F.partesDeFecha(x))));
  ok('un número suelto (no una fecha) tampoco', F.partesDeFecha(20261012) === null);
}
ok('diaMes(): «2026-10-12» → «12/10»', F.diaMes('2026-10-12') === '12/10' && F.diaMes('2026-01-05') === '05/01');
ok('diaMes(): vacío → «», y lo que no es fecha vuelve como vino', F.diaMes('') === '' && F.diaMes(null) === '' && F.diaMes('pronto') === 'pronto');
ok('rango(): con dos fechas', F.rango('2026-10-12', '2026-10-20') === '12/10 – 20/10');
ok('rango(): mismo día o sin fin → una sola fecha', F.rango('2026-10-12', '2026-10-12') === '12/10' && F.rango('2026-10-12', null) === '12/10' && F.rango('2026-10-12', '') === '12/10');
ok('rango(): sin inicio → «»', F.rango('', '2026-10-12') === '' && F.rango(null, null) === '');
ok('etiquetaDeFecha(): día de la semana, día y mes (2026-10-12 es lunes)', F.etiquetaDeFecha('2026-10-12') === 'Lun 12 de oct', F.etiquetaDeFecha('2026-10-12'));
ok('etiquetaDeFecha(): cubre toda la semana', ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17']
  .map((d) => F.etiquetaDeFecha(d).split(' ')[0]).join(',') === 'Dom,Lun,Mar,Mié,Jue,Vie,Sáb');
ok('etiquetaDeFecha(): con hora agrega « · hh:mm»', F.etiquetaDeFecha('2026-10-12 18:30:00') === 'Lun 12 de oct · 18:30' && F.etiquetaDeFecha('2026-12-25T07:05') === 'Vie 25 de dic · 07:05');
ok('etiquetaDeFecha(): medianoche exacta no lleva hora', F.etiquetaDeFecha('2026-10-12 00:00:00') === 'Lun 12 de oct');
ok('etiquetaDeFecha(): sin fecha válida → «Sin fecha»', F.etiquetaDeFecha(null) === 'Sin fecha' && F.etiquetaDeFecha('x') === 'Sin fecha');
ok('periodo(): «inicio → fin», con «—» donde falta', F.periodo('2026-10-24', '2026-11-23') === '2026-10-24 → 2026-11-23' && F.periodo(null, '2026-11-23') === '— → 2026-11-23' && F.periodo('2026-10-24', '') === '2026-10-24 → —' && F.periodo(null, null) === '— → —');
{
  const fuente = fs.readFileSync(path.join(raiz, 'public', 'assets', 'js', 'formato.js'), 'utf8').replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, '');
  ok('no interpreta fechas con new Date(texto) ni Date.parse: esos corren el día según la zona horaria', !/new Date\(\s*['"`]/.test(fuente) && !/Date\.parse/.test(fuente));
}

console.log('formato.js — estados');
ok('«en_curso» → «En curso», color por defecto', JSON.stringify(F.estado('en_curso')) === JSON.stringify({ texto: 'En curso', clase: 'chip' }));
ok('«inscripcion» → «Inscripcion» (sin tilde, como el servidor), amarillo', JSON.stringify(F.estado('inscripcion')) === JSON.stringify({ texto: 'Inscripcion', clase: 'chip warning' }));
ok('«finalizado» verde, «cancelado» rojo', F.estado('finalizado').clase === 'chip success' && F.estado('cancelado').clase === 'chip danger');
ok('un estado que no conoce lleva el color por defecto y su texto legible', JSON.stringify(F.estado('raro_total')) === JSON.stringify({ texto: 'Raro total', clase: 'chip' }));
ok('null y vacío no rompen', F.estado(null).texto === '' && F.estado('').clase === 'chip' && F.estado(undefined).texto === '');
ok('«constructor», «toString»… no se toman por estados (nada del prototipo)', F.estado('constructor').clase === 'chip' && F.estado('toString').clase === 'chip' && F.estado('__proto__').clase === 'chip');
ok('mayusculaInicial(): «individual» → «Individual»', F.mayusculaInicial('individual') === 'Individual' && F.mayusculaInicial('equipos') === 'Equipos' && F.mayusculaInicial('') === '' && F.mayusculaInicial(null) === '');
{
  // La misma tabla que PHP: se lee el fuente de View::estadoChip() y se compara estado por estado, con el texto que armaría PHP.
  const php = fs.readFileSync(path.join(raiz, 'core', 'View.php'), 'utf8');
  const bloque = php.slice(php.indexOf('function estadoChip'));
  const tabla = bloque.slice(bloque.indexOf('$map = ['), bloque.indexOf('];', bloque.indexOf('$map = [')));
  const pares = [...tabla.matchAll(/'(\w+)'\s*=>\s*'(\w*)'/g)].map((m) => [m[1], m[2]]);
  ok('se leyó la tabla de View::estadoChip()', pares.length >= 14, String(pares.length));
  const distintos = pares.filter(([estado, color]) => {
    const js = F.estado(estado);
    const clasePhp = color ? `chip ${color}` : 'chip';
    const textoPhp = estado.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
    return js.clase !== clasePhp || js.texto !== textoPhp;
  });
  ok('cada estado de PHP sale igual en JavaScript (texto y clase)', distintos.length === 0, JSON.stringify(distintos));
  const fuenteJs = fs.readFileSync(path.join(raiz, 'public', 'assets', 'js', 'formato.js'), 'utf8');
  const bloqueJs = fuenteJs.slice(fuenteJs.indexOf('const COLOR_DEL_ESTADO'), fuenteJs.indexOf('};', fuenteJs.indexOf('const COLOR_DEL_ESTADO')));
  const enJs = [...bloqueJs.matchAll(/(\w+):\s*'(\w*)'/g)].map((m) => m[1]);
  const faltanEnPhp = enJs.filter((e) => !pares.some(([k]) => k === e));
  ok('y JavaScript no tiene estados de más', faltanEnPhp.length === 0 && enJs.length === pares.length, `${enJs.length} contra ${pares.length}: ${faltanEnPhp}`);
}

console.log(fallos === 0 ? '\nTodo OK' : `\n${fallos} fallo(s)`);
process.exit(fallos === 0 ? 0 : 1);
