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
    echo "Aplicación disponible en http://localhost:8080"
    echo "phpMyAdmin disponible en http://localhost:8081"
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
