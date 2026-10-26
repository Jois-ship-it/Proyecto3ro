// Lo que hace un administrador fuera de los torneos: módulos, configuración del sitio, auditoría, reportes y cuentas.
// Lo que cambia se deja como estaba (módulo activo, nombre del sitio original) aunque la prueba falle: ver el `after`.
describe('Administración', () => {
  beforeEach(() => cy.iniciarSesion('admin'));

  /** Deja un módulo activo (por si una prueba falló a medias). */
  const asegurarActivo = (nombre) => {
    cy.visit('/admin/modulos');
    cy.contains('article', nombre).then(($a) => {
      if ($a.text().includes('Inactivo')) cy.wrap($a).contains('button', 'Activar').click();
    });
  };

  after(() => {
    cy.iniciarSesion('admin');
    asegurarActivo('Sistema Suizo');
    cy.visit('/admin/configuracion');
    cy.get('#cfg_sitio_nombre').clear().type('FlexArena');
    cy.contains('button', 'Guardar cambios').click();
  });

  describe('módulos', () => {
    it('apagar un formato lo saca del formulario de alta, y volver a encenderlo lo devuelve', () => {
      cy.visit('/admin/torneos/crear');
      cy.get('#tipoTorneo option').then(($o) => expect($o.text()).to.contain('Sistema Suizo'));

      cy.visit('/admin/modulos');
      cy.contains('article', 'Sistema Suizo').contains('button', 'Desactivar').click();       // el diálogo de confirmación se acepta solo
      cy.aviso('Módulo «Sistema Suizo» desactivado.');
      cy.contains('article', 'Sistema Suizo').should('contain', 'Inactivo');

      cy.visit('/admin/torneos/crear');
      cy.get('#tipoTorneo option:not([disabled])').then(($o) => expect($o.text()).to.not.contain('Sistema Suizo'));
      cy.get('#tipoTorneo option').then(($o) => expect($o.text()).to.contain('Liga'));          // los otros formatos siguen

      cy.visit('/admin/modulos');
      cy.contains('article', 'Sistema Suizo').contains('button', 'Activar').click();
      cy.aviso('Módulo «Sistema Suizo» activado.');
      cy.visit('/admin/torneos/crear');
      cy.get('#tipoTorneo option:not([disabled])').then(($o) => expect($o.text()).to.contain('Sistema Suizo'));
    });

    it('con el módulo apagado no se puede crear un torneo de ese formato aunque se fuerce el envío', () => {
      cy.visit('/admin/modulos');
      cy.contains('article', 'Sistema Suizo').contains('button', 'Desactivar').click();
      cy.aviso('desactivado');

      cy.visit('/admin/torneos/crear');
      cy.unico('Forzado E2E').then((nombre) => {
        // se arma el envío a mano, con el formato apagado (como lo haría alguien salteándose la pantalla)
        cy.get('#torneoForm input[name="csrf_token"]').invoke('val').then((token) => {
          cy.request({
            method: 'POST', url: '/admin/torneos/crear', form: true, followRedirect: false, failOnStatusCode: false,
            body: { csrf_token: token, nombre, tipo_torneo_id: 3, modalidad: 'individual', organizador_id: 2, estado: 'borrador', fecha_inicio: '2027-01-10', fecha_fin: '2027-02-10', rondas_suizo: 3, bye_suizo: 'sin_puntos' },
          }).then((r) => expect(r.status).to.eq(302));
        });
        cy.visit(`/admin/torneos?q=${encodeURIComponent(nombre)}`);
        cy.contains('tr', nombre).should('not.exist');                                          // no se creó
      });
      asegurarActivo('Sistema Suizo');
    });
  });

  describe('configuración del sitio', () => {
    it('el nombre del sitio se cambia y se ve en la portada; un valor inválido no se guarda y no pierde lo escrito', () => {
      cy.visit('/admin/configuracion');
      cy.get('#cfg_sitio_nombre').clear().type('Liga E2E <b>negrita</b>');
      cy.contains('button', 'Guardar cambios').click();
      cy.aviso('Configuración guardada');
      cy.visit('/');
      cy.get('.brand').first().should('contain.text', 'Liga E2E <b>negrita</b>');             // se escribe como texto, no como HTML
      cy.get('.brand b').should('not.exist');

      cy.visit('/admin/configuracion');
      cy.get('#cfg_login_max_intentos').clear().type('500');                                  // el máximo es 20
      cy.get('#cfg_sitio_nombre').clear().type('Otro nombre sin guardar');
      cy.contains('button', 'Guardar cambios').click();
      cy.get('.flash-msg').should('contain', 'entre 1 y 20');
      cy.get('#cfg_sitio_nombre').should('have.value', 'Otro nombre sin guardar');             // lo escrito vuelve
      cy.get('#cfg_login_max_intentos').should('have.value', '500');
      cy.visit('/');
      cy.get('.brand').first().should('contain.text', 'Liga E2E');                             // y nada de lo del formulario inválido se guardó

      cy.visit('/admin/configuracion');
      cy.get('#cfg_sitio_nombre').clear().type('FlexArena');
      cy.contains('button', 'Guardar cambios').click();
      cy.aviso('Configuración guardada');
    });

    it('con los perfiles públicos apagados, un visitante no ve el perfil de un participante; con la sesión sí', () => {
      cy.visit('/admin/configuracion');
      cy.get('#cfg_perfiles_publicos').then(($c) => {
        const estabaActivo = $c.prop('checked');
        if (estabaActivo) cy.wrap($c).uncheck();
        cy.contains('button', 'Guardar cambios').click();
        cy.aviso('Configuración guardada');

        cy.clearCookies();
        cy.request({ url: '/jugador/1', followRedirect: false, failOnStatusCode: false }).its('status').should('eq', 302);   // pide iniciar sesión
        cy.iniciarSesion('admin');
        cy.request({ url: '/jugador/1', failOnStatusCode: false }).its('status').should('eq', 200);

        cy.visit('/admin/configuracion');
        if (estabaActivo) cy.get('#cfg_perfiles_publicos').check();
        cy.contains('button', 'Guardar cambios').click();
      });
    });
  });

  describe('auditoría', () => {
    it('registra lo que pasó en el sistema, se puede buscar y filtrar, y no tiene cómo borrarse', () => {
      cy.visit('/admin/auditoria');
      cy.get('table[aria-label="Registro de auditoría"] tbody tr').should('have.length.at.least', 3);
      cy.get('h1').should('contain', 'Auditoría');

      // Lo de esta misma sesión: el cambio de módulos y de configuración de las pruebas anteriores
      cy.get('#f-q').type('Módulo');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('table[aria-label="Registro de auditoría"] tbody tr').should('have.length.at.least', 1);
      cy.get('table[aria-label="Registro de auditoría"] tbody').should('contain', 'Sistema Suizo');

      cy.get('#f-q').clear().type('texto-que-no-existe-en-ningun-registro');
      cy.get('form.filtros button[type="submit"]').click();
      cy.get('table[aria-label="Registro de auditoría"] tbody').should('contain', 'Ningún registro coincide con la búsqueda.');

      // Solo se lee: ni un botón ni un formulario que borre o edite
      cy.visit('/admin/auditoria');
      cy.get('main form[method="POST"]').should('not.exist');
      cy.contains('button', /borrar|eliminar|vaciar/i).should('not.exist');
    });

    it('un organizador no puede ver la auditoría', () => {
      cy.clearCookies();
      cy.iniciarSesion('organizador');
      cy.request({ url: '/admin/auditoria', failOnStatusCode: false }).its('status').should('eq', 403);
    });
  });

  describe('reportes', () => {
    it('el reporte general se ve y se descarga en CSV, con encabezados y sin fórmulas peligrosas', () => {
      cy.visit('/admin/reportes');
      cy.get('h1').should('contain', 'Reportes');
      cy.request('/admin/reportes/torneos.csv').then((r) => {
        expect(r.status).to.eq(200);
        expect(r.headers['content-type']).to.contain('text/csv');
        expect(r.headers['content-disposition']).to.contain('attachment');
        const lineas = r.body.replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
        expect(lineas.length, 'una fila de encabezado y al menos un torneo').to.be.at.least(2);
        expect(lineas[0]).to.match(/Torneo/);
        expect(lineas[0].split(';').length, 'separado por «;» (así lo abre Excel en español)').to.be.at.least(8);
        // Ninguna celda empieza con = + - @ (se interpretaría como fórmula en una planilla)
        lineas.slice(1).forEach((l) => l.split(';').forEach((c) => expect(c.replace(/^"/, '')).to.not.match(/^[=+@]/)));
      });
    });

    it('el reporte de un torneo tiene su tabla y sus tres CSV', () => {
      cy.visit('/admin/torneos?q=Liga Campus');
      cy.contains('tr', 'Liga Campus 2026').find('a[href$="/reporte"], a[href*="/admin/torneos/"]').first().invoke('attr', 'href').then((href) => {
        const id = href.match(/\/admin\/torneos\/(\d+)/)[1];
        cy.visit(`/admin/torneos/${id}/reporte`);
        cy.get('h1').should('exist');
        for (const archivo of ['posiciones', 'partidos', 'inscritos']) {
          cy.request(`/admin/torneos/${id}/reporte/${archivo}.csv`).then((r) => {
            expect(r.status).to.eq(200);
            expect(r.headers['content-type']).to.contain('text/csv');
          });
        }
      });
    });

    it('un participante no accede a los reportes', () => {
      cy.clearCookies();
      cy.iniciarSesion('matias');
      cy.request({ url: '/admin/reportes', failOnStatusCode: false }).its('status').should('eq', 403);
      cy.request({ url: '/admin/reportes/torneos.csv', failOnStatusCode: false }).its('status').should('eq', 403);
    });
  });

  describe('cuentas', () => {
    it('un administrador bloquea una cuenta: no puede entrar; al desbloquearla, sí', () => {
      // Se usa una cuenta propia de esta prueba: se registra y se aprueba
      const CLAVE = 'Clave-Segura-9!';
      cy.clearCookies();
      cy.unico('Cuenta E2E').then((nombre) => {
        const correo = `e2e.${nombre.split(' ').pop().toLowerCase()}@example.com`;
        cy.visit('/registro');
        cy.get('#nombre').type(nombre);
        cy.get('#email').type(correo);
        cy.get('#password').type(CLAVE, { log: false });
        cy.get('#password_confirm').type(CLAVE, { log: false });
        cy.get('#acepto_politicas').check();
        cy.get('#submitBtn').click();
        cy.iniciarSesion('admin');
        cy.visit(`/admin/registros?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Aprobar').click();
        cy.aviso('Registro aprobado');

        cy.visit(`/admin/participantes?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Bloquear').click();
        cy.aviso('Cuenta bloqueada correctamente.');
        cy.visit(`/admin/participantes?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Desbloquear').should('exist');      // la fila ahora ofrece desbloquear

        cy.clearCookies();
        cy.iniciarSesionCon(correo, CLAVE);
        cy.location('pathname').should('eq', '/login');
        cy.get('.aviso-auth').should('contain', 'bloqueada');

        cy.iniciarSesion('admin');
        cy.visit(`/admin/participantes?q=${encodeURIComponent(correo)}`);
        cy.contains('tr', correo).contains('button', 'Desbloquear').click();
        cy.clearCookies();
        cy.iniciarSesionCon(correo, CLAVE);
        cy.location('pathname').should('eq', '/participante');
      });
    });

    it('un administrador no puede dejar el sistema sin administradores (el último no se borra ni se bloquea)', () => {
      cy.visit('/admin/administradores');
      cy.get('table tbody tr').then(($filas) => {
        if ($filas.length === 1) {
          cy.get('table tbody tr').first().contains('button', /Eliminar|Bloquear/).should('not.exist');
        } else {
          cy.log('hay más de un administrador: la regla se prueba en los tests de PHP');
        }
      });
    });
  });
});
