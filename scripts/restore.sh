#!/bin/bash
# ============================================================
# restore.sh — Restaurar respaldo de la base de datos (contenedor 'db')
# Uso: ./scripts/restore.sh <archivo.sql.gz>
#
# Corre mysql DENTRO del contenedor 'db' vía 'docker compose exec' (el
# host no tiene acceso directo a la base — ver backup.sh/docker-compose.yml).
# Usa el usuario root, con la clave que el propio contenedor ya tiene
# (MYSQL_ROOT_PASSWORD): no hace falta definirla en el host, igual que en
# backup.sh. Restaurar un dump completo implica CREATE/DROP TABLE y recrear
# vistas y triggers, y el usuario de la app (DB_USER) solo tiene
# SELECT/INSERT/UPDATE/DELETE — no alcanza para esto.
#
# El archivo se verifica ANTES de pedir confirmación y antes de tocar la base:
# restaurar desde un respaldo vacío o truncado deja la base peor que como
# estaba. Mismo criterio que usa backup.sh (ver lib_dump.sh).
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$SCRIPT_DIR/lib_dump.sh"

if [ -z "${1:-}" ]; then
    echo "Uso: $0 <archivo_respaldo.sql.gz>"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: El archivo '$BACKUP_FILE' no existe." >&2
    exit 1
fi

# ── Verificación del respaldo ───────────────────────────────────────────────
# Va primero: es la comprobación más barata y la que más seguido falla.
echo "[$(date)] Verificando $BACKUP_FILE..."
if ! validar_dump "$BACKUP_FILE"; then
    echo "[$(date)] RESTAURACIÓN CANCELADA: el archivo no parece un respaldo completo de FlexArena." >&2
    echo "       No se tocó la base de datos." >&2
    exit 1
fi

# ── Configuración ───────────────────────────────────────────────────────────
if [ -f "$PROJECT_ROOT/.env" ]; then
    set -a
    source "$PROJECT_ROOT/.env"
    set +a
fi

DB_NAME="${DB_NAME:-flexarena}"

echo "[$(date)] ADVERTENCIA: Se restaurará $DB_NAME desde $BACKUP_FILE (contenedor db)"
read -rp "¿Confirmar? (s/N): " confirm
[[ "$confirm" != "s" && "$confirm" != "S" ]] && { echo "Restauración cancelada."; exit 0; }

echo "[$(date)] Iniciando restauración..."

cd "$PROJECT_ROOT"
# mysql como root DENTRO del contenedor, con su propia clave. El nombre de la base
# viaja como argumento posicional ($1 dentro del sh) y no pegado al texto del
# comando: mismo criterio que backup.sh.
mysql_en_db() {
    docker compose exec -T db sh -c '
      exec mysql \
        --default-character-set=utf8mb4 \
        --user=root \
        --password="$MYSQL_ROOT_PASSWORD" \
        "$1"' sh "$DB_NAME"
}
if [[ "$BACKUP_FILE" == *.gz ]]; then
    gunzip -c "$BACKUP_FILE" | mysql_en_db
else
    mysql_en_db < "$BACKUP_FILE"
fi

echo "[$(date)] Restauración completada exitosamente."
