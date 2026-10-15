// Cypress de FlexArena: pruebas de punta a punta contra el entorno AISLADO (docker-compose.e2e.yml, `bash scripts/e2e.sh`).
//
// Las contraseñas (ADMIN_PASS, DEMO_PASS) salen del .env del proyecto y NO se ponen en `env` de Cypress (ahí quedan a la vista en la
// configuración que muestra la interfaz): llegan a las pruebas por una tarea de Node (cy.task('credencial')) y se escriben con
// {log: false}, así no aparecen en el registro de comandos ni en las capturas.
const { defineConfig } = require('cypress');
const fs = require('fs');
const path = require('path');

/** Lee el .env del proyecto (el mismo que usa Docker). Si una variable ya está en el entorno, esa gana. */
function leerEnv() {
  const valores = {};
  const archivo = path.join(__dirname, '..', '..', '.env');
  if (fs.existsSync(archivo)) {
    for (const linea of fs.readFileSync(archivo, 'utf8').split(/\r?\n/)) {
      const t = linea.trim();
      if (!t || t.startsWith('#') || !t.includes('=')) continue;
      const i = t.indexOf('=');
      valores[t.slice(0, i).trim()] = t.slice(i + 1).trim().replace(/^["']|["']$/g, '');
    }
  }
  return valores;
}

// Las cuentas del seed (database/seed.sql): el administrador usa ADMIN_PASS; las demás, DEMO_PASS.
const CUENTAS = {
  admin:        { email: 'admin@flexarena.com',  clave: 'ADMIN_PASS' },
  organizador:  { email: 'org1@flexarena.com',   clave: 'DEMO_PASS' },
  organizadora: { email: 'org2@flexarena.com',   clave: 'DEMO_PASS' },
  matias:       { email: 'matias@example.com',   clave: 'DEMO_PASS' },
  vale:         { email: 'vale@example.com',     clave: 'DEMO_PASS' },
};

module.exports = defineConfig({
  e2e: {
    baseUrl: process.env.E2E_URL || 'https://localhost:8643',
    specPattern: 'cypress/e2e/**/*.cy.js',
    supportFile: 'cypress/support/e2e.js',
    video: false,
    screenshotOnRunFailure: true,
    viewportWidth: 1280,
    viewportHeight: 800,
    // En una máquina cargada (poco CPU, otras aplicaciones abiertas) las pantallas tardan más en llegar al navegador de Cypress: se pueden
    // ensanchar los tiempos sin tocar los casos (E2E_TIEMPO_COMANDO y E2E_TIEMPO_PAGINA, en milisegundos).
    defaultCommandTimeout: Number(process.env.E2E_TIEMPO_COMANDO) || 8000,
    pageLoadTimeout: Number(process.env.E2E_TIEMPO_PAGINA) || 30000,
    // Por defecto Cypress ELIMINA la cabecera Content-Security-Policy de cada respuesta (para poder inyectarse en la página): con eso un
    // `<script>` en línea correría igual y las pruebas jamás verían una violación de la política de seguridad de la aplicación. Se le pide
    // que conserve las directivas que importan, así lo que la política bloquearía en un navegador real también se bloquea acá.
    experimentalCspAllowList: ['script-src-elem', 'script-src', 'default-src', 'form-action', 'child-src', 'frame-src'],
    // Sin reintentos: los recorridos tienen estado (crean torneos, aprueban solicitudes) y repetirlos a mitad de camino solo confunde.
    retries: { runMode: 0, openMode: 0 },
    // La sesión de FlexArena cambia de identificador cada tanto: cada spec inicia la suya (cy.session no cruza especificaciones).
    testIsolation: true,
    setupNodeEvents(on) {
      on('task', {
        /** {email, clave} de una cuenta del seed. La clave sale del .env (o del entorno) y no se imprime. */
        credencial(rol) {
          const c = CUENTAS[rol];
          if (!c) throw new Error(`No hay una cuenta «${rol}» en cypress.config.js`);
          const env = { ...leerEnv(), ...process.env };
          const clave = env[c.clave];
          if (!clave) throw new Error(`Falta ${c.clave} en el .env: sin ella no se puede iniciar sesión como «${rol}».`);
          return { email: c.email, clave };
        },
      });
    },
  },
});
