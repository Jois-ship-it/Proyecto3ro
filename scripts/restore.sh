#!/bin/bash
# ============================================================
# restore.sh — Restaurar respaldo de la base de datos (contenedor 'db')
# Uso: ./scripts/restore.sh <archivo.sql.gz>
#
# Corre mysql DENTRO del contenedor 'db' vía 'docker compose exec' (el
# host no tiene acceso directo a la base — ver backup.sh/docker-compose.yml).
# Usa el usuario root: restaurar un dump completo implica CREATE/DROP
# TABLE, y el usuario de la app (DB_USER) solo tiene permisos de
# SELECT/INSERT/UPDATE/DELETE — no alcanza para esto.
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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ -f "$PROJECT_ROOT/.env" ]; then
    set -a
    source "$PROJECT_ROOT/.env"
    set +a
fi

DB_NAME="${DB_NAME:-flexarena}"
DB_USER="root"
DB_PASS="${DB_ROOT_PASS:-FlexArena-Root-2026}"

echo "[$(date)] ADVERTENCIA: Se restaurará $DB_NAME desde $BACKUP_FILE (contenedor db)"
read -rp "¿Confirmar? (s/N): " confirm
[[ "$confirm" != "s" && "$confirm" != "S" ]] && { echo "Restauración cancelada."; exit 0; }

echo "[$(date)] Iniciando restauración..."

cd "$PROJECT_ROOT"
if [[ "$BACKUP_FILE" == *.gz ]]; then
    gunzip -c "$BACKUP_FILE" | docker compose exec -T db mysql \
      --default-character-set=utf8mb4 --user="$DB_USER" --password="$DB_PASS" "$DB_NAME"
else
    docker compose exec -T db mysql \
      --default-character-set=utf8mb4 --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$BACKUP_FILE"
fi

echo "[$(date)] Restauración completada exitosamente."
