/* FlexArena — torneos-en-vivo.js
 *
 * Búsqueda en vivo en la lista pública de torneos (/torneos): al escribir en «Buscar» o cambiar «Formato» o «Estado», la lista se
 * actualiza sin recargar la página, con los datos de la API pública (GET /api/torneos, la misma consulta que usa el servidor).
 *
 * Es una MEJORA, no un reemplazo: el formulario sigue siendo un GET común (con el botón «Buscar» y la dirección copiable) y sin
 * JavaScript funciona igual. Lo que dibuja este script es lo mismo que dibuja el servidor (public/torneos.php y
 * partials/paginacion.php): mismas clases, mismo texto; los estados salen de formato.js, que repite View::estadoChip().
 *
 * Qué hace:
 *   - Al tipear espera 300 ms de calma antes de pedir (no manda una consulta por tecla) y cancela la anterior si sigue en camino.
 *   - Una respuesta vieja nunca pisa a una nueva: cada pedido lleva un número y solo el último dibuja.
 *   - Mientras espera, la lista se atenúa (clase `cargando`) y queda marcada como ocupada (aria-busy).
 *   - Los lectores de pantalla oyen cuántos torneos hay (la región `#estado-lista`, un role="status").
 *   - La dirección del navegador se actualiza (replaceState) para que se pueda copiar y recargar con los mismos filtros.
 *   - Los botones de página también se resuelven acá (con Ctrl/Cmd/Mayús o el botón del medio abren la página normal).
 *   - Si el pedido falla, la lista queda como estaba y arriba aparece el motivo (no se pierde lo que se veía).
 *
 * Hace falta, antes: formato.js y api.js (en ese orden de `extraJs` en PublicoController::torneos()).
 */
'use strict';

(() => {
  const form = document.querySelector('form[data-en-vivo]');
  const zona = document.getElementById('lista-torneos');
  if (!form || !zona || typeof FlexApi === 'undefined' || typeof FlexFormato === 'undefined') return;

  const urlApi = form.dataset.enVivo || '/api/torneos';
  const rutaLista = form.getAttribute('action') || '/torneos';
  const aviso = document.getElementById('estado-lista');
  const RETARDO_MS = 300;
  const CLAVES = ['q', 'tipo', 'estado'];        // en el mismo orden en que el servidor arma la dirección de la lista

  const campo = (nombre) => form.querySelector('[name="' + nombre + '"]');
  const entradaQ = campo('q');

  let temporizador = null;
  let cancelador = null;
  let secuencia = 0;
  let ultimaConsulta = null;

  function el(etiqueta, clase, texto) {
    const e = document.createElement(etiqueta);
    if (clase) e.className = clase;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  /** Lo que hay escrito en el formulario, ya recortado. */
  function filtrosActuales() {
    const f = {};
    CLAVES.forEach((k) => { const c = campo(k); f[k] = c ? String(c.value || '').trim() : ''; });
    return f;
  }

  const hayFiltros = (f) => CLAVES.some((k) => f && f[k] !== '' && f[k] !== null && f[k] !== undefined);

  const codificar = (v) => encodeURIComponent(v).replace(/%20/g, '+');

  /** Igual que Paginador::enlace(): solo los filtros con valor, y ?page= a partir de la 2. */
  function consulta(filtros, pagina) {
    const partes = [];
    CLAVES.forEach((k) => { if (filtros[k] !== '' && filtros[k] != null) partes.push(k + '=' + codificar(filtros[k])); });
    if (pagina > 1) partes.push('page=' + pagina);
    return partes.length ? '?' + partes.join('&') : '';
  }

  /** Igual que Paginador::ventana(): primera, última y la actual con una vecina a cada lado; el hueco de una sola página se rellena. */
  function ventana(pagina, paginas, vecinas = 1) {
    const numeros = new Set([1, paginas]);
    for (let i = pagina - vecinas; i <= pagina + vecinas; i++) if (i >= 1 && i <= paginas) numeros.add(i);
    const ordenados = [...numeros].sort((a, b) => a - b);
    const salida = [];
    let previo = 0;
    ordenados.forEach((n) => {
      if (previo !== 0 && n - previo > 1) salida.push(n - previo === 2 ? previo + 1 : null);
      salida.push(n);
      previo = n;
    });
    return salida;
  }

  // ── Lo que se dibuja (el mismo marcado que el servidor) ──

  function tarjeta(t) {
    const art = el('article', 'card flex flex-col gap-06');

    const cabecera = el('div', 'flex justify-between items-start gap-05');
    cabecera.appendChild(el('strong', 'rompe-texto', t.nombre));
    const estado = FlexFormato.estado(t.estado);
    cabecera.appendChild(el('span', estado.clase, estado.texto));
    art.appendChild(cabecera);

    const tipo = el('div');
    tipo.appendChild(el('span', 'chip', t.formato && t.formato.nombre ? t.formato.nombre : ''));
    tipo.appendChild(document.createTextNode(' '));      // el HTML del servidor lo tiene: sin este espacio la modalidad queda 4 px corrida
    tipo.appendChild(el('span', 'muted ml-04', FlexFormato.mayusculaInicial(t.modalidad)));
    art.appendChild(tipo);

    art.appendChild(el('div', 'muted', (parseInt(t.inscritos, 10) || 0) + ' participantes'));
    art.appendChild(el('div', 'muted', FlexFormato.periodo(t.fecha_inicio, t.fecha_fin)));

    const ver = el('a', 'btn small primary mt-auto', 'Ver detalle →');
    ver.href = '/torneo/' + (parseInt(t.id, 10) || 0);       // se arma con el número: nunca con una dirección que venga de afuera
    art.appendChild(ver);
    return art;
  }

  function botonDePagina(n, filtros) {
    const a = el('a', 'btn small', String(n));
    a.href = rutaLista + consulta(filtros, n);
    a.setAttribute('aria-label', 'Página ' + n);
    return a;
  }

  function paginacion(meta, filtros) {
    const nav = el('nav', 'paginacion');
    nav.setAttribute('aria-label', 'Páginas de torneos');

    if (meta.pagina > 1) {
      const anterior = el('a', 'btn small', '‹ Anterior');
      anterior.setAttribute('rel', 'prev');
      anterior.href = rutaLista + consulta(filtros, meta.pagina - 1);
      nav.appendChild(anterior);
    } else {
      const anterior = el('span', 'btn small disabled', '‹ Anterior');
      anterior.setAttribute('aria-disabled', 'true');
      nav.appendChild(anterior);
    }

    ventana(meta.pagina, meta.paginas).forEach((n) => {
      if (n === null) {
        const hueco = el('span', 'paginacion-gap', '…');
        hueco.setAttribute('aria-hidden', 'true');
        nav.appendChild(hueco);
      } else if (n === meta.pagina) {
        const actual = el('span', 'btn small primary', String(n));
        actual.setAttribute('aria-current', 'page');
        nav.appendChild(actual);
      } else {
        nav.appendChild(botonDePagina(n, filtros));
      }
    });

    if (meta.pagina < meta.paginas) {
      const siguiente = el('a', 'btn small', 'Siguiente ›');
      siguiente.setAttribute('rel', 'next');
      siguiente.href = rutaLista + consulta(filtros, meta.pagina + 1);
      nav.appendChild(siguiente);
    } else {
      const siguiente = el('span', 'btn small disabled', 'Siguiente ›');
      siguiente.setAttribute('aria-disabled', 'true');
      nav.appendChild(siguiente);
    }
    return nav;
  }

  function pie(meta, filtros) {
    const contenedor = el('div', 'listado-pie');
    const desde = (meta.pagina - 1) * meta.por_pagina + 1;
    const hasta = Math.min(meta.total, meta.pagina * meta.por_pagina);
    contenedor.appendChild(el('p', 'resumen', 'Mostrando ' + desde + '–' + hasta + ' de ' + meta.total + ' torneos'));
    if (meta.paginas > 1) contenedor.appendChild(paginacion(meta, filtros));
    return contenedor;
  }

  function dibujar(datos, meta) {
    const filtros = meta.filtros || {};
    zona.innerHTML = '';
    if (!datos.length) {
      const vacio = el('div', 'card sin-resultados');
      vacio.appendChild(el('p', 'muted', hayFiltros(filtros) ? 'No encontramos torneos con esa búsqueda.' : 'No hay torneos públicos disponibles.'));
      zona.appendChild(vacio);
      return;
    }
    const grilla = el('div', 'grid cols-3');
    datos.forEach((t) => grilla.appendChild(tarjeta(t)));
    zona.appendChild(grilla);
    zona.appendChild(pie(meta, filtros));
  }

  // ── Alrededores de la lista ──

  function anunciar(meta) {
    if (!aviso) return;
    const total = meta.total;
    let texto = total === 0 ? 'No se encontraron torneos.' : total + (total === 1 ? ' torneo encontrado.' : ' torneos encontrados.');
    if (total > 0 && meta.paginas > 1) texto += ' Página ' + meta.pagina + ' de ' + meta.paginas + '.';
    aviso.textContent = texto;
  }

  /** «Limpiar» solo está cuando hay filtros (como en el servidor). */
  function actualizarLimpiar(filtros) {
    const acciones = form.querySelector('.actions');
    if (!acciones) return;
    const enlace = acciones.querySelector('a.ghost');
    if (hayFiltros(filtros) && !enlace) {
      const nuevo = el('a', 'btn small ghost', 'Limpiar');
      nuevo.href = rutaLista;
      acciones.appendChild(nuevo);
    } else if (!hayFiltros(filtros) && enlace) {
      enlace.remove();
    }
  }

  function actualizarDireccion(filtros, pagina) {
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState(null, '', rutaLista + consulta(filtros, pagina));
    }
  }

  function mostrarError(mensaje) {
    const anterior = zona.querySelector('.alerta-lista');
    if (anterior) anterior.remove();
    const chip = el('div', 'chip danger alerta-lista', mensaje || 'No se pudo actualizar la lista.');
    chip.setAttribute('role', 'alert');
    zona.insertBefore(chip, zona.firstChild);
  }

  // ── El pedido ──

  function terminarCarga() {
    zona.classList.remove('cargando');
    zona.removeAttribute('aria-busy');
  }

  async function buscar(pagina = 1) {
    const filtros = filtrosActuales();
    const clave = consulta(filtros, pagina);

    // Lo que estuviera en camino ya no sirve, sea cual sea la respuesta a esto: o se pide otra cosa o se vuelve a lo que ya se ve.
    if (cancelador) cancelador.abort();
    cancelador = typeof AbortController === 'function' ? new AbortController() : null;
    const mia = ++secuencia;

    if (clave === ultimaConsulta) {                          // volvió a lo que ya se ve: no hay nada que pedir
      terminarCarga();
      return;
    }

    zona.classList.add('cargando');
    zona.setAttribute('aria-busy', 'true');
    try {
      const url = urlApi + (clave ? clave + '&' : '?') + 'por_pagina=12';
      const respuesta = await FlexApi.get(url, cancelador ? { signal: cancelador.signal } : {});
      if (mia !== secuencia) return;                        // llegó tarde: ya se pidió otra cosa
      dibujar(respuesta.datos || [], respuesta.meta);
      actualizarLimpiar(respuesta.meta.filtros || filtros);
      actualizarDireccion(respuesta.meta.filtros || filtros, respuesta.meta.pagina);
      anunciar(respuesta.meta);
      ultimaConsulta = clave;
    } catch (error) {
      // Un pedido cancelado a propósito (api.js lo deja pasar como AbortError) llega acá con un número viejo: se descarta con
      // todo lo que ya no corresponde, igual que una respuesta tardía. Solo el pedido vigente puede mostrar un error.
      if (mia !== secuencia) return;
      mostrarError(error && error.message);
    } finally {
      if (mia === secuencia) terminarCarga();
    }
  }

  // Lo que se ve al cargar la página es lo que corresponde a esta dirección: no hace falta pedirlo de nuevo.
  ultimaConsulta = consulta(filtrosActuales(), Number((/[?&]page=(\d+)/.exec(window.location.search || '') || [])[1]) || 1);

  function programar() {
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(() => { temporizador = null; buscar(1); }, RETARDO_MS);
  }

  if (entradaQ) entradaQ.addEventListener('input', programar);
  ['tipo', 'estado'].forEach((k) => { const c = campo(k); if (c) c.addEventListener('change', () => { if (temporizador) clearTimeout(temporizador); buscar(1); }); });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (temporizador) { clearTimeout(temporizador); temporizador = null; }
    buscar(1);
  });

  zona.addEventListener('click', (e) => {
    const enlace = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!enlace || !enlace.closest('.paginacion')) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;      // abrir en otra pestaña: que lo haga el navegador
    e.preventDefault();
    const pagina = Number((/[?&]page=(\d+)/.exec(enlace.href || '') || [])[1]) || 1;
    buscar(pagina);
    if (typeof form.scrollIntoView === 'function') form.scrollIntoView();
  });
})();
