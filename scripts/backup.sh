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
DB_USER="${DB_USER:-flexarena_user}"

# Sin valor por defecto, a propósito: un default con pinta de contraseña real
# termina commiteado en el repositorio y, encima, hace que el script "ande" con
# la credencial equivocada en vez de avisar.
if [ -z "${DB_PASS:-}" ]; then
    echo "ERROR: DB_PASS no está definida." >&2
    echo "       Definila en $PROJECT_ROOT/.env (DB_PASS=...) o exportala antes de correr este script." >&2
    exit 1
fi

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
# --no-tablespaces: sin esto, mysqldump 8 intenta volcar metadata de
# tablespaces que requiere el privilegio PROCESS — el usuario de la app
# no lo tiene (a propósito, mínimo privilegio) y tira un error visible
# aunque el resto del dump igual se complete bien. Se lo saltea directo.
docker compose exec -T db mysqldump \
  --default-character-set=utf8mb4 \
  --user="$DB_USER" \
  --password="$DB_PASS" \
  --single-transaction \
  --no-tablespaces \
  --routines \
  --triggers \
  "$DB_NAME" | gzip > "$PARCIAL"

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
