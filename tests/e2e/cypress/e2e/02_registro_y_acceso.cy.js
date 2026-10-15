// Registro de un participante, su aprobación por un administrador, el acceso y la salida.
// Cada ejecución crea su propia cuenta con un correo único: se puede repetir sin limpiar nada.
describe('Registro y acceso', () => {
  // Clave de la cuenta que crea la prueba (un valor de prueba, de un entorno descartable).
  const CLAVE = 'Clave-Segura-9!';

  describe('formulario de registro', () => {
    beforeEach(() => cy.visit('/registro'));

    it('las reglas de la contraseña se marcan a medida que se escribe y el botón espera a que se cumplan todas', () => {
      cy.get('#submitBtn').should('be.disabled');
      cy.get('#pwHints li').should('have.length', 6);
      cy.get('#password').type('abc', { log: false });
      cy.get('#pwHints li[data-rule="lower"]').should('have.class', 'cumple');
      cy.get('#pwHints li[data-rule="len"]').should('not.have.class', 'cumple');
      cy.get('#pwHints li[data-rule="upper"]').should('not.have.class', 'cumple');

      cy.get('#password').clear().type(CLAVE, { log: false });
      cy.get('#pwHints li[data-rule="match"]').should('not.have.class', 'cumple');
      cy.get('#submitBtn').should('be.disabled');
      cy.get('#password_confirm').type(CLAVE, { log: false });
      cy.get('#pwHints li.cumple').should('have.length', 6);
      cy.get('#submitBtn').should('not.be.disabled');

      cy.get('#password_confirm').clear().type('otra', { log: false });
      cy.get('#submitBtn').should('be.disabled');
    });

    it('no deja registrar un correo que ya existe', () => {
      cy.get('#nombre').type('Alguien Repetido');
      cy.get('#email').type('matias@example.com');
      cy.get('#password').type(CLAVE, { log: false });
      cy.get('#password_confirm').type(CLAVE, { log: false });
      cy.get('#submitBtn').click();
      cy.location('pathname').should('eq', '/registro');
      cy.get('.aviso-auth').should('contain', 'Ese email ya está registrado.');
      cy.get('#nombre').should('have.value', 'Alguien Repetido');          // no se pierde lo escrito
      cy.get('#password').should('have.value', '');                         // pero la contraseña no vuelve
    });
  });

  it('una persona se registra, queda pendiente, un administrador la aprueba y entra a su panel', () => {
    cy.unico('Persona E2E').then((nombre) => {
      const correo = `e2e.${nombre.split(' ').pop().toLowerCase()}@example.com`;

      // 1) se registra
      cy.visit('/registro');
      cy.get('#nombre').type(nombre);
      cy.get('#nick').type('e2e');
      cy.get('#email').type(correo);
      cy.get('#password').type(CLAVE, { log: false });
      cy.get('#password_confirm').type(CLAVE, { log: false });
      cy.get('#submitBtn').click();
      cy.location('pathname').should('eq', '/login');
      cy.aviso('quedó pendiente de aprobación');

      // 2) todavía no puede entrar: con la clave correcta, la cuenta «no está activa»
      cy.iniciarSesionCon(correo, CLAVE);
      cy.location('pathname').should('eq', '/login');
      cy.get('.aviso-auth').should('contain', 'Tu cuenta no está activa');

      // 3) el administrador la ve en «Registros» y la aprueba
      cy.iniciarSesion('admin');
      cy.visit('/admin/registros');
      cy.get('#f-q').type(correo);
      cy.get('form.filtros button[type="submit"]').click();
      cy.contains('tr', correo).within(() => {
        cy.contains('td', nombre);
        cy.contains('button', 'Aprobar').click();             // el diálogo de confirmación se acepta solo
      });
      cy.aviso('Registro aprobado');
      cy.contains('tr', correo).should('not.exist');

      // 4) ahora sí entra, a su panel de participante
      cy.clearCookies();
      cy.iniciarSesionCon(correo, CLAVE);
      cy.location('pathname').should('eq', '/participante');
      cy.get('h1').should('exist');

      // 5) cierra sesión
      cy.visit('/logout');
      cy.location('pathname').should('eq', '/login');
      cy.aviso('Sesión cerrada correctamente.');          // el aviso de despedida viaja en la sesión nueva
      cy.visit('/participante');
      cy.location('pathname').should('eq', '/login');
    });
  });

  it('un registro se puede rechazar y esa persona no entra', () => {
    cy.unico('Rechazada E2E').then((nombre) => {
      const correo = `e2e.${nombre.split(' ').pop().toLowerCase()}@example.com`;
      cy.visit('/registro');
      cy.get('#nombre').type(nombre);
      cy.get('#email').type(correo);
      cy.get('#password').type(CLAVE, { log: false });
      cy.get('#password_confirm').type(CLAVE, { log: false });
      cy.get('#submitBtn').click();
      cy.aviso('quedó pendiente');

      cy.iniciarSesion('admin');
      cy.visit(`/admin/registros?q=${encodeURIComponent(correo)}`);
      cy.contains('tr', correo).contains('button', 'Rechazar').click();
      cy.aviso('Registro rechazado');

      cy.clearCookies();
      cy.iniciarSesionCon(correo, CLAVE);
      cy.location('pathname').should('eq', '/login');
      cy.get('.aviso-auth').should('contain', 'no está activa');
    });
  });

  describe('acceso con las cuentas del sistema', () => {
    it('cada rol cae en su panel', () => {
      cy.iniciarSesion('admin');
      cy.visit('/');
      cy.visit('/admin');
      cy.location('pathname').should('eq', '/admin');
      cy.get('aside.sidebar').should('contain', 'Registros');
    });

    it('el organizador entra a su panel y no al de administración (403)', () => {
      cy.iniciarSesion('organizador');
      cy.visit('/organizador');
      cy.location('pathname').should('eq', '/organizador');
      for (const ruta of ['/admin', '/admin/registros', '/admin/auditoria', '/admin/modulos']) {
        cy.request({ url: ruta, failOnStatusCode: false }).its('status').should('eq', 403);
      }
      cy.visit('/admin/registros', { failOnStatusCode: false });
      cy.esSinPermisos();
    });

    it('un participante no entra al panel del administrador ni al del organizador (403)', () => {
      cy.iniciarSesion('matias');
      cy.visit('/participante');
      cy.location('pathname').should('eq', '/participante');
      for (const ruta of ['/admin', '/admin/registros', '/admin/auditoria', '/organizador']) {
        cy.request({ url: ruta, failOnStatusCode: false }).its('status').should('eq', 403);
      }
      cy.visit('/admin', { failOnStatusCode: false });
      cy.esSinPermisos();
    });

    it('una clave equivocada no dice si el correo existe', () => {
      cy.iniciarSesionCon('matias@example.com', 'ClaveEquivocada-1!');
      cy.get('.aviso-auth').should('contain', 'Email o contraseña incorrectos.');
      cy.iniciarSesionCon('no.existe@example.com', 'ClaveEquivocada-1!');
      cy.get('.aviso-auth').should('contain', 'Email o contraseña incorrectos.');
    });

    it('después de varios intentos fallidos la cuenta se bloquea, y un administrador la desbloquea', () => {
      cy.unico('Bloqueable E2E').then((nombre) => {
        const correo = `e2e.${nombre.split(' ').pop().toLowerCase()}@example.com`;
        cy.visit('/registro');
        cy.get('#nombre').type(nombre);
        cy.get('#email').type(correo);
        cy.get('#password').type(CLAVE, { log: false });
        cy.get('#password_confirm').type(CLAVE, { log: false });
        cy.get('#submitBtn').click();
        cy.iniciarSesion('admin');
        cy.visit(`/admin/registros?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Aprobar').click();
        cy.aviso('Registro aprobado');
        cy.clearCookies();

        for (let i = 0; i < 5; i++) cy.iniciarSesionCon(correo, 'ClaveEquivocada-1!');
        cy.iniciarSesionCon(correo, CLAVE);           // ni siquiera la correcta
        cy.get('.aviso-auth').should('contain', 'bloqueada');

        cy.clearCookies();
        cy.iniciarSesion('admin');
        cy.visit(`/admin/participantes?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Desbloquear').click();
        cy.clearCookies();
        cy.iniciarSesionCon(correo, CLAVE);
        cy.location('pathname').should('eq', '/participante');
      });
    });
  });

  describe('protecciones del formulario de ingreso', () => {
    it('un POST sin el token CSRF se rechaza', () => {
      cy.request({ method: 'POST', url: '/login', form: true, body: { email: 'matias@example.com', password: 'x' }, failOnStatusCode: false, followRedirect: false })
        .its('status').should('be.oneOf', [302, 403, 419]);
      cy.visit('/login');
      cy.location('pathname').should('eq', '/login');
    });

    it('cerrar sesión invalida la cookie de antes: volver a presentarla no entra', () => {
      cy.iniciarSesion('vale');
      cy.visit('/participante');
      cy.location('pathname').should('eq', '/participante');
      cy.getCookie('PHPSESSID').then((vieja) => {
        cy.visit('/logout');
        cy.location('pathname').should('eq', '/login');
        cy.setCookie('PHPSESSID', vieja.value, { secure: true, httpOnly: true });     // alguien que copió la cookie antes de que se fuera
        cy.visit('/participante');
        cy.location('pathname').should('eq', '/login');
      });
    });

    it('«/logout» por GET cierra sesión sin romper, y el login por GET solo muestra el formulario', () => {
      cy.visit('/login');
      cy.get('form[action="/login"]').should('exist');
      cy.get('input[name="csrf_token"]').should('have.length', 1);
    });

    it('un redireccionamiento hacia otro sitio no se acepta como destino', () => {
      cy.visit('/login?redirect=https://ejemplo-malo.test/robo');
      cy.location('host').should('not.contain', 'ejemplo-malo');
    });
  });
});
