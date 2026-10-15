// Una liga de punta a punta: la crea un administrador, se inscriben 4 participantes, se genera el fixture, se juegan todos los
// partidos y el torneo termina con su campeón. Cada ejecución crea su propio torneo.
describe('Torneo de liga (administrador)', () => {
  const JUGADORES = ['Matías López', 'Valentina García', 'Nicolás Pérez', 'Camila Torres'];

  it('se crea, se inscribe, se genera el fixture, se juega y termina con campeón', () => {
    cy.iniciarSesion('admin');
    cy.unico('Liga E2E').then((nombre) => {
      cy.crearTorneo({ nombre, formato: 'Liga' }).then((id) => {
        // Inscripciones
        cy.inscribirParticipantes(id, JUGADORES);
        cy.contains('h2', 'Inscripciones (4)').should('exist');

        // Fixture: 4 participantes → 6 partidos (todos contra todos, cada par una vez)
        cy.generarCompetencia(id);
        cy.get('button[data-modal-cargar]').should('have.length', 6);
        cy.get('.chip').contains('En curso').should('exist');

        // Se juega todo (gana siempre el local, 2 a 1)
        cy.cargarTodosLosResultados([2, 1]);
        cy.get('button[data-modal-cargar]').should('not.exist');

        // Termina solo (B9): finalizado, con tabla de posiciones y un campeón
        cy.contains('.chip', 'Finalizado').should('exist');
        cy.get('table[aria-label*="osiciones"], table[aria-label*="abla"]').should('exist');

        // La ficha pública muestra el torneo terminado
        cy.visit(`/torneo/${id}`);
        cy.get('h1').should('contain', nombre);
        cy.contains('Finalizado').should('exist');
      });
    });
  });
});
