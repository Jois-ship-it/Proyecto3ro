#!/bin/bash
# ============================================================
# backup.sh — Respaldo de la base de datos FlexArena
# Uso: ./scripts/backup.sh
# ============================================================

set -euo pipefail

# Cargar variables de entorno si existe .env
if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-flexarena}"
DB_USER="${DB_USER:-flexarena_user}"
DB_PASS="${DB_PASS:-FlexArena-2026_Db}"

BACKUP_DIR="$(dirname "$0")/../backups"
mkdir -p "$BACKUP_DIR"

FILENAME="${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

echo "[$(date)] Iniciando respaldo de ${DB_NAME}..."

mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USER" \
  --password="$DB_PASS" \
  --single-transaction \
  --routines \
  --triggers \
  "$DB_NAME" | gzip > "$FILEPATH"

echo "[$(date)] Respaldo completado: $FILEPATH"
echo "Tamaño: $(du -sh "$FILEPATH" | cut -f1)"
