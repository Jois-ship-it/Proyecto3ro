#!/bin/bash
# ============================================================
# backup.sh — Respaldo de la base de datos (contenedor 'db')
# Uso: ./scripts/backup.sh
#
# Corre mysqldump DENTRO del contenedor 'db' vía 'docker compose exec':
# el host no tiene acceso directo a la base (el puerto no se publica,
# a propósito, por seguridad — ver docker-compose.yml). Un mysqldump
# apuntando a "db"/localhost desde el host, o con cliente mysql
# instalado en el host, no funciona con esta arquitectura.
#
# Vuelca como root, y la contraseña la toma del propio contenedor
# (MYSQL_ROOT_PASSWORD): no hace falta tenerla en el host. No se usa la
# cuenta de la aplicación porque, por mínimo privilegio, no puede ver las
# vistas (SHOW VIEW) ni los triggers (TRIGGER) de la base, y un respaldo
# sin ellos no restaura el historial ni las vistas. Es la misma razón por
# la que restore.sh también usa root.
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ -f "$PROJECT_ROOT/.env" ]; then
    set -a
    source "$PROJECT_ROOT/.env"
    set +a
fi

DB_NAME="${DB_NAME:-flexarena}"

# Criterio de verificación compartido con restore.sh.
source "$SCRIPT_DIR/lib_dump.sh"

BACKUP_DIR="$PROJECT_ROOT/backups"
mkdir -p "$BACKUP_DIR"

FILENAME="${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"
# Se vuelca a un archivo .parcial y recién se renombra si pasa la verificación:
# así un respaldo fallido nunca queda en backups/ con nombre de respaldo bueno.
PARCIAL="${FILEPATH}.parcial"

limpiar_parcial() { rm -f -- "$PARCIAL"; }
trap limpiar_parcial EXIT

echo "[$(date)] Iniciando respaldo de ${DB_NAME} (contenedor db)..."

cd "$PROJECT_ROOT"
# -T: sin pseudo-TTY. Imprescindible acá — con TTY, docker compose exec
# puede inyectar retornos de carro en la salida y corromper el dump.
# El nombre de la base viaja como argumento posicional ($1 dentro del sh) y no
# pegado al texto del comando, para que un valor raro de DB_NAME no se interprete.
# --single-transaction: foto consistente sin bloquear a la aplicación.
# --triggers (ya es el default, se deja explícito): sin ellos el historial de la
# base (historial_bd) se restauraría vacío de reglas y dejaría de registrar.
# --no-tablespaces: la metadata de tablespaces es del servidor, no de los datos.
docker compose exec -T db sh -c '
  exec mysqldump \
    --default-character-set=utf8mb4 \
    --user=root \
    --password="$MYSQL_ROOT_PASSWORD" \
    --single-transaction \
    --no-tablespaces \
    --routines \
    --triggers \
    "$1"' sh "$DB_NAME" | gzip > "$PARCIAL"

# El pipeline de arriba aborta el script si mysqldump falla (pipefail), pero eso
# no alcanza: gzip de una entrada vacía deja un .gz válido de 20 bytes. Hay que
# mirar lo que quedó adentro antes de dar el respaldo por bueno.
echo "[$(date)] Verificando el volcado..."
if ! validar_dump "$PARCIAL" "el respaldo recién generado"; then
    echo "[$(date)] RESPALDO DESCARTADO: no pasó la verificación. No se dejó ningún archivo en backups/." >&2
    exit 1   # el trap borra el .parcial
fi

mv -- "$PARCIAL" "$FILEPATH"
trap - EXIT

echo "[$(date)] Respaldo completado: $FILEPATH"
echo "Tamaño: $(du -sh "$FILEPATH" | cut -f1)"
