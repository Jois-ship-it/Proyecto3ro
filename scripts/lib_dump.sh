#!/bin/bash
# ============================================================
# lib_dump.sh — Verificación de respaldos de la base.
#
# No se ejecuta solo: lo cargan backup.sh (antes de dar por bueno un respaldo
# recién generado) y restore.sh (antes de restaurar uno existente), de modo que
# los dos apliquen exactamente el mismo criterio.
#
# El problema que resuelve: con "set -euo pipefail", el pipeline
#     docker compose exec ... mysqldump ... | gzip > archivo.gz
# aborta el script si mysqldump falla, pero el archivo YA fue creado por la
# redirección, y gzip de una entrada vacía produce un .gz perfectamente válido
# de 20 bytes. Sin verificar el contenido, un respaldo fallido queda en
# backups/ con pinta de respaldo bueno hasta el día que hay que restaurarlo.
# ============================================================

# Piso de tamaño. Un .gz de entrada vacía pesa 20 bytes; un dump real de este
# proyecto, aun con la base recién creada, pesa varios kB comprimido.
DUMP_MIN_BYTES="${DUMP_MIN_BYTES:-1024}"

# Tablas que tiene que traer sí o sí cualquier respaldo de FlexArena. Sirve
# para detectar un dump que "funcionó" pero contra la base equivocada.
DUMP_TABLAS_ESPERADAS=(usuarios torneos enfrentamientos resultados)

# ¿El archivo está comprimido con gzip? Se mira el contenido (los dos primeros
# bytes de un gzip son 1f 8b) y no la extensión: el respaldo se genera con un
# nombre temporal que no termina en .gz, y fiarse del nombre haría que la
# verificación leyera bytes comprimidos como si fueran texto.
dump_es_gzip() {
    local magia
    magia=$(head -c 2 -- "$1" 2>/dev/null | od -An -tx1 | tr -d '[:space:]')
    [ "$magia" = "1f8b" ]
}

# Vuelca el contenido SQL del archivo, esté comprimido o no.
dump_leer() {
    if dump_es_gzip "$1"; then
        gzip -dc -- "$1"
    else
        cat -- "$1"
    fi
}

# validar_dump <archivo> [etiqueta]
#   0 = parece un respaldo completo de FlexArena
#   1 = no lo parece; el motivo concreto va a stderr
#
# Llamarla siempre dentro de un `if`, para que `set -e` no corte antes de poder
# explicar el problema.
validar_dump() {
    local archivo="$1"
    local etiqueta="${2:-$1}"
    local problemas=0

    if [ ! -f "$archivo" ]; then
        echo "ERROR: $etiqueta no existe." >&2
        return 1
    fi

    # ── 1) Tamaño ───────────────────────────────────────────────────────────
    local bytes
    bytes=$(wc -c < "$archivo" | tr -d '[:space:]')
    if [ "${bytes:-0}" -lt "$DUMP_MIN_BYTES" ]; then
        echo "ERROR: $etiqueta pesa ${bytes} bytes, por debajo del mínimo razonable (${DUMP_MIN_BYTES})." >&2
        echo "       Un .gz de 20 bytes es un gzip válido de entrada vacía: el volcado no produjo nada." >&2
        return 1
    fi

    # ── 2) Integridad del gzip ──────────────────────────────────────────────
    if dump_es_gzip "$archivo" && ! gzip -t -- "$archivo" 2>/dev/null; then
        echo "ERROR: $etiqueta está comprimido pero gzip no lo puede leer (corrupto o truncado)." >&2
        return 1
    fi
    # Un archivo con nombre .gz que no arranca con la firma de gzip tampoco sirve.
    if [[ "$archivo" == *.gz ]] && ! dump_es_gzip "$archivo"; then
        echo "ERROR: $etiqueta se llama .gz pero no es un archivo gzip." >&2
        return 1
    fi

    # ── 3) Contenido, en una sola pasada ────────────────────────────────────
    local resumen tablas inserts cierre
    resumen=$(dump_leer "$archivo" 2>/dev/null | awk '
        /^CREATE TABLE/  { tablas++ }
        /^INSERT INTO/   { inserts++ }
        /Dump completed/ { cierre = 1 }
        END { printf "%d %d %d", tablas + 0, inserts + 0, cierre + 0 }
    ') || resumen="0 0 0"
    read -r tablas inserts cierre <<< "$resumen"

    if [ "${tablas:-0}" -eq 0 ]; then
        echo "ERROR: $etiqueta no tiene ninguna sentencia CREATE TABLE: no es un dump de MySQL." >&2
        problemas=1
    fi

    # ── 4) Tablas propias del proyecto ──────────────────────────────────────
    local creates faltantes=() t
    creates=$(dump_leer "$archivo" 2>/dev/null | grep -E '^CREATE TABLE' || true)
    for t in "${DUMP_TABLAS_ESPERADAS[@]}"; do
        grep -q "\`${t}\`" <<< "$creates" || faltantes+=("$t")
    done
    if [ "${#faltantes[@]}" -gt 0 ]; then
        echo "ERROR: a $etiqueta le faltan tablas de FlexArena: ${faltantes[*]}" >&2
        problemas=1
    fi

    # ── 5) Cierre de mysqldump ──────────────────────────────────────────────
    # mysqldump termina con "-- Dump completed on ...". Si no está, el volcado
    # se cortó a mitad de camino (se cayó la conexión, se llenó el disco, etc.).
    if [ "${cierre:-0}" -eq 0 ]; then
        echo "ERROR: $etiqueta no trae la línea final de mysqldump (\"Dump completed\"):" >&2
        echo "       el volcado quedó incompleto." >&2
        problemas=1
    fi

    [ "$problemas" -eq 0 ] || return 1

    echo "  Verificado: ${bytes} bytes, ${tablas} tabla(s), ${inserts} INSERT(s), cierre de mysqldump presente."
    return 0
}
