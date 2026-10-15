// Comandos para armar y jugar un torneo por la interfaz (como lo haría un administrador). Los usan varias especificaciones.
// Hay que haber iniciado sesión antes (cy.iniciarSesion('admin')).

const dos = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
const enDias = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return iso(d); };

/**
 * Crea un torneo por el formulario de administración y devuelve (como valor) su identificador.
 * opciones: {nombre, formato: 'Liga'|'Eliminación Directa'|'Sistema Suizo', modalidad: 'individual'|'equipos', estado, rondas, empates, organizador}
 */
Cypress.Commands.add('crearTorneo', (opciones) => {
  const { nombre, formato, modalidad = 'individual', estado = 'Inscripción abierta', rondas = null, empates = false, organizador = 'Carlos', minIntegrantes = 3 } = opciones;
  cy.visit('/admin/torneos/crear');
  cy.get('#torneo-form-nombre').type(nombre);
  cy.get('#tipoTorneo').select(formato);
  cy.get('#modalidad').select(modalidad === 'equipos' ? 'Por equipos' : 'Individual (participantes)');
  if (modalidad === 'equipos') cy.get('#torneo-form-min_integrantes_equipo').clear().type(String(minIntegrantes));
  cy.contains('#torneo-form-organizador_id option', organizador).then(($o) => cy.get('#torneo-form-organizador_id').select($o.val()));
  cy.get('#torneo-form-estado').select(estado);
  cy.get('#torneo-form-fecha_inicio').type(enDias(1));
  cy.get('#torneo-form-fecha_fin').type(enDias(30));
  if (empates) cy.get('input[name="permite_empates"]').check();
  if (rondas !== null) cy.get('#torneo-form-rondas_suizo').clear().type(String(rondas));
  cy.contains('button', /Crear torneo/).click();
  cy.location('pathname').should('eq', '/admin/torneos');
  cy.aviso('Torneo creado correctamente.');
  // El listado lo ordena por nombre o por fecha: se busca por nombre para encontrarlo en cualquier página.
  cy.visit(`/admin/torneos?q=${encodeURIComponent(nombre)}`);
  return cy.contains('tr', nombre).find('a[href]').then(($as) => {
    const enlace = [...$as].map((a) => a.getAttribute('href')).find((h) => /^\/admin\/torneos\/\d+$/.test(h));
    expect(enlace, 'enlace a la gestión del torneo').to.be.a('string');
    return cy.wrap(Number(enlace.split('/').pop()));
  });
});

/** Inscribe participantes (por nombre) en un torneo individual abierto. */
Cypress.Commands.add('inscribirParticipantes', (torneoId, nombres) => {
  cy.visit(`/admin/torneos/${torneoId}`);
  nombres.forEach((nombre) => {
    cy.get('#inscripciones-participante').clear().type(nombre.split(' ')[0]);
    cy.contains('.combobox-list li', nombre).click();
    cy.contains('.panel button', 'Inscribir').click();
    cy.aviso('inscri');                                       // «Participante inscrito…»
    cy.contains('.panel table[aria-label="Inscritos del torneo"] td', nombre).should('exist');
  });
});

/** Genera el fixture / bracket / primera ronda (el diálogo de confirmación lo acepta Cypress solo). */
Cypress.Commands.add('generarCompetencia', (torneoId) => {
  cy.visit(`/admin/torneos/${torneoId}`);
  cy.contains('button', 'Generar competencia').click();
  cy.get('.flash-msg').should('exist');
  // Una liga o un suizo muestran una tabla de partidos; un bracket, tarjetas.
  cy.get('button[data-modal-cargar], .bk-card').should('have.length.at.least', 1);
});

/** Los partidos que esperan resultado en la página: filas con su botón, o tarjetas del bracket que abren un menú. */
const PENDIENTES = 'button[data-modal-cargar]:visible, .bk-card.clickable[aria-haspopup]:visible';

/**
 * Carga resultado en todos los partidos pendientes, uno por uno, con este marcador (gana el de arriba / local).
 * Cada carga recarga la página: se marca la ventana y se espera a que la marca desaparezca (página nueva). Así sirve igual cuando,
 * al avanzar un ganador, aparece un partido nuevo y la cantidad de pendientes no baja.
 */
Cypress.Commands.add('cargarTodosLosResultados', (marcador = [2, 1]) => {
  const paso = () => {
    cy.get('body').then(($b) => {
      if ($b.find(PENDIENTES).length === 0) return;
      if ($b.find('button[data-modal-cargar]:visible').length) {
        cy.get('button[data-modal-cargar]:visible').first().click();
      } else {
        cy.get('.bk-card.clickable[aria-haspopup]:visible').first().click();           // abre el menú de la tarjeta
        cy.get('.bk-action-menu:visible [data-action="cargar"]').first().click();
      }
      cy.get('#modalCargar').should('have.attr', 'aria-hidden', 'false');
      cy.get('#modalCargar input[name="puntos_a"]').clear().type(String(marcador[0]));
      cy.get('#modalCargar input[name="puntos_b"]').clear().type(String(marcador[1]));
      cy.window().then((w) => { w.__antesDeCargar = true; });
      cy.get('#modalCargar form button[type="submit"]').click();
      cy.window().should((w) => { expect(w.__antesDeCargar, 'la página se recargó').to.be.undefined; });
      paso();
    });
  };
  paso();
});
