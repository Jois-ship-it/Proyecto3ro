#!/bin/bash
# ============================================================
# server_management.sh — Gestión del servidor FlexArena
# ============================================================

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

usage() {
  echo "Uso: $0 {start|stop|restart|status|logs|backup|shell-app|shell-db}"
  exit 1
}

case "${1:-}" in
  start)
    echo "Levantando contenedores FlexArena..."
    cd "$SCRIPT_DIR" && docker compose up -d
    HTTP_ADDR="$(docker compose port app 80 2>/dev/null | sed 's/^0\.0\.0\.0:/localhost:/')"
    HTTPS_ADDR="$(docker compose port app 443 2>/dev/null | sed 's/^0\.0\.0\.0:/localhost:/')"
    PMA_ADDR="$(docker compose port pma 80 2>/dev/null | sed 's/^0\.0\.0\.0:/localhost:/')"
    echo "Aplicación (HTTP, redirige):  http://${HTTP_ADDR}"
    echo "Aplicación (HTTPS):           https://${HTTPS_ADDR}"
    echo "phpMyAdmin:                   http://${PMA_ADDR}"
    ;;
  stop)
    echo "Deteniendo contenedores..."
    cd "$SCRIPT_DIR" && docker compose down
    ;;
  restart)
    echo "Reiniciando contenedores..."
    cd "$SCRIPT_DIR" && docker compose restart
    ;;
  status)
    cd "$SCRIPT_DIR" && docker compose ps
    ;;
  logs)
    cd "$SCRIPT_DIR" && docker compose logs -f --tail=50
    ;;
  backup)
    bash "$SCRIPT_DIR/scripts/backup.sh"
    ;;
  shell-app)
    docker exec -it flexarena_app /bin/bash
    ;;
  shell-db)
    docker exec -it flexarena_db mysql -u flexarena_user -p flexarena
    ;;
  *)
    usage
    ;;
esac
