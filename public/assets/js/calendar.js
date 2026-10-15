/* FlexArena — calendar.js
 *
 * El calendario público de torneos (/torneos/calendario): un mes por vez, con cada torneo en el día en que empieza.
 * Los torneos viajan en un bloque JSON (`#torneos-cal`, que el navegador no ejecuta) y se dibujan en `#calendar`.
 *
 * Las fechas se escriben con formato.js (FlexFormato), que va antes que este script en la lista `extraJs` del controlador.
 *
 * Responsive (Mobile First; el CSS decide qué se ve, ver paginas.css y responsive.css):
 *   - En pantalla chica los días son cuadritos con un punto por torneo que empieza ese día, y debajo del calendario va la
 *     lista del mes (nombre, fechas y enlace): en un cuadrito de 45 px no entra un título.
 *   - Desde 721 px cada día muestra sus torneos como tarjetas con enlace, y la lista se oculta.
 */
'use strict';

(() => {
  const destino = document.getElementById('calendar');
  if (!destino) return;

  const datos = document.getElementById('torneos-cal');
  let torneos = [];
  try { torneos = datos ? JSON.parse(datos.textContent || '[]') : []; } catch (e) { torneos = []; }
  torneos = torneos.filter((t) => t && typeof t.inicio === 'string');

  const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
  const { dos, diaMes, rango } = FlexFormato;                           // formato.js: va antes que este script

  function el(etiqueta, clase, texto) {
    const e = document.createElement(etiqueta);
    if (clase) e.className = clase;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  let actual = new Date();
  actual.setDate(1);

  function dibujar() {
    destino.innerHTML = '';

    const anio = actual.getFullYear();
    const mes = actual.getMonth();
    const prefijoMes = `${anio}-${dos(mes + 1)}-`;

    // ── cabecera: mes anterior · mes y año · mes siguiente ──
    const cabecera = el('div', 'cal-cabecera');
    const anterior = el('button', 'btn', '<');
    anterior.type = 'button';
    anterior.setAttribute('aria-label', 'Mes anterior');
    anterior.onclick = () => { actual.setMonth(actual.getMonth() - 1); dibujar(); };
    const siguiente = el('button', 'btn', '>');
    siguiente.type = 'button';
    siguiente.setAttribute('aria-label', 'Mes siguiente');
    siguiente.onclick = () => { actual.setMonth(actual.getMonth() + 1); dibujar(); };
    const titulo = el('div', 'cal-mes');
    titulo.setAttribute('aria-live', 'polite');
    titulo.appendChild(el('strong', '', actual.toLocaleString('es', { month: 'long', year: 'numeric' })));
    cabecera.appendChild(anterior);
    cabecera.appendChild(titulo);
    cabecera.appendChild(siguiente);
    destino.appendChild(cabecera);

    // ── la grilla: los días de la semana y, debajo, los del mes ──
    const grilla = el('div', 'cal-grilla');
    DIAS.forEach((d) => grilla.appendChild(el('div', 'cal-dia-semana', d)));

    const primerDia = (actual.getDay() + 6) % 7;                       // el lunes es 0
    const diasDelMes = new Date(anio, mes + 1, 0).getDate();
    for (let i = 0; i < primerDia; i++) grilla.appendChild(document.createElement('div'));

    const hoy = new Date();
    const hoyTexto = `${hoy.getFullYear()}-${dos(hoy.getMonth() + 1)}-${dos(hoy.getDate())}`;

    for (let d = 1; d <= diasDelMes; d++) {
      const fecha = prefijoMes + dos(d);
      const celda = el('div', fecha === hoyTexto ? 'cal-dia hoy' : 'cal-dia');
      celda.appendChild(el('div', 'cal-numero', String(d)));

      // Solo los torneos que EMPIEZAN ese día.
      const delDia = torneos.filter((t) => t.inicio === fecha);
      if (delDia.length) {
        // Pantalla chica: un punto por torneo (hasta 3).
        const puntos = el('div', 'cal-puntos');
        puntos.setAttribute('aria-hidden', 'true');
        delDia.slice(0, 3).forEach(() => puntos.appendChild(el('span', 'cal-punto')));
        celda.appendChild(puntos);

        // Pantalla ancha: una tarjeta con enlace por torneo.
        const lista = el('div', 'cal-lista');
        delDia.forEach((t) => {
          const enlace = el('a', 'cal-enlace');
          enlace.href = `/torneo/${t.id}`;
          enlace.title = t.nombre || '';            // el título se corta con puntos suspensivos: el nombre entero, al pasar el mouse
          const tarjeta = el('div', 'cal-torneo');
          tarjeta.appendChild(el('div', 'cal-titulo', t.nombre || ''));
          tarjeta.appendChild(el('div', 'cal-rango', rango(t.inicio, t.fin)));
          enlace.appendChild(tarjeta);
          lista.appendChild(enlace);
        });
        celda.appendChild(lista);
      }
      grilla.appendChild(celda);
    }
    destino.appendChild(grilla);

    // ── la lista del mes (solo se ve en pantalla chica) ──
    const delMes = torneos.filter((t) => t.inicio.startsWith(prefijoMes)).sort((a, b) => a.inicio.localeCompare(b.inicio) || String(a.nombre).localeCompare(String(b.nombre)));
    const agenda = el('div', 'cal-agenda');
    agenda.appendChild(el('h2', 'como-h3', 'Torneos que empiezan este mes'));
    if (delMes.length === 0) {
      agenda.appendChild(el('p', 'muted', 'No hay torneos que empiecen este mes.'));
    } else {
      const ul = el('ul', 'lista-filas');
      delMes.forEach((t) => {
        const li = document.createElement('li');
        const enlace = el('a');
        enlace.href = `/torneo/${t.id}`;
        const detalle = el('span', 'detalle');
        detalle.appendChild(el('strong', '', t.nombre || ''));
        detalle.appendChild(el('small', '', rango(t.inicio, t.fin)));
        enlace.appendChild(detalle);
        enlace.appendChild(el('span', 'chip', diaMes(t.inicio)));
        li.appendChild(enlace);
        ul.appendChild(li);
      });
      agenda.appendChild(ul);
    }
    destino.appendChild(agenda);
  }

  dibujar();
})();
