#!/usr/bin/env bash
# gestor-servicios.sh
# Script simple para gestionar servicios systemd en AlmaLinux 9.
# Uso interactivo:   sudo ./gestor-servicios.sh
# Uso directo:       sudo ./gestor-servicios.sh <accion> <servicio>
# Acciones válidas:  status | start | stop | restart | enable | disable | logs

set -e
# set -e: si un comando falla, el script se detiene en lugar de seguir ejecutando con errores.

SERVICIOS_COMUNES=("httpd" "mariadb" "firewalld" "sshd")
# Lista de servicios que se muestran como opciones rápidas en el menú.

verificar_root() {
    # Función que confirma que el script se ejecuta con privilegios de administrador.
    if [ "$EUID" -ne 0 ]; then
        # $EUID es el ID del usuario que ejecuta el script; 0 corresponde a root.
        echo "Error: este script debe ejecutarse como root o con sudo."
        exit 1
        # Se corta la ejecución si no hay privilegios suficientes.
    fi
}

servicio_existe() {
    # Función que verifica si el servicio recibido como argumento existe en el sistema.
    local servicio="$1"
    # Guarda el primer argumento recibido en una variable local llamada "servicio".
    systemctl list-unit-files --type=service | grep -q "^${servicio}.service"
    # Busca el nombre del servicio dentro de la lista de unidades systemd instaladas.
    # grep -q no imprime nada, solo devuelve verdadero o falso.
}

mostrar_estado() {
    # Función que imprime en una sola línea si el servicio está activo y si arranca con el sistema.
    local servicio="$1"
    # Nombre del servicio a consultar.

    if ! servicio_existe "$servicio"; then
        # Si la función servicio_existe devuelve falso, se informa y se sale.
        echo "$servicio: unidad no encontrada"
        return 1
    fi

    local activo
    activo=$(systemctl is-active "$servicio" 2>/dev/null || true)
    # Guarda el estado actual del servicio (active, inactive, failed, etc).
    # "|| true" evita que el script corte si el comando devuelve un error controlado.

    local habilitado
    habilitado=$(systemctl is-enabled "$servicio" 2>/dev/null || true)
    # Guarda si el servicio está configurado para iniciar automáticamente al arrancar.

    echo "$servicio: estado=$activo | arranque=$habilitado"
    # Imprime ambos datos en formato simple y legible.
}

resumen_servicios() {
    # Función que recorre la lista de servicios comunes y muestra el estado de cada uno.
    echo "=== Resumen de servicios ==="
    for s in "${SERVICIOS_COMUNES[@]}"; do
        # Itera sobre cada elemento del arreglo SERVICIOS_COMUNES.
        mostrar_estado "$s"
        # Llama a la función que imprime el estado de ese servicio puntual.
    done
}

ejecutar_accion() {
    # Función central que ejecuta la acción solicitada sobre un servicio.
    local accion="$1"
    # Primer argumento: la acción a realizar (start, stop, restart, etc).
    local servicio="$2"
    # Segundo argumento: el nombre del servicio sobre el que se actúa.

    if ! servicio_existe "$servicio"; then
        # Verifica que el servicio exista antes de intentar cualquier operación.
        echo "Error: el servicio '$servicio' no existe en este sistema."
        return 1
    fi

    case "$accion" in
        # Evalúa el valor de "accion" y ejecuta el bloque correspondiente.
        start)
            echo "Iniciando $servicio..."
            systemctl start "$servicio"
            # Comando systemctl que inicia el servicio.
            echo "Listo."
            ;;
        stop)
            echo "Deteniendo $servicio..."
            systemctl stop "$servicio"
            # Comando systemctl que detiene el servicio.
            echo "Listo."
            ;;
        restart)
            echo "Reiniciando $servicio..."
            systemctl restart "$servicio"
            # Comando systemctl que detiene e inicia nuevamente el servicio.
            echo "Listo."
            ;;
        enable)
            echo "Habilitando $servicio en el arranque..."
            systemctl enable "$servicio"
            # Configura el servicio para que inicie automáticamente al encender el equipo.
            echo "Listo."
            ;;
        disable)
            echo "Deshabilitando $servicio del arranque..."
            systemctl disable "$servicio"
            # Quita al servicio del inicio automático del sistema.
            echo "Listo."
            ;;
        status)
            systemctl status "$servicio" --no-pager
            # Muestra el estado detallado del servicio tal como lo entrega systemd.
            ;;
        logs)
            journalctl -u "$servicio" -n 50 --no-pager
            # Muestra las últimas 50 líneas del registro (log) del servicio.
            ;;
        *)
            # Caso por defecto: la acción ingresada no es válida.
            echo "Acción desconocida: $accion"
            return 1
            ;;
    esac
}

pedir_servicio() {
    # Función que le muestra al usuario la lista de servicios y guarda su elección.
    echo ""
    echo "Servicios disponibles:"
    local i=1
    # Contador usado para numerar las opciones del menú.
    for s in "${SERVICIOS_COMUNES[@]}"; do
        echo "  $i) $s"
        i=$((i + 1))
        # Incrementa el contador en cada vuelta del ciclo.
    done
    echo "  0) Escribir el nombre de otro servicio"
    read -rp "Seleccione un servicio: " seleccion
    # Lee la opción elegida por el usuario y la guarda en "seleccion".

    if [ "$seleccion" = "0" ]; then
        # Si el usuario eligió "0", se le pide que escriba el nombre manualmente.
        read -rp "Nombre del servicio (sin .service): " SERVICIO_SEL
    elif [ "$seleccion" -ge 1 ] 2>/dev/null && [ "$seleccion" -le "${#SERVICIOS_COMUNES[@]}" ]; then
        # Verifica que el número ingresado esté dentro del rango de la lista.
        SERVICIO_SEL="${SERVICIOS_COMUNES[$((seleccion - 1))]}"
        # Convierte el número elegido en el nombre del servicio correspondiente.
    else
        echo "Opción inválida."
        SERVICIO_SEL=""
        # Se deja vacío para que el menú principal no intente operar sobre un valor incorrecto.
    fi
}

menu_principal() {
    # Función que muestra el menú principal y queda a la espera de órdenes del usuario.
    while true; do
        # Bucle infinito que se repite hasta que el usuario elija salir.
        echo ""
        echo "===================================="
        echo " Gestor de Servicios - AlmaLinux 9"
        echo "===================================="
        echo " 1) Ver resumen de servicios comunes"
        echo " 2) Ver estado detallado de un servicio"
        echo " 3) Iniciar un servicio"
        echo " 4) Detener un servicio"
        echo " 5) Reiniciar un servicio"
        echo " 6) Habilitar en el arranque"
        echo " 7) Deshabilitar del arranque"
        echo " 8) Ver logs recientes"
        echo " 0) Salir"
        echo ""
        read -rp "Elija una opción: " opcion
        # Lee la opción numérica ingresada por el usuario.

        case "$opcion" in
            1) resumen_servicios ;;
            # Llama directamente al resumen, no requiere elegir un servicio puntual.
            2) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion status "$SERVICIO_SEL" ;;
            3) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion start "$SERVICIO_SEL" ;;
            4) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion stop "$SERVICIO_SEL" ;;
            5) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion restart "$SERVICIO_SEL" ;;
            6) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion enable "$SERVICIO_SEL" ;;
            7) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion disable "$SERVICIO_SEL" ;;
            8) pedir_servicio; [ -n "$SERVICIO_SEL" ] && ejecutar_accion logs "$SERVICIO_SEL" ;;
            # Cada opción pide primero el servicio y luego ejecuta la acción correspondiente.
            0)
                echo "Saliendo."
                exit 0
                # Termina el script de forma normal.
                ;;
            *)
                echo "Opción inválida."
                ;;
        esac
    done
}

main() {
    # Función principal que decide si se usa el menú o el modo por argumentos.
    verificar_root
    # Se valida primero que el usuario tenga privilegios de administrador.

    if [ $# -eq 0 ]; then
        # Si no se pasaron argumentos al ejecutar el script, se abre el menú interactivo.
        menu_principal
    elif [ $# -eq 2 ]; then
        # Si se pasaron exactamente dos argumentos, se ejecuta la acción directamente.
        ejecutar_accion "$1" "$2"
    else
        # Cualquier otra cantidad de argumentos se considera un uso incorrecto.
        echo "Uso:"
        echo "  $0                      (modo interactivo)"
        echo "  $0 <accion> <servicio>  (modo directo)"
        echo "Acciones: status|start|stop|restart|enable|disable|logs"
        exit 1
    fi
}

main "$@"
# Llama a la función principal pasando todos los argumentos recibidos por el script.