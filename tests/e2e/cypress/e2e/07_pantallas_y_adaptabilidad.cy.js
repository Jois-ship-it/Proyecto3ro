// Cada pantalla en tres anchos (celular, tableta, escritorio): sin scroll horizontal, con un solo h1, con su título, con un contenido
// principal al que se llega desde «Saltar al contenido», y sin violaciones de la política de seguridad de contenido (CSP).
// Solo lectura: no escribe nada.
const ANCHOS = [
  { nombre: 'celular', ancho: 375, alto: 812 },
  { nombre: 'tableta', ancho: 768, alto: 1024 },
  { nombre: 'escritorio', ancho: 1280, alto: 800 },
];

const PUBLICAS = ['/', '/torneos', '/torneos?q=liga', '/torneos/calendario', '/torneo/1', '/torneo/2', '/torneo/3', '/login', '/registro', '/esto-no-existe'];
const ADMIN = ['/admin', '/admin/torneos', '/admin/torneos/1', '/admin/torneos/2', '/admin/torneos/3', '/admin/torneos/crear', '/admin/participantes', '/admin/equipos',
  '/admin/organizadores', '/admin/administradores', '/admin/registros', '/admin/correcciones', '/admin/auditoria', '/admin/reportes', '/admin/torneos/1/reporte',
  '/admin/modulos', '/admin/permisos', '/admin/configuracion'];
const ORGANIZADOR = ['/organizador', '/organizador/torneos', '/organizador/torneos/1'];
const PARTICIPANTE = ['/participante', '/participante/torneos', '/participante/perfil'];

/** Registra las violaciones de la CSP que ocurran mientras la página carga y se usa. */
const vigilarCsp = () => {
  const violaciones = [];
  cy.on('window:before:load', (win) => {
    win.addEventListener('securitypolicyviolation', (e) => violaciones.push(`${e.violatedDirective}: ${e.blockedURI}`));
  });
  return violaciones;
};

const revisar = (ruta, rol) => {
  const esError = ruta === '/esto-no-existe';
  const violaciones = vigilarCsp();
  cy.visit(ruta, { failOnStatusCode: !esError });
  cy.title().should('not.be.empty').and('not.eq', 'undefined');
  cy.get('h1').should('have.length', 1);
  cy.get('main, section.page, [role="main"]').should('exist');
  cy.get('img').each(($i) => expect($i.attr('alt'), `alt de ${$i.attr('src')}`).to.not.be.undefined);
  cy.sinScrollHorizontal();
  cy.wrap(violaciones).should('have.length', 0);
};

describe('Pantallas y adaptabilidad', () => {
  // Un torneo con un nombre largo SIN ESPACIOS (una dirección, una palabra enorme, texto pegado): no se puede partir en renglones y, si el
  // maquetado no lo prevé, ensancha la tarjeta y la página entera. Se crea a propósito para que esas pantallas siempre lo lleven.
  let largo = null;
  before(() => {
    cy.iniciarSesion('admin');
    cy.unico('Largo').then((u) => {
      cy.crearTorneo({ nombre: `${'PalabraSinEspacios'.repeat(6)}${u.replace(/\s+/g, '')}`, formato: 'Liga' }).then((id) => { largo = id; });
    });
  });
  const conElLargo = () => [`/torneo/${largo}`, `/admin/torneos/${largo}`, `/admin/torneos/${largo}/reporte`];

  ANCHOS.forEach(({ nombre, ancho, alto }) => {
    describe(`en ${nombre} (${ancho} px)`, () => {
      beforeEach(() => cy.viewport(ancho, alto));

      it('las pantallas públicas', () => {
        [...PUBLICAS, `/torneo/${largo}`].forEach((ruta) => revisar(ruta));
      });

      it('las pantallas de administración', () => {
        cy.iniciarSesion('admin');
        [...ADMIN, ...conElLargo().slice(1)].forEach((ruta) => revisar(ruta));
      });

      it('las pantallas del organizador', () => {
        cy.iniciarSesion('organizador');
        [...ORGANIZADOR, `/organizador/torneos/${largo}`].forEach((ruta) => revisar(ruta));
      });

      it('las pantallas del participante', () => {
        cy.iniciarSesion('vale');
        PARTICIPANTE.forEach((ruta) => revisar(ruta));
      });
    });
  });

  describe('menús', () => {
    it('en el celular el menú público se abre y se cierra con el botón', () => {
      cy.viewport(375, 812);
      cy.visit('/');
      cy.get('[data-public-nav]').should('not.have.class', 'open');
      cy.get('[data-nav-toggle]').should('be.visible').click();
      cy.get('[data-public-nav]').should('have.class', 'open');
      cy.get('[data-public-nav] a').contains('Torneos').should('be.visible');
      cy.get('[data-nav-toggle]').click();
      cy.get('[data-public-nav]').should('not.have.class', 'open');
    });

    it('en el celular el menú del panel es un cajón: se abre con ☰ y se cierra al tocar afuera', () => {
      cy.viewport(375, 812);
      cy.iniciarSesion('admin');
      cy.visit('/admin');
      cy.get('[data-sidebar]').should('not.have.class', 'open');
      cy.get('[data-sidebar-toggle]').click().should('have.attr', 'aria-expanded', 'true');
      cy.get('[data-sidebar]').should('have.class', 'open');
      cy.get('[data-sidebar] a').contains('Torneos').should('be.visible');
      cy.get('h1').click({ force: true });                    // un toque afuera
      cy.get('[data-sidebar]').should('not.have.class', 'open');
    });

    it('en el escritorio el menú del panel está siempre a la vista y no hay botón ☰', () => {
      cy.viewport(1280, 800);
      cy.iniciarSesion('admin');
      cy.visit('/admin');
      cy.get('[data-sidebar]').should('be.visible');
      cy.get('[data-sidebar-toggle]').should('not.be.visible');
    });

    it('«Saltar al contenido» aparece al recibir el foco del teclado y lleva al contenido', () => {
      cy.visit('/torneos');
      cy.get('a.saltar').focus().should('be.visible');
      cy.get('a.saltar').click();
      cy.location('hash').should('eq', '#contenido');
      cy.focused().should('have.attr', 'id', 'contenido');
    });
  });
});
