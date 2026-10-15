// Sitio público: lo que ve una persona sin sesión. Solo lectura: no escribe nada en la base.
describe('Sitio público (sin sesión)', () => {
  it('la portada trae el sitio, el menú y el acceso', () => {
    cy.visit('/');
    cy.title().should('contain', 'FlexArena');
    cy.get('main#contenido').should('exist');
    cy.get('nav a').contains('Torneos').should('be.visible');
    cy.contains('a', 'Ingresar').should('have.attr', 'href', '/login');
  });

  it('el enlace «Saltar al contenido» existe y lleva al contenido principal', () => {
    cy.visit('/');
    cy.get('a.saltar').should('have.attr', 'href', '#contenido');
  });

  describe('lista de torneos', () => {
    beforeEach(() => cy.visit('/torneos'));

    // El entorno aislado acumula los torneos que crean las otras pruebas: los del seed ya no están en la primera página. Por eso lo que
    // se busca se busca por nombre, y de la lista completa solo se mira que haya varias tarjetas.
    it('muestra los torneos públicos, y el borrador no aparece ni buscándolo', () => {
      cy.get('#lista-torneos article.card').should('have.length.at.least', 5);
      cy.visit('/torneos?q=Campus');
      cy.contains('#lista-torneos', 'Liga Campus 2026');
      cy.contains('#lista-torneos', 'Campus Cup Ajedrez');
      cy.visit('/torneos?q=Copa Mental');
      cy.get('.sin-resultados').should('exist');
    });

    it('busca por nombre (sin importar mayúsculas) y la dirección queda copiable', () => {
      cy.get('#f-q').type('AJEDREZ');
      cy.get('form.filtros button[type="submit"]').click();
      cy.location('search').should('contain', 'q=AJEDREZ');   // el formulario GET manda también los campos vacíos (tipo= y estado=)
      cy.get('#lista-torneos article.card').should('have.length', 1).and('contain', 'Campus Cup Ajedrez');
      cy.get('.resumen').should('contain', 'Mostrando 1–1 de 1 torneos');
    });

    it('filtra por formato y por estado, y «Limpiar» vuelve a la lista completa', () => {
      cy.get('#f-q').type('Campus');
      cy.get('#f-tipo').select('Liga');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('#lista-torneos article.card').should('have.length', 1).and('contain', 'Liga Campus 2026');       // «Campus Cup Ajedrez» es de otro formato

      cy.get('#f-estado').select('Inscripción');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('.sin-resultados').should('exist');                                                              // Liga Campus está «en curso»

      cy.get('#f-estado').select('En curso');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('#lista-torneos article.card').should('have.length', 1).and('contain', 'Liga Campus 2026');

      cy.contains('a', 'Limpiar').click();
      cy.location('search').should('eq', '');
      cy.get('#lista-torneos article.card').should('have.length.at.least', 5);
    });

    it('sin coincidencias lo dice, y no inventa una paginación', () => {
      cy.get('#f-q').type('zzzz-no-existe');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('.sin-resultados').should('contain', 'No encontramos torneos con esa búsqueda.');
      cy.get('#lista-torneos article').should('not.exist');
    });

    it('un texto con símbolos de HTML se muestra como texto, no se interpreta', () => {
      cy.get('#f-q').type('<img src=x onerror=alert(1)>');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('.sin-resultados').should('exist');
      cy.get('#f-q').should('have.value', '<img src=x onerror=alert(1)>');
      cy.get('main img[src="x"]').should('not.exist');
    });
  });

  it('la ficha de un torneo se abre desde la lista', () => {
    cy.visit('/torneos?q=Liga Campus');
    cy.contains('article', 'Liga Campus 2026').contains('a', 'Ver detalle').click();
    cy.location('pathname').should('match', /^\/torneo\/\d+$/);
    cy.get('h1').should('contain', 'Liga Campus 2026');
  });

  it('un borrador no se abre por su dirección (da 404)', () => {
    cy.request({ url: '/torneo/6', failOnStatusCode: false }).its('status').should('eq', 404);
  });

  describe('calendario', () => {
    it('muestra el mes, tiene siete días de semana y navega entre meses', () => {
      cy.visit('/torneos/calendario');
      cy.get('#calendar .cal-dia-semana').should('have.length', 7);
      cy.get('#calendar .cal-mes').invoke('text').then((mes) => {
        cy.get('#calendar button[aria-label="Mes siguiente"]').click();
        cy.get('#calendar .cal-mes').invoke('text').should('not.eq', mes);
        cy.get('#calendar button[aria-label="Mes anterior"]').click();
        cy.get('#calendar .cal-mes').invoke('text').should('eq', mes);
      });
    });

    it('un torneo aparece el día en que empieza y enlaza a su ficha', () => {
      cy.visit('/torneos/calendario');
      // «Liga Campus 2026» empieza el 1 de marzo de 2026: se retrocede hasta ese mes.
      const retroceder = () => cy.get('#calendar .cal-mes').invoke('text').then((mes) => {
        if (!/marzo de 2026/i.test(mes)) { cy.get('#calendar button[aria-label="Mes anterior"]').click().then(retroceder); }
      });
      retroceder();
      cy.get('#calendar .cal-mes').should('contain', 'marzo');
      cy.contains('#calendar .cal-dia', 'Liga Campus 2026').find('.cal-numero').should('have.text', '1');
      cy.contains('#calendar a.cal-enlace', 'Liga Campus 2026').click();
      cy.location('pathname').should('match', /^\/torneo\/\d+$/);
    });

    it('en pantalla de celular: puntos y lista del mes en lugar de tarjetas, sin desbordar', () => {
      cy.viewport(375, 812);
      cy.visit('/torneos/calendario');
      const hastaMarzo = () => cy.get('#calendar .cal-mes').invoke('text').then((mes) => {
        if (!/marzo de 2026/i.test(mes)) { cy.get('#calendar button[aria-label="Mes anterior"]').click().then(hastaMarzo); }
      });
      hastaMarzo();
      cy.get('#calendar .cal-punto').should('be.visible');
      cy.get('#calendar .cal-lista').should('not.be.visible');
      cy.get('#calendar .cal-agenda').should('be.visible').and('contain', 'Liga Campus 2026');
      cy.sinScrollHorizontal();
    });
  });

  it('una dirección que no existe da 404 con título propio', () => {
    cy.visit('/esto-no-existe', { failOnStatusCode: false });
    cy.title().should('contain', 'Página no encontrada');
  });

  it('sin sesión no se entra al panel: lleva al login', () => {
    cy.visit('/admin');
    cy.location('pathname').should('eq', '/login');
    cy.visit('/organizador');
    cy.location('pathname').should('eq', '/login');
    cy.visit('/participante');
    cy.location('pathname').should('eq', '/login');
  });

  describe('API pública y cabeceras', () => {
    it('la lista de torneos es JSON, de solo lectura y no abre sesión', () => {
      cy.request('/api/torneos?por_pagina=50').then((r) => {
        expect(r.status).to.eq(200);
        expect(r.headers['content-type']).to.contain('application/json');
        expect(r.headers).to.not.have.property('set-cookie');
        expect(r.body.ok).to.eq(true);
        expect(r.body.datos.length).to.be.at.least(5);
        expect(r.body.datos.map((t) => t.nombre)).to.not.include('Copa Mental Suizo');
        expect(r.body.meta.filtros).to.have.keys('q', 'tipo', 'estado');
      });
    });

    it('con otro método responde 405 y con una ruta rara 404, siempre en JSON', () => {
      cy.request({ method: 'POST', url: '/api/torneos', failOnStatusCode: false }).then((r) => {
        expect(r.status).to.be.oneOf([403, 405]);
      });
      cy.request({ url: '/api/torneos/999999/posiciones', failOnStatusCode: false }).then((r) => {
        expect(r.status).to.eq(404);
        expect(r.body.ok).to.eq(false);
      });
    });

    it('cada respuesta trae su política de seguridad, sin código en línea', () => {
      cy.request('/').then((r) => {
        const csp = r.headers['content-security-policy'];
        expect(csp, 'CSP').to.be.a('string');
        expect(csp).to.contain("script-src 'self'");
        expect(csp).to.not.contain("'unsafe-inline'");
        expect(csp).to.not.contain("'unsafe-eval'");
      });
    });
  });
});
