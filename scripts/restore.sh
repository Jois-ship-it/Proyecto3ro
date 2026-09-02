#!/bin/bash
# ============================================================
# restore.sh — Restaurar respaldo de la base de datos FlexArena
# Uso: ./scripts/restore.sh <archivo.sql.gz>
# ============================================================

set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Uso: $0 <archivo_respaldo.sql.gz>"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: El archivo '$BACKUP_FILE' no existe."
    exit 1
fi

if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-flexarena}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_ROOT_PASS:-FlexArena-Root-2026}"

echo "[$(date)] ADVERTENCIA: Se restaurará $DB_NAME desde $BACKUP_FILE"
read -rp "¿Confirmar? (s/N): " confirm
[[ "$confirm" != "s" && "$confirm" != "S" ]] && { echo "Restauración cancelada."; exit 0; }

echo "[$(date)] Iniciando restauración..."

if [[ "$BACKUP_FILE" == *.gz ]]; then
    gunzip -c "$BACKUP_FILE" | mysql \
      --host="$DB_HOST" --port="$DB_PORT" \
      --user="$DB_USER" --password="$DB_PASS" \
      "$DB_NAME"
else
    mysql \
      --host="$DB_HOST" --port="$DB_PORT" \
      --user="$DB_USER" --password="$DB_PASS" \
      "$DB_NAME" < "$BACKUP_FILE"
fi

echo "[$(date)] Restauración completada exitosamente."
