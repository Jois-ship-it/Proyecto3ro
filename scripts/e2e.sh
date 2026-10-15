#!/usr/bin/env bash
# ============================================================
# Pruebas de punta a punta (Cypress) sobre un entorno AISLADO.
#
#   bash scripts/e2e.sh subir          levanta el entorno (docker-compose.e2e.yml): otra base, otros volúmenes, otros puertos
#   bash scripts/e2e.sh probar [args]  corre Cypress contra él (args se le pasan: --spec cypress/e2e/01_publico.cy.js)
#   bash scripts/e2e.sh abrir          abre la interfaz de Cypress
#   bash scripts/e2e.sh limpiar-intentos   vacía los contadores de ingresos fallidos (probar lo hace solo)
#   bash scripts/e2e.sh estado         qué está corriendo
#   bash scripts/e2e.sh bajar          lo apaga y BORRA su base (la próxima vez nace de cero)
#
# No toca la instalación normal (flexarena_*): ver docker-compose.e2e.yml. Cypress se instala con
#   bash scripts/instalar_herramientas_de_prueba.sh cypress
# Variables opcionales: E2E_HTTPS_PORT (8643), E2E_HTTP_PORT (8280), E2E_HOST (localhost).
#   E2E_HOST=127.0.0.1 sirve cuando «localhost» tarda (el sistema prueba primero ::1, donde Docker no escucha, y cada conexión nueva
#   espera ~250 ms o más antes de caer a IPv4: con las decenas de pedidos de cada pantalla, Cypress se pasa de sus tiempos).
#   E2E_TIEMPO_COMANDO (8000 ms) y E2E_TIEMPO_PAGINA (30000 ms) ensanchan lo que Cypress espera a un comando y a la carga de una
#   pantalla: en una máquina cargada (poco CPU, otras aplicaciones abiertas) un caso puede fallar por lentitud y pasar al repetirlo.
# ============================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"
PUERTO="${E2E_HTTPS_PORT:-8643}"
URL="https://${E2E_HOST:-localhost}:${PUERTO}"
compose() { docker compose -f docker-compose.e2e.yml "$@"; }

# Los intentos de ingreso fallidos se cuentan por dirección IP (20 en una ventana): varias corridas seguidas, con los casos de
# «clave equivocada» y de bloqueo, lo agotan y la aplicación empieza a rechazar hasta los ingresos correctos. Es una base descartable:
# antes de probar se vacía esa tabla. (La contraseña de root se lee DENTRO del contenedor: no pasa por acá.)
limpiar_intentos() {
    compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "DELETE FROM intentos_login" 2>/dev/null'
}

esperar_app() {
    echo "==> Esperando a que la aplicación responda en ${URL} (la primera vez crea la base: puede tardar unos minutos)"
    for _ in $(seq 1 120); do
        codigo="$(curl -ks -o /dev/null -w '%{http_code}' "${URL}/login" || true)"
        if [ "$codigo" = "200" ]; then echo "    lista."; return 0; fi
        sleep 5
    done
    echo "La aplicación no respondió a tiempo. Mirá: docker compose -f docker-compose.e2e.yml logs app" >&2
    return 1
}

case "${1:-}" in
    subir)
        compose up -d --build
        esperar_app
        ;;
    probar)
        shift
        [ -d tests/e2e/node_modules/cypress ] || { echo "Falta Cypress: bash scripts/instalar_herramientas_de_prueba.sh cypress" >&2; exit 2; }
        esperar_app
        limpiar_intentos
        # Estas opciones: el Electron de Cypress se cae al arrancar en algunos Windows (código 0x80000003) si usa la GPU o su sandbox.
        ( cd tests/e2e && ELECTRON_EXTRA_LAUNCH_ARGS="${ELECTRON_EXTRA_LAUNCH_ARGS:---disable-gpu --disable-software-rasterizer --no-sandbox}" E2E_URL="$URL" npx cypress run "$@" )
        ;;
    abrir)
        esperar_app
        ( cd tests/e2e && ELECTRON_EXTRA_LAUNCH_ARGS="${ELECTRON_EXTRA_LAUNCH_ARGS:---disable-gpu --disable-software-rasterizer --no-sandbox}" E2E_URL="$URL" npx cypress open )
        ;;
    limpiar-intentos) limpiar_intentos ;;
    estado) compose ps ;;
    bajar)  compose down -v ;;
    *) echo "Uso: $0 {subir|probar [args]|abrir|limpiar-intentos|estado|bajar}" >&2; exit 2 ;;
esac
