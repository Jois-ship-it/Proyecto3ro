// La búsqueda en vivo de la lista pública de torneos (public/assets/js/torneos-en-vivo.js), en un navegador real y contra la API real.
// Solo lectura, salvo el torneo con nombre malicioso que crea un caso.
describe('Búsqueda en vivo de la lista de torneos', () => {
  /** Abre la lista tal como la sirve el sitio (con sus scripts) y deja una marca en la ventana: si la página se recarga, la marca desaparece. */
  const conBusquedaEnVivo = (ruta = '/torneos') => {
    cy.visit(ruta);
    cy.get('form[data-en-vivo]').should('exist');
    // La página trae los tres scripts, en este orden (FlexApi y FlexFormato son «const» globales: no cuelgan de window y la CSP no deja usar eval,
    // así que su presencia se prueba por el orden de los scripts y, en cada caso de abajo, porque la lista de verdad se actualiza).
    cy.get('script[src]').then(($s) => {
      const orden = [...$s].map((s) => s.getAttribute('src').split('?')[0]).filter((s) => /\/(formato|api|torneos-en-vivo)\.js$/.test(s));
      expect(orden).to.deep.eq(['/assets/js/formato.js', '/assets/js/api.js', '/assets/js/torneos-en-vivo.js']);
    });
    cy.window().then((win) => { win.__antesDeRecargar = true; });
  };
  const sinRecargar = () => cy.window().its('__antesDeRecargar').should('eq', true);
  const tarjetas = () => cy.get('#lista-torneos article.card');

  it('al tipear, la lista se actualiza sin recargar la página, y la dirección queda copiable', () => {
    conBusquedaEnVivo();
    tarjetas().should('have.length.at.least', 5);
    cy.get('#f-q').type('Ajedrez');
    tarjetas().should('have.length', 1).and('contain', 'Campus Cup Ajedrez');
    sinRecargar();
    cy.location('search').should('eq', '?q=Ajedrez');
    cy.get('.resumen').should('contain', 'Mostrando 1–1 de 1 torneos');
    cy.contains('.filtros a', 'Limpiar').should('exist');
    cy.get('#estado-lista').should('have.text', '1 torneo encontrado.');
  });

  it('esa dirección, abierta de cero, muestra lo mismo que la lista en vivo (sin diferencias de texto)', () => {
    conBusquedaEnVivo();
    cy.get('#f-q').type('Campus');
    tarjetas().should('have.length', 2);
    cy.get('#lista-torneos').invoke('text').then((enVivo) => {
      cy.visit('/torneos?q=Campus');
      cy.get('#lista-torneos').invoke('text').should((delServidor) => {
        const normal = (t) => t.replace(/\s+/g, '');            // sin espacios: el servidor deja saltos de línea entre etiquetas que no se ven
        expect(normal(delServidor)).to.eq(normal(enVivo));
      });
    });
  });

  it('elegir un formato o un estado actualiza enseguida, y «Limpiar» vuelve a la lista completa', () => {
    conBusquedaEnVivo();
    cy.get('#f-q').type('Campus');
    tarjetas().should('have.length', 2);
    cy.get('#f-tipo').select('Liga');
    tarjetas().should('have.length', 1).and('contain', 'Liga Campus 2026');
    cy.location('search').should('contain', 'tipo=liga');
    cy.get('#f-estado').select('Inscripción');
    cy.get('.sin-resultados').should('contain', 'No encontramos torneos con esa búsqueda.');
    cy.get('#estado-lista').should('have.text', 'No se encontraron torneos.');
    sinRecargar();
    cy.contains('.filtros a', 'Limpiar').click();                 // «Limpiar» es un enlace común: recarga la página
    cy.location('search').should('eq', '');
    tarjetas().should('have.length.at.least', 5);
  });

  it('con más de una página, los botones de página cambian la lista sin recargar', () => {
    conBusquedaEnVivo();
    cy.get('.paginacion').should('exist');                         // el entorno acumula torneos de las otras pruebas: hay varias páginas
    cy.get('#lista-torneos .resumen').invoke('text').then((antes) => {
      cy.get('.paginacion a[aria-label="Página 2"]').click();
      cy.get('#lista-torneos .resumen').should('not.have.text', antes).and('contain', 'Mostrando 13–');
      sinRecargar();
      cy.location('search').should('eq', '?page=2');
      cy.get('.paginacion [aria-current="page"]').should('have.text', '2');
      cy.get('.paginacion a[rel="prev"]').click();
      cy.get('.paginacion [aria-current="page"]').should('have.text', '1');
      sinRecargar();
    });
  });

  it('una pausa al tipear evita una consulta por tecla: «Ajedrez» tecla a tecla hace una sola', () => {
    cy.intercept('GET', '/api/torneos*').as('api');
    conBusquedaEnVivo();
    cy.get('#f-q').type('Ajedrez', { delay: 40 });
    tarjetas().should('have.length', 1);
    cy.get('@api.all').should('have.length', 1);
  });

  it('mientras llega la respuesta la lista se atenúa y queda marcada como ocupada', () => {
    cy.intercept('GET', '/api/torneos*', (req) => req.continue((res) => { res.delay = 1500; })).as('lenta');
    conBusquedaEnVivo();
    cy.get('#f-q').type('Ajedrez');
    cy.get('#lista-torneos').should('have.class', 'cargando').and('have.attr', 'aria-busy', 'true');
    cy.wait('@lenta');
    cy.get('#lista-torneos').should('not.have.class', 'cargando').and('not.have.attr', 'aria-busy');
    tarjetas().should('have.length', 1);
  });

  it('una respuesta vieja que llega tarde no pisa a la nueva', () => {
    let n = 0;
    cy.intercept('GET', '/api/torneos*', (req) => {
      n += 1;
      if (n === 1) req.continue((res) => { res.delay = 2500; });     // la primera consulta tarda; la segunda llega antes
    }).as('api');
    conBusquedaEnVivo();
    cy.get('#f-q').type('Liga');
    cy.get('@api.all').should('have.length', 1);                   // la primera ya salió
    cy.get('#f-q').clear().type('Ajedrez');
    tarjetas().should('have.length', 1).and('contain', 'Campus Cup Ajedrez');
    cy.wait(3000);                                                  // la primera llegaría ahora
    tarjetas().should('have.length', 1).and('contain', 'Campus Cup Ajedrez');
    cy.location('search').should('eq', '?q=Ajedrez');
  });

  it('si falla la red, la lista queda como estaba y arriba aparece el motivo', () => {
    conBusquedaEnVivo();
    tarjetas().its('length').then((antes) => {
      cy.intercept('GET', '/api/torneos*', { forceNetworkError: true });
      cy.get('#f-q').type('Ajedrez');
      cy.get('.alerta-lista').should('be.visible').and('have.attr', 'role', 'alert').and('contain', 'No se pudo conectar');
      tarjetas().should('have.length', antes);
      cy.get('#lista-torneos').should('not.have.class', 'cargando');
    });
  });

  it('si la API responde un error, se muestra su mensaje', () => {
    cy.intercept('GET', '/api/torneos*', { statusCode: 500, body: { ok: false, error: { codigo: 'error_interno', mensaje: 'Algo falló en el servidor.', id: 'ab12' } } });
    conBusquedaEnVivo();
    cy.get('#f-q').type('Ajedrez');
    cy.get('.alerta-lista').should('contain', 'Algo falló en el servidor.');
  });

  it('el botón «Buscar» y Enter también funcionan, sin recargar', () => {
    conBusquedaEnVivo();
    cy.get('#f-q').type('Ajedrez{enter}');
    tarjetas().should('have.length', 1);
    sinRecargar();
    cy.get('#f-q').clear().type('Campus');
    cy.get('form.filtros button[type="submit"]').click();
    tarjetas().should('have.length', 2);
    sinRecargar();
  });

  it('un torneo con HTML en el nombre se dibuja como texto', () => {
    cy.iniciarSesion('admin');
    cy.unico('XssLive').then((u) => {
      const nombre = `<b>negrita</b><img src=x onerror="window.__xss=1"> ${u}`;
      cy.crearTorneo({ nombre, formato: 'Liga' });
      cy.clearCookies();
      conBusquedaEnVivo();
      cy.get('#f-q').type(u, { parseSpecialCharSequences: false });
      tarjetas().should('have.length', 1);
      cy.get('#lista-torneos article strong').invoke('text').should('contain', '<b>negrita</b><img src=x');
      cy.get('#lista-torneos article b').should('not.exist');
      cy.get('#lista-torneos article img').should('not.exist');
      cy.window().its('__xss').should('be.undefined');
    });
  });

  it('el aviso para lectores de pantalla existe y es una región «status»', () => {
    conBusquedaEnVivo();
    cy.get('#estado-lista').should('have.attr', 'role', 'status').and('have.class', 'solo-lectores');
    cy.get('form.filtros[data-en-vivo]').should('have.attr', 'data-en-vivo', '/api/torneos');
  });

  it('en el celular también funciona y no desborda', () => {
    cy.viewport(375, 812);
    conBusquedaEnVivo();
    cy.get('#f-q').type('Campus');
    tarjetas().should('have.length', 2);
    cy.sinScrollHorizontal();
  });
});
