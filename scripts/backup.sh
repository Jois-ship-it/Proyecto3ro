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

BACKUP_DIR="$PROJECT_ROOT/backups"
mkdir -p "$BACKUP_DIR"

FILENAME="${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

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
  "$DB_NAME" | gzip > "$FILEPATH"

echo "[$(date)] Respaldo completado: $FILEPATH"
echo "Tamaño: $(du -sh "$FILEPATH" | cut -f1)"
