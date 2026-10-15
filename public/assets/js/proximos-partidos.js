/* FlexArena — proximos-partidos.js
 *
 * «Próximos partidos» de la ficha pública de un torneo: toma los partidos que el servidor dejó en el JSON
 * `#partidos-calendario` (los datos viajan en un bloque que no se ejecuta, no en un script en línea), se
 * queda con los de hoy en adelante y los ordena por fecha. Se dibuja en el `#calendarMatches`.
 * Las fechas se separan y se escriben con formato.js (FlexFormato), que va antes que este script en la lista `extraJs`.
 */
'use strict';

(() => {
  const contenedor = document.getElementById('calendarMatches');
  const datos = document.getElementById('partidos-calendario');
  if (!contenedor || !datos) return;

  let PARTIDOS = [];
  try { PARTIDOS = JSON.parse(datos.textContent || '[]'); } catch (e) { PARTIDOS = []; }

  function escapar(valor) {
    return String(valor ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  const { partesDeFecha, etiquetaDeFecha } = FlexFormato;               // formato.js: va antes que este script

  function esDeHoyEnAdelante(valor) {
    const partes = partesDeFecha(valor);
    if (!partes) return false;
    const hoy = new Date();
    const hoySinHora = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
    const fecha = new Date(partes.anio, partes.mes - 1, partes.dia);
    return fecha.getTime() >= hoySinHora.getTime();
  }

  function ordenarPorFecha(lista) {
    return [...lista].sort((a, b) => {
      const izquierda = partesDeFecha(a.fecha);
      const derecha = partesDeFecha(b.fecha);
      if (!izquierda || !derecha) return 0;
      const claveIzquierda = Date.UTC(izquierda.anio, izquierda.mes - 1, izquierda.dia, izquierda.hora, izquierda.minuto, izquierda.segundo);
      const claveDerecha = Date.UTC(derecha.anio, derecha.mes - 1, derecha.dia, derecha.hora, derecha.minuto, derecha.segundo);
      return claveIzquierda - claveDerecha;
    });
  }

  function dibujarVacio(mensaje) {
    contenedor.innerHTML = '<div class="card proximo-vacio"><div class="muted">' + escapar(mensaje) + '</div></div>';
  }

  const proximos = ordenarPorFecha(PARTIDOS.filter((partido) => esDeHoyEnAdelante(partido.fecha)));

  if (!PARTIDOS.length) { dibujarVacio('No hay partidos programados.'); return; }
  if (!proximos.length) { dibujarVacio('No hay próximos partidos programados.'); return; }

  const lista = document.createElement('div');
  lista.className = 'proximos-lista';

  proximos.forEach((partido) => {
    const tarjeta = document.createElement('div');
    tarjeta.className = 'card proximo';

    const equipos = document.createElement('div');
    equipos.className = 'proximo-equipos';
    equipos.innerHTML = escapar(partido.a) + ' <span class="muted">vs</span> ' + escapar(partido.b);

    const detalle = document.createElement('div');
    const ronda = (partido.ronda || partido.ronda === 0) ? 'Ronda ' + escapar(String(partido.ronda)) : 'Ronda';
    const orden = (partido.orden || partido.orden === 0) ? ' · Orden ' + escapar(String(partido.orden)) : '';
    detalle.className = 'muted proximo-detalle';
    detalle.innerHTML = ronda + orden;

    const cuando = document.createElement('div');
    cuando.className = 'muted proximo-detalle';
    cuando.textContent = etiquetaDeFecha(partido.fecha);

    tarjeta.appendChild(equipos);
    tarjeta.appendChild(detalle);
    tarjeta.appendChild(cuando);
    lista.appendChild(tarjeta);
  });

  contenedor.innerHTML = '';
  contenedor.appendChild(lista);
})();
