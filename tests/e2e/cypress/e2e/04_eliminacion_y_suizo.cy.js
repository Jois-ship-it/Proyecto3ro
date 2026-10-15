// Los otros dos formatos de competencia, de punta a punta: eliminación directa (con un número impar, que obliga a un pase libre)
// y sistema suizo (rondas generadas una por una). Cada ejecución crea sus propios torneos.
describe('Eliminación directa y sistema suizo (administrador)', () => {
  const CINCO = ['Matías López', 'Valentina García', 'Nicolás Pérez', 'Camila Torres', 'Lucas Romero'];
  const CUATRO = CINCO.slice(0, 4);

  beforeEach(() => cy.iniciarSesion('admin'));

  it('eliminación directa con 5 participantes: hay un pase libre, el ganador avanza y se llega a la final', () => {
    cy.unico('Bracket E2E').then((nombre) => {
      cy.crearTorneo({ nombre, formato: 'Eliminación Directa' }).then((id) => {
        cy.inscribirParticipantes(id, CINCO);
        cy.generarCompetencia(id);

        // 5 participantes → una llave de 8 lugares: se ve el bracket y un partido con «Bye»
        cy.contains('.bk-card .bk-row .sc', 'BYE').should('exist');

        // Se juega todo, ronda tras ronda: las siguientes aparecen cuando avanzan los ganadores
        cy.cargarTodosLosResultados([3, 1]);
        cy.get('button[data-modal-cargar], .bk-card.clickable[aria-haspopup]').should('not.exist');
        cy.contains('.chip', 'Finalizado').should('exist');

        // La ficha pública cuenta con campeón y el bracket
        cy.visit(`/torneo/${id}`);
        cy.get('h1').should('contain', nombre);
        cy.contains('Finalizado').should('exist');
      });
    });
  });

  it('sistema suizo de 2 rondas con 4 participantes: la segunda ronda se genera a mano cuando termina la primera', () => {
    cy.unico('Suizo E2E').then((nombre) => {
      cy.crearTorneo({ nombre, formato: 'Sistema Suizo', rondas: 2 }).then((id) => {
        cy.inscribirParticipantes(id, CUATRO);
        cy.generarCompetencia(id);
        cy.get('button[data-modal-cargar]').should('have.length', 2);           // 4 participantes → 2 partidos por ronda
        cy.contains('button', /Generar ronda 2/).should('not.exist');             // todavía no terminó la primera

        cy.cargarTodosLosResultados([1, 0]);
        cy.contains('button', 'Generar ronda 2').click();                         // ahora sí: la ronda 2
        cy.get('button[data-modal-cargar]').should('have.length', 2);

        cy.cargarTodosLosResultados([1, 0]);
        cy.get('button[data-modal-cargar], .bk-card.clickable[aria-haspopup]').should('not.exist');
        cy.contains('.chip', 'Finalizado').should('exist');
        cy.contains('button', /Generar ronda 3/).should('not.exist');             // era de 2 rondas: no hay una tercera
      });
    });
  });

  it('un torneo por equipos usa los equipos del sistema (mínimo de integrantes) y se juega igual', () => {
    cy.unico('Equipos E2E').then((nombre) => {
      cy.crearTorneo({ nombre, formato: 'Liga', modalidad: 'equipos' }).then((id) => {
        cy.visit(`/admin/torneos/${id}`);
        for (const equipo of ['Aqua Academy', 'Team Atlas', 'Nexo Gaming']) {
          cy.get('#inscripciones-equipo').clear().type(equipo.split(' ')[0]);
          cy.contains('.combobox-list li', equipo).click();
          cy.contains('.panel button', 'Inscribir equipo').click();
          cy.aviso('Equipo inscrito.');
        }
        cy.contains('h2', 'Inscripciones (3)').should('exist');
        cy.generarCompetencia(id);
        cy.get('button[data-modal-cargar]').should('have.length', 3);            // 3 equipos → 3 partidos
        cy.cargarTodosLosResultados([2, 0]);
        cy.contains('.chip', 'Finalizado').should('exist');
      });
    });
  });

  it('con menos de dos inscritos no se ofrece generar la competencia', () => {
    cy.unico('Vacio E2E').then((nombre) => {
      cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
        cy.visit(`/admin/torneos/${id}`);
        cy.contains('button', 'Generar competencia').should('not.exist');
        cy.inscribirParticipantes(id, [CINCO[0]]);
        cy.contains('button', 'Generar competencia').should('not.exist');
        cy.inscribirParticipantes(id, [CINCO[1]]);
        cy.contains('button', 'Generar competencia').should('exist');
      });
    });
  });
});
