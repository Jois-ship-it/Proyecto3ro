#!/bin/bash
# ============================================================
# monitor_db.sh — Monitoreo básico de la BD (contenedor 'db')
#
# Corre las consultas DENTRO del contenedor 'db' vía 'docker compose
# exec' (el host no tiene acceso directo a la base — ver backup.sh).
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
DB_PASS="${DB_PASS:-FlexArena-2026_Db}"

cd "$PROJECT_ROOT"
mysql_cmd() {
    docker compose exec -T db mysql --default-character-set=utf8mb4 \
        --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" -e "$1"
}

echo "========================================"
echo " FlexArena — Monitor de BD"
echo " $(date)"
echo "========================================"

echo ""
echo "--- Conteos de registros ---"
mysql_cmd "SELECT 'Roles'          AS tabla, COUNT(*) AS total FROM roles
UNION ALL SELECT 'Usuarios',        COUNT(*) FROM usuarios
UNION ALL SELECT 'Participantes',   COUNT(*) FROM participantes
UNION ALL SELECT 'Equipos',         COUNT(*) FROM equipos
UNION ALL SELECT 'Torneos',         COUNT(*) FROM torneos
UNION ALL SELECT 'Inscripciones',   COUNT(*) FROM inscripciones
UNION ALL SELECT 'Rondas',          COUNT(*) FROM rondas
UNION ALL SELECT 'Enfrentamientos', COUNT(*) FROM enfrentamientos
UNION ALL SELECT 'Resultados',      COUNT(*) FROM resultados
UNION ALL SELECT 'Auditoría',       COUNT(*) FROM auditoria;"

echo ""
echo "--- Torneos por estado ---"
mysql_cmd "SELECT estado, COUNT(*) AS cantidad FROM torneos GROUP BY estado ORDER BY cantidad DESC;"

echo ""
echo "--- Últimas 5 acciones de auditoría ---"
mysql_cmd "SELECT created_at, accion, descripcion FROM auditoria ORDER BY created_at DESC LIMIT 5;"
