// Comandos propios de las pruebas de FlexArena.
import './torneos';

/**
 * Inicia sesión por la interfaz con la cuenta de un rol del seed (admin, organizador, organizadora, matias, vale).
 * La clave llega por una tarea de Node (no pasa por la configuración de Cypress) y se escribe sin registrarla.
 * La sesión se guarda con cy.session: el segundo caso del mismo rol no vuelve a pasar por el formulario.
 *
 * El servidor cambia el identificador de una sesión iniciada cada 15 minutos (Session::REGENERAR_CADA_SEGUNDOS) y la que guardó
 * cy.session es la de cuando se inició: en una corrida larga (una máquina cargada tarda mucho más) deja de valer. Por eso la sesión
 * guardada se COMPRUEBA antes de usarla (`validate`): si ya no abre el panel de su rol, Cypress vuelve a iniciar sesión.
 */
const PANEL_DEL_ROL = { admin: '/admin', organizador: '/organizador', organizadora: '/organizador', matias: '/participante', vale: '/participante' };

Cypress.Commands.add('iniciarSesion', (rol) => {
  cy.task('credencial', rol, { log: false }).then(({ email, clave }) => {
    cy.session(['sesion', rol], () => {
      cy.visit('/login');
      cy.get('#email').type(email);
      cy.get('#password').type(clave, { log: false });
      cy.get('form[action="/login"] button[type="submit"]').click();
      cy.location('pathname').should('not.eq', '/login');
    }, {
      validate() {
        cy.request({ url: PANEL_DEL_ROL[rol], followRedirect: false, failOnStatusCode: false }).its('status').should('eq', 200);
      },
    });
  });
});

/** Inicia sesión con un correo y una clave cualquiera (para las cuentas que crea la prueba). La clave no se registra. */
Cypress.Commands.add('iniciarSesionCon', (email, clave) => {
  cy.visit('/login');
  cy.get('#email').type(email);
  cy.get('#password').type(clave, { log: false });
  cy.get('form[action="/login"] button[type="submit"]').click();
});

/** Un aviso de la página con este texto: `.flash-msg` en el panel y el sitio, `.aviso-auth` en las pantallas de ingreso y registro. */
Cypress.Commands.add('aviso', (texto) => cy.get('.flash-msg, .aviso-auth').should('contain.text', texto));

/** La página es el «403 Sin permisos» (la aplicación no redirige: responde 403 en la misma dirección). */
Cypress.Commands.add('esSinPermisos', () => cy.get('h1').should('contain', 'Sin permisos'));

/** La página no se desborda en horizontal (lo que arruina un celular). Si lo hace, dice QUÉ elementos se pasan del borde. */
Cypress.Commands.add('sinScrollHorizontal', () => {
  cy.document().then((doc) => {
    const ancho = doc.documentElement.clientWidth;
    if (doc.documentElement.scrollWidth <= ancho + 1) return;
    const culpables = [...doc.body.querySelectorAll('*')]
      .filter((e) => e.getBoundingClientRect().right > ancho + 1 && e.getBoundingClientRect().width > 0)
      .filter((e) => ![...e.children].some((h) => h.getBoundingClientRect().right > ancho + 1))             // el más profundo de cada rama
      .slice(0, 4)
      .map((e) => `<${e.tagName.toLowerCase()}${e.className ? '.' + String(e.className).trim().split(/\s+/).join('.') : ''}> «${(e.textContent || '').trim().slice(0, 40)}»`);
    expect(doc.documentElement.scrollWidth, `ancho del contenido; se pasan del borde: ${culpables.join(' | ')}`).to.be.at.most(ancho + 1);
  });
});

/** Un texto único para que una prueba no choque con lo que dejó otra corrida (la base del entorno aislado persiste hasta `bajar`). */
Cypress.Commands.add('unico', (prefijo) => cy.wrap(`${prefijo} ${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`));
