// Corregir un resultado ya cargado: el organizador SOLICITA y el administrador aprueba o rechaza; el administrador también puede
// corregir directo. Y un organizador no toca los torneos de otro. Las pruebas comparten UN torneo (se arma en la primera) y trabajan
// cada una sobre un partido distinto, en este orden.
describe('Correcciones de resultados y propiedad de los torneos', () => {
  let nombre;
  let id;

  /** Abre el diálogo de corrección del partido n (0, 1, 2…) de la página y lo completa. */
  const corregir = (n, puntosA, puntosB, motivo) => {
    cy.get('button[data-modal-corregir]').eq(n).click();
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');
    cy.get('#corregirPuntosA').clear().type(String(puntosA));
    cy.get('#corregirPuntosB').clear().type(String(puntosB));
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').type(motivo);
    cy.window().then((w) => { w.__antesDeEnviar = true; });
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.window().should((w) => { expect(w.__antesDeEnviar, 'la página se recargó').to.be.undefined; });
  };

  it('preparación: un administrador arma una liga de Carlos, con todos sus partidos jugados 2 a 1', () => {
    cy.iniciarSesion('admin');
    cy.unico('Correcciones E2E').then((n) => {
      nombre = n;
      cy.crearTorneo({ nombre, formato: 'Liga', organizador: 'Carlos' }).then((torneoId) => {
        id = torneoId;
        cy.inscribirParticipantes(id, ['Matías López', 'Valentina García', 'Nicolás Pérez', 'Camila Torres']);
        cy.generarCompetencia(id);
        cy.cargarTodosLosResultados([2, 1]);
        cy.get('button[data-modal-corregir]').should('have.length', 6);
      });
    });
  });

  it('el organizador pide corregir un resultado: queda pendiente y no cambia nada; el administrador la aprueba y se aplica', () => {
    cy.iniciarSesion('organizador');
    cy.visit(`/organizador/torneos/${id}`);
    cy.contains('button', 'Solicitar corrección').should('exist');
    cy.get('button[data-modal-corregir]').first().click();
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');
    cy.get('#corregirPuntosA').clear().type('0');
    cy.get('#corregirPuntosB').clear().type('3');
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').type('Se cargó el marcador al revés por error.');
    cy.window().then((w) => { w.__antesDeEnviar = true; });
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.window().should((w) => { expect(w.__antesDeEnviar).to.be.undefined; });
    cy.aviso('olicitud');

    // el resultado todavía es el viejo (2 — 1) y el partido NO figura como corregido
    cy.visit(`/organizador/torneos/${id}`);
    cy.contains('td', '2 — 1').should('exist');
    cy.contains('.chip', 'Corregido').should('not.exist');

    // el administrador la ve y la aprueba
    cy.iniciarSesion('admin');
    cy.visit('/admin/correcciones');
    cy.contains('tr', nombre).within(() => {
      cy.contains('0');
      cy.contains('Se cargó el marcador al revés por error.');
      cy.contains('button', 'Aprobar').click();
    });
    cy.aviso('Solicitud aprobada');
    cy.visit(`/admin/torneos/${id}`);
    cy.contains('td', '0 — 3').should('exist');
    cy.contains('.chip', 'Corregido').should('exist');
  });

  it('una solicitud se puede rechazar con su motivo: el resultado no cambia', () => {
    cy.iniciarSesion('organizador');
    cy.visit(`/organizador/torneos/${id}`);
    cy.get('button[data-modal-corregir]').eq(1).click();
    cy.get('#corregirPuntosA').clear().type('1');
    cy.get('#corregirPuntosB').clear().type('3');
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').type('Creo que ganó el visitante, pero no estoy seguro.');
    cy.window().then((w) => { w.__antes = true; });
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.window().should((w) => { expect(w.__antes).to.be.undefined; });

    cy.iniciarSesion('admin');
    cy.visit('/admin/correcciones');
    cy.contains('tr', 'Creo que ganó el visitante').within(() => cy.contains('button', 'Rechazar').click());
    cy.get('#modalRechazar').should('have.attr', 'aria-hidden', 'false');
    cy.get('#modalRechazar textarea[name="motivo_rechazo"]').type('El acta dice 2 a 1.');
    cy.get('#modalRechazar form button[type="submit"]').click();
    cy.aviso('Solicitud rechazada');
    cy.contains('tr', 'Creo que ganó el visitante').should('contain', 'echaz');       // «Rechazado»/«Rechazada»
    cy.visit(`/admin/torneos/${id}`);
    cy.contains('td', '1 — 3').should('not.exist');
  });

  it('el administrador corrige directo; un motivo corto o un empate (la liga no los permite) no se aceptan', () => {
    cy.iniciarSesion('admin');
    cy.visit(`/admin/torneos/${id}`);
    cy.get('button[data-modal-corregir]').eq(2).click();
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');

    // 1) motivo de menos de 10 caracteres: el navegador no deja enviar...
    cy.get('#corregirPuntosA').clear().type('5');
    cy.get('#corregirPuntosB').clear().type('0');
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').type('corto');
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');
    cy.contains('td', '5 — 0').should('not.exist');
    //    ...y si se saltea el navegador (se le quita el minlength), lo rechaza el SERVIDOR, con su motivo dentro del diálogo.
    //    Esto se descubrió acá: el servidor solo miraba que no estuviera vacío y la corrección «5 — 0» entraba con el motivo «corto».
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').invoke('removeAttr', 'minlength');
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.get('#modalCorregir [data-api-error]').should('be.visible').and('contain', 'mínimo 10 caracteres');
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');
    cy.contains('td', '5 — 0').should('not.exist');

    // 2) un empate en una liga sin empates: lo rechaza el servidor, y el motivo se ve DENTRO del diálogo, que no se cierra
    cy.get('#corregirPuntosA').clear().type('4');
    cy.get('#corregirPuntosB').clear().type('4');
    cy.get('#modalCorregir textarea[name="motivo_correccion"]').clear().type('El árbitro corrigió el acta del partido.');
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.get('#modalCorregir [data-api-error]').should('be.visible').and('contain', 'mpate');
    cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');

    // 3) un resultado válido: se aplica y la página se recarga
    cy.get('#corregirPuntosA').clear().type('5');
    cy.get('#corregirPuntosB').clear().type('0');
    cy.window().then((w) => { w.__antes = true; });
    cy.get('#modalCorregir form button[type="submit"]').click();
    cy.window().should((w) => { expect(w.__antes).to.be.undefined; });
    cy.contains('td', '5 — 0').should('exist');
  });

  it('otro organizador no entra a un torneo que no es suyo: lo mandan a su lista con un aviso', () => {
    cy.iniciarSesion('organizadora');                        // Laura; el torneo es de Carlos
    cy.request({ url: `/organizador/torneos/${id}`, followRedirect: false, failOnStatusCode: false }).then((r) => {
      expect(r.status).to.eq(302);
      expect(r.headers.location).to.match(/\/organizador\/torneos$/);
    });
    // lo mismo con los pedidos que escriben: no se puede cargar un resultado ni pedir una corrección en un torneo ajeno
    cy.visit(`/organizador/torneos/${id}`);
    cy.location('pathname').should('eq', '/organizador/torneos');
    cy.aviso('No tenés acceso a este torneo.');
    cy.contains('button', 'Solicitar corrección').should('not.exist');
    cy.contains(nombre).should('not.exist');                 // y no figura en su lista
  });

  it('el organizador dueño ve su torneo en su lista', () => {
    cy.iniciarSesion('organizador');
    cy.visit('/organizador/torneos');
    cy.contains(nombre).should('exist');
  });
});
