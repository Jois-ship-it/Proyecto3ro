// Los casos del plan de testing (docs/plan_testing.md) que dependen del navegador y de la sesión, y que hasta ahora se recorrían a mano.
// El número del plan va entre corchetes al principio de cada caso: [1], [10], [33]…
const GENTE = ['Matías López', 'Valentina García', 'Nicolás Pérez', 'Camila Torres', 'Lucas Romero', 'Sofía Méndez', 'Diego Alvarez', 'Ana Fernández'];

/** POST de un formulario de la aplicación saltándose la pantalla, con el token CSRF de la página que está abierta. */
const publicar = (url, cuerpo) => cy.get('input[name="csrf_token"]').first().invoke('val').then((token) =>
  cy.request({ method: 'POST', url, form: true, body: { csrf_token: token, ...cuerpo }, followRedirect: false, failOnStatusCode: false }));

describe('Casos del plan de testing', () => {
  describe('Autenticación', () => {
    it('[1][2][3] cada rol, al ingresar, cae en su panel', () => {
      for (const [rol, destino] of [['admin', '/admin'], ['organizador', '/organizador'], ['matias', '/participante']]) {
        cy.clearCookies();
        cy.task('credencial', rol, { log: false }).then(({ email, clave }) => cy.iniciarSesionCon(email, clave));
        cy.location('pathname').should('eq', destino);
      }
    });

    it('[6] entrar a /admin sin sesión lleva a /login con un mensaje', () => {
      cy.visit('/admin');
      cy.location('pathname').should('eq', '/login');
      cy.get('.aviso-auth').should('contain', 'Debés iniciar sesión');
    });

    it('[7] «Cerrar sesión» del panel lleva a /login', () => {
      // Una sesión PROPIA de este caso (no la que Cypress guarda con cy.session): cerrarla en el servidor invalidaría la de los demás.
      cy.task('credencial', 'admin', { log: false }).then(({ email, clave }) => cy.iniciarSesionCon(email, clave));
      cy.visit('/admin');
      cy.contains('a, button', 'Cerrar sesión').click();
      cy.location('pathname').should('eq', '/login');
    });
  });

  describe('Liga', () => {
    beforeEach(() => cy.iniciarSesion('admin'));

    it('[9] con 8 inscritos el fixture son 7 rondas de 4 partidos', () => {
      cy.unico('Liga 8 E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.inscribirParticipantes(id, GENTE);
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').should('have.length', 28);                     // 8·7/2
          cy.contains('.panel strong', 'Fecha 7').should('exist');
          cy.contains('.panel strong', 'Fecha 8').should('not.exist');
          cy.get('table[aria-label="Partidos de la ronda"]').should('have.length', 7);          // siete rondas…
          cy.get('table[aria-label="Partidos de la ronda"]').each(($t) => expect($t.find('tbody tr').length, 'partidos por ronda').to.eq(4));   // …de cuatro partidos
        });
      });
    });

    it('[10][43] con 5 inscritos (impar) hay 5 rondas de 2 partidos: nadie juega contra «nadie» y descansa uno por ronda', () => {
      cy.unico('Liga 5 E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.inscribirParticipantes(id, GENTE.slice(0, 5));
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').should('have.length', 10);                     // 5·4/2
          cy.get('table[aria-label="Partidos de la ronda"] tbody tr').each(($f) => {
            expect($f.find('td').eq(1).text().trim(), 'local').to.not.be.empty;
            expect($f.find('td').eq(3).text().trim(), 'visitante').to.not.be.oneOf(['', '—']);   // ninguna fila con un rival vacío
          });
          cy.get('table[aria-label="Partidos de la ronda"]').should('have.length', 5);        // cinco rondas
          cy.get('table[aria-label="Partidos de la ronda"]').each(($t) => expect($t.find('tbody tr').length).to.eq(2));
        });
      });
    });

    it('[12] con empates permitidos, un 1 a 1 suma el punto de empate a los dos', () => {
      cy.unico('Liga empates E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga', empates: true }).then((id) => {
          cy.inscribirParticipantes(id, GENTE.slice(0, 4));
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').first().click();
          cy.get('#modalCargar input[name="puntos_a"]').clear().type('1');
          cy.get('#modalCargar input[name="puntos_b"]').clear().type('1');
          cy.window().then((w) => { w.__antes = true; });
          cy.get('#modalCargar form button[type="submit"]').click();
          cy.window().should((w) => expect(w.__antes).to.be.undefined);
          cy.contains('td', '1 — 1').should('exist');
          // La tabla de posiciones: dos filas con un empate y 1 punto (3 por victoria, 1 por empate, por defecto)
          cy.get('table[aria-label*="osiciones"] tbody tr').then(($filas) => {
            const conPunto = [...$filas].filter((f) => f.textContent.replace(/\s+/g, ' ').trim().endsWith(' 1'));
            expect(conPunto.length, 'filas con 1 punto').to.be.at.least(2);
          });
        });
      });
    });

    it('[40] un resultado con puntos negativos no se acepta (aunque se salteen los campos del navegador)', () => {
      cy.unico('Liga negativos E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.inscribirParticipantes(id, GENTE.slice(0, 3));
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').first().invoke('attr', 'data-enf-id').then((enf) => {
            publicar('/admin/resultados/cargar', { enfrentamiento_id: enf, torneo_id: id, puntos_a: -1, puntos_b: 2 }).then((r) => {
              expect(r.status).to.be.oneOf([302, 422]);
            });
            cy.visit(`/admin/torneos/${id}`);
            cy.get('button[data-modal-cargar]').should('have.length', 3);                  // nadie quedó cargado
          });
        });
      });
    });

    it('[41] cargar dos veces el mismo partido no se acepta: se ofrece «Corregir»', () => {
      cy.unico('Liga duplicado E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.inscribirParticipantes(id, GENTE.slice(0, 3));
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').first().invoke('attr', 'data-enf-id').then((enf) => {
            cy.get('button[data-modal-cargar]').first().click();
            cy.get('#modalCargar input[name="puntos_a"]').clear().type('2');
            cy.get('#modalCargar input[name="puntos_b"]').clear().type('0');
            cy.window().then((w) => { w.__antes = true; });
            cy.get('#modalCargar form button[type="submit"]').click();
            cy.window().should((w) => expect(w.__antes).to.be.undefined);
            // la segunda carga, por un envío directo (el botón ya no está)
            publicar('/admin/resultados/cargar', { enfrentamiento_id: enf, torneo_id: id, puntos_a: 5, puntos_b: 5 }).then((r) => expect(r.status).to.be.oneOf([302, 422]));
            cy.visit(`/admin/torneos/${id}`);
            cy.contains('td', '2 — 0').should('exist');
            cy.contains('td', '5 — 5').should('not.exist');
          });
        });
      });
    });
  });

  describe('Eliminación directa', () => {
    beforeEach(() => cy.iniciarSesion('admin'));

    const bracket = (nombre, cantidad) => cy.crearTorneo({ nombre, formato: 'Eliminación Directa' }).then((id) => {
      cy.inscribirParticipantes(id, GENTE.slice(0, cantidad));
      cy.generarCompetencia(id);
      return cy.wrap(id);
    });

    it('[16] con 4 inscritos: 2 semifinales y una final', () => {
      cy.unico('Bracket 4 E2E').then((n) => bracket(n, 4).then(() => {
        cy.get('.bk-card:not(.champion)').should('have.length', 3);
        cy.get('.bk-card.clickable[aria-haspopup]').should('have.length', 2);               // solo las semifinales están listas
      }));
    });

    it('[17] con 8 inscritos: 4 cuartos, 2 semifinales y una final', () => {
      cy.unico('Bracket 8 E2E').then((n) => bracket(n, 8).then(() => {
        cy.get('.bk-card:not(.champion)').should('have.length', 7);
        cy.get('.bk-card.clickable[aria-haspopup]').should('have.length', 4);
      }));
    });

    it('[18][20] con 6 inscritos hay 2 pases libres, y se dice «Avanza por bye»', () => {
      cy.unico('Bracket 6 E2E').then((n) => bracket(n, 6).then(() => {
        cy.contains('.bk-card .bk-row .sc', 'BYE').should('exist');
        cy.get('.bk-card .bk-row .sc').filter(':contains("BYE")').should('have.length', 2);
        cy.contains('.bk-card .bk-row .nm', 'Avanza por bye').should('exist');
      }));
    });

    it('[39] con 2 inscritos es una sola partida: la final directa', () => {
      cy.unico('Bracket 2 E2E').then((n) => bracket(n, 2).then(() => {
        cy.get('.bk-card:not(.champion)').should('have.length', 1);
        cy.cargarTodosLosResultados([2, 0]);
        cy.contains('.chip', 'Finalizado').should('exist');
      }));
    });

    it('[19][21][22][23] el ganador avanza, un empate no vale, no se corrige lo que ya avanzó, y la final da campeón', () => {
      cy.unico('Bracket reglas E2E').then((n) => bracket(n, 4).then((id) => {
        // [23] empate: el servidor lo rechaza y el motivo se ve dentro del diálogo
        cy.get('.bk-card.clickable[aria-haspopup]').first().click();
        cy.get('.bk-action-menu:visible [data-action="cargar"]').first().click();
        cy.get('#modalCargar input[name="puntos_a"]').clear().type('2');
        cy.get('#modalCargar input[name="puntos_b"]').clear().type('2');
        cy.get('#modalCargar form button[type="submit"]').click();
        cy.get('#modalCargar [data-api-error]').should('be.visible').and('contain', 'mpate');
        cy.get('#modalCargar').should('have.attr', 'aria-hidden', 'false');
        cy.get('#modalCargar [data-cerrar-modal]').click();

        // [19] se juegan las dos semifinales: el ganador de cada una aparece en la final
        cy.cargarTodosLosResultados([3, 1]);                                                // semifinales y final
        cy.contains('.chip', 'Finalizado').should('exist');                                  // [21] campeón
        // [22] corregir una semifinal cuando el ganador ya avanzó: no se puede
        cy.get('.bk-card.clickable[data-modal-corregir]').first().click();
        cy.get('#modalCorregir').should('have.attr', 'aria-hidden', 'false');
        cy.get('#corregirPuntosA').clear().type('0');
        cy.get('#corregirPuntosB').clear().type('3');
        cy.get('#modalCorregir textarea[name="motivo_correccion"]').type('Se cargó al revés el marcador.');
        cy.get('#modalCorregir form button[type="submit"]').click();
        cy.get('#modalCorregir [data-api-error]').should('be.visible').and('contain', 'ronda posterior');
      }));
    });
  });

  describe('Sistema suizo', () => {
    beforeEach(() => cy.iniciarSesion('admin'));

    const suizo = (nombre, cantidad) => cy.crearTorneo({ nombre, formato: 'Sistema Suizo', rondas: 3 }).then((id) => {
      cy.inscribirParticipantes(id, GENTE.slice(0, cantidad));
      cy.generarCompetencia(id);
      return cy.wrap(id);
    });

    it('[24] con 6 inscritos la primera ronda son 3 partidos y nadie descansa', () => {
      cy.unico('Suizo 6 E2E').then((n) => suizo(n, 6).then(() => {
        cy.get('button[data-modal-cargar]').should('have.length', 3);
        cy.get('.chip').filter(':contains("Bye")').should('not.exist');
      }));
    });

    it('[25] con 7 inscritos son 3 partidos y un descanso (bye)', () => {
      cy.unico('Suizo 7 E2E').then((n) => suizo(n, 7).then(() => {
        cy.get('button[data-modal-cargar]').should('have.length', 3);
        cy.contains('.chip', 'Bye').should('exist');
      }));
    });

    it('[26] no se genera la ronda siguiente con partidos pendientes (ni por un envío directo)', () => {
      cy.unico('Suizo pendiente E2E').then((n) => suizo(n, 4).then((id) => {
        cy.contains('button', /Generar ronda 2/).should('not.exist');
        publicar(`/admin/torneos/${id}/siguiente-ronda`, {}).then((r) => expect(r.status).to.eq(302));
        cy.visit(`/admin/torneos/${id}`);
        cy.get('.flash-msg').should('contain', 'partidos pendientes');
        cy.get('button[data-modal-cargar]').should('have.length', 2);                        // sigue la ronda 1, no se generó otra
      }));
    });
  });

  describe('Límites', () => {
    beforeEach(() => cy.iniciarSesion('admin'));

    it('[37][38] con 0 o 1 inscrito no se genera nada: el servidor lo rechaza con su motivo', () => {
      cy.unico('Pocos E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.visit(`/admin/torneos/${id}`);
          publicar(`/admin/torneos/${id}/generar`, {}).then((r) => expect(r.status).to.eq(302));
          cy.visit(`/admin/torneos/${id}`);
          cy.get('.flash-msg').should('contain', 'Se necesitan al menos 2');
          cy.inscribirParticipantes(id, [GENTE[0]]);
          publicar(`/admin/torneos/${id}/generar`, {}).then((r) => expect(r.status).to.eq(302));
          cy.visit(`/admin/torneos/${id}`);
          cy.get('.flash-msg').should('contain', 'Se necesitan al menos 2');
          cy.get('button[data-modal-cargar]').should('not.exist');
        });
      });
    });

    it('[42] un torneo que ya empezó no se vuelve a generar', () => {
      cy.unico('Doble generar E2E').then((nombre) => {
        cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
          cy.inscribirParticipantes(id, GENTE.slice(0, 3));
          cy.generarCompetencia(id);
          cy.get('button[data-modal-cargar]').should('have.length', 3);
          publicar(`/admin/torneos/${id}/generar`, {}).then((r) => expect(r.status).to.eq(302));
          cy.visit(`/admin/torneos/${id}`);
          cy.get('.flash-msg').should('contain', 'estado');
          cy.get('button[data-modal-cargar]').should('have.length', 3);                      // sigue igual: no se duplicó nada
        });
      });
    });
  });

  describe('Seguridad', () => {
    it('[32] una inyección SQL en el correo no autentica y da el error de siempre', () => {
      // El campo de correo valida el formato en el navegador: se le quita (novalidate) para que decida el SERVIDOR.
      const ingresar = (correo) => {
        cy.visit('/login');
        cy.get('form[action="/login"]').invoke('attr', 'novalidate', 'novalidate');
        cy.get('#email').type(correo, { parseSpecialCharSequences: false });
        cy.get('#password').type('cualquiera-1A!', { log: false });
        cy.get('form[action="/login"] button[type="submit"]').click();
      };
      ingresar("' OR 1=1 --");
      cy.location('pathname').should('eq', '/login');
      cy.get('.aviso-auth').should('contain', 'Email o contraseña incorrectos.');
      ingresar("admin@flexarena.com' OR '1'='1");
      cy.get('.aviso-auth').should('contain', 'Email o contraseña incorrectos.');
      cy.visit('/admin');
      cy.location('pathname').should('eq', '/login');                                       // y no quedó con sesión
    });

    it('[33] un nombre con HTML se muestra como texto en todas partes donde aparece', () => {
      const CLAVE = 'Clave-Segura-9!';
      cy.unico('Xss').then((u) => {
        const nombre = `<img src=x onerror="window.__xss=1"> ${u}`;
        const correo = `e2e.${u.split(' ').pop().toLowerCase()}@example.com`;
        cy.visit('/registro');
        cy.get('#nombre').type(nombre, { parseSpecialCharSequences: false });
        cy.get('#email').type(correo);
        cy.get('#password').type(CLAVE, { log: false });
        cy.get('#password_confirm').type(CLAVE, { log: false });
        cy.get('#submitBtn').click();

        cy.iniciarSesion('admin');
        cy.visit(`/admin/registros?q=${encodeURIComponent(correo)}`);
        cy.contains('td', '<img src=x onerror=').should('exist');                           // se ve el texto, tal cual
        cy.get('main img[src="x"]').should('not.exist');                                      // y no se creó ninguna imagen
        cy.window().its('__xss').should('be.undefined');
        // al aprobarlo aparece en los listados de participantes, también escapado
        cy.contains('tr', correo).contains('button', 'Aprobar').click();
        cy.visit(`/admin/participantes?q=${encodeURIComponent(correo)}`);
        cy.get('main img[src="x"]').should('not.exist');
        cy.window().its('__xss').should('be.undefined');
        cy.visit(`/admin/auditoria?q=${encodeURIComponent(correo)}`);
        cy.get('main img[src="x"]').should('not.exist');
      });
    });

    it('[34] un POST de administración sin el token CSRF se rechaza, y no cambia nada', () => {
      cy.iniciarSesion('admin');
      cy.visit('/admin/modulos');
      cy.request({ method: 'POST', url: '/admin/modulos/toggle/3', form: true, body: {}, followRedirect: false, failOnStatusCode: false })
        .its('status').should('be.oneOf', [302, 403, 419]);
      cy.visit('/admin/modulos');
      cy.contains('article', 'Sistema Suizo').should('contain', 'Activo');
    });

    it('[35][36] un participante no entra a /admin/torneos ni puede cargar un resultado (403)', () => {
      cy.iniciarSesion('matias');
      cy.visit('/participante/perfil');                                                      // tiene un formulario (y su token)
      cy.request({ url: '/admin/torneos', failOnStatusCode: false }).its('status').should('eq', 403);
      publicar('/admin/resultados/cargar', { enfrentamiento_id: 1, torneo_id: 1, puntos_a: 9, puntos_b: 0 }).then((r) => expect(r.status).to.eq(403));
      publicar('/admin/resultados/corregir', { enfrentamiento_id: 1, torneo_id: 1, puntos_a: 9, puntos_b: 0, motivo_correccion: 'Intento de un participante' }).then((r) => expect(r.status).to.eq(403));
    });
  });
});
