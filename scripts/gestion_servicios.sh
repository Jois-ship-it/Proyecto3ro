#!/bin/bash
# ============================================================
# gestion_servicios.sh — Gestión de servicios systemd (AlmaLinux)
#
# Uso:
#   ./scripts/gestion_servicios.sh start|stop|restart|reload|enable|disable <servicio>
#   ./scripts/gestion_servicios.sh status [servicio]     (sin servicio: resumen de conocidos)
#   ./scripts/gestion_servicios.sh logs <servicio> [-f]
#   ./scripts/gestion_servicios.sh list
#
# Sin restricción de servicios: <servicio> puede ser cualquier unidad systemd
# del servidor (docker, firewalld, crond, sshd, httpd, mariadb, lo que sea).
#
# SERVICIOS_CONOCIDOS de abajo NO es una whitelist que bloquea nada — es
# solo la lista que se usa para el resumen de "list"/"status" sin argumento.
# Son los que de verdad corren sobre el host en este despliegue (Apache/PHP
# y MySQL corren DENTRO de los contenedores Docker y se gestionan con
# scripts/server_management.sh, no con systemctl directo sobre el host).
# ============================================================
set -euo pipefail

SERVICIOS_CONOCIDOS=(docker firewalld crond sshd)

usage() {
  echo "Uso: $0 {start|stop|restart|reload|enable|disable} <servicio>"
  echo "     $0 status [servicio]        (sin servicio: resumen de conocidos)"
  echo "     $0 logs <servicio> [-f]"
  echo "     $0 list"
  echo
  echo "<servicio> puede ser cualquier unidad systemd (no hay whitelist)."
  echo "Conocidos para el resumen: ${SERVICIOS_CONOCIDOS[*]}"
  exit 1
}

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: esta acción requiere privilegios de root -> sudo $0 $*" >&2
    exit 1
  fi
}

resumen() {
  printf "%-10s %-14s %-12s\n" "SERVICIO" "ACTIVO" "HABILITADO"
  for s in "${SERVICIOS_CONOCIDOS[@]}"; do
    if systemctl list-unit-files "${s}.service" >/dev/null 2>&1; then
      local activo habilitado
      activo="$(systemctl is-active "$s" 2>/dev/null || true)"
      habilitado="$(systemctl is-enabled "$s" 2>/dev/null || true)"
      printf "%-10s %-14s %-12s\n" "$s" "${activo:-desconocido}" "${habilitado:-desconocido}"
    else
      printf "%-10s %-14s %-12s\n" "$s" "no instalado" "-"
    fi
  done
}

ACCION="${1:-}"
[ -n "$ACCION" ] || usage

case "$ACCION" in

  list)
    resumen
    ;;

  status)
    SERVICIO="${2:-}"
    if [ -z "$SERVICIO" ]; then
      resumen
    else
      systemctl --no-pager status "$SERVICIO"
    fi
    ;;

  logs)
    SERVICIO="${2:-}"
    [ -n "$SERVICIO" ] || usage
    if [ "${3:-}" = "-f" ]; then
      journalctl -u "$SERVICIO" -f
    else
      journalctl -u "$SERVICIO" -n 50 --no-pager
    fi
    ;;

  start|stop|restart|reload|enable|disable)
    SERVICIO="${2:-}"
    [ -n "$SERVICIO" ] || usage
    require_root "$ACCION" "$SERVICIO"
    echo "==> systemctl $ACCION $SERVICIO"
    systemctl "$ACCION" "$SERVICIO"
    systemctl --no-pager --lines=5 status "$SERVICIO" || true
    ;;

  *)
    usage
    ;;
esac
