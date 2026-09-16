#!/bin/bash
# ============================================================
# backup_restore_test.sh — Verificación de scripts/backup.sh y scripts/restore.sh
#
# No toca Docker ni la base real: arma un proyecto de prueba en un directorio
# temporal y pone un `docker` falso primero en el PATH, que según la variable
# FAKE_DOCKER_MODO simula un volcado bueno, uno vacío, uno truncado, uno de otra
# base, o directamente un fallo del comando.
#
# Comprueba las dos cosas que el punto pide:
#   - backup.sh no da por bueno un respaldo que no lo es, y no deja archivos a
#     medio hacer en backups/.
#   - restore.sh valida el archivo ANTES de tocar la base.
#
# Ejecutar:  bash tests/backup_restore_test.sh
# ============================================================
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

PASADAS=0
FALLIDAS=0

ok()    { PASADAS=$((PASADAS + 1)); printf '  OK    %s\n' "$1"; }
falla() { FALLIDAS=$((FALLIDAS + 1)); printf '  FALLA %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; return 0; }

afirmar_igual() { # <esperado> <real> <descripcion>
    if [ "$1" = "$2" ]; then ok "$3"; else falla "$3" "esperado '$1', obtenido '$2'"; fi
}

afirmar_contiene() { # <aguja> <texto> <descripcion>
    if printf '%s' "$2" | grep -qF -- "$1"; then ok "$3"; else falla "$3" "no aparece '$1' en la salida"; fi
}

# ── Proyecto de prueba ──────────────────────────────────────────────────────
PROY="$TMP/proyecto"
mkdir -p "$PROY/scripts" "$TMP/bin"
cp "$RAIZ/scripts/backup.sh" "$RAIZ/scripts/restore.sh" "$RAIZ/scripts/lib_dump.sh" "$PROY/scripts/"
cat > "$PROY/.env" <<'EOF'
DB_NAME=flexarena
DB_USER=flexarena_user
DB_PASS=clave-de-prueba
DB_ROOT_PASS=clave-root-de-prueba
EOF

# ── Volcados de mentira, con forma y tamaño realistas ───────────────────────
# Tienen que superar el mínimo de lib_dump.sh una vez comprimidos: si no, los
# casos de "truncado" y "de otra base" se rechazarían por tamaño y el test no
# estaría probando lo que dice probar.
generar_dump() { # <archivo> <tabla...> — sin la línea de cierre
    local archivo="$1"; shift
    {
        echo "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)"
        echo "--"
        echo "-- Host: localhost    Database: flexarena"
        echo "SET NAMES utf8mb4;"
        local t n
        for t in "$@"; do
            echo "DROP TABLE IF EXISTS \`$t\`;"
            echo "CREATE TABLE \`$t\` ("
            echo "  \`id\` int unsigned NOT NULL AUTO_INCREMENT,"
            echo "  \`nombre\` varchar(150) DEFAULT NULL,"
            echo "  \`descripcion\` text,"
            echo "  PRIMARY KEY (\`id\`)"
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            for n in $(seq 1 40); do
                echo "INSERT INTO \`$t\` VALUES ($n,'Registro $n de $t','Texto de relleno numero $n para que el volcado tenga un tamano parecido al de uno real');"
            done
        done
    } > "$archivo"
}

TABLAS_REALES=(usuarios torneos enfrentamientos resultados rondas participantes
               equipos inscripciones auditoria tabla_posiciones roles modulos)

DUMP_OK="$TMP/dump_ok.sql"
generar_dump "$DUMP_OK" "${TABLAS_REALES[@]}"
echo "-- Dump completed on 2026-09-16 10:00:00" >> "$DUMP_OK"

# Truncado: idéntico pero sin la línea de cierre (el volcado se cortó a mitad).
DUMP_TRUNCADO="$TMP/dump_truncado.sql"
generar_dump "$DUMP_TRUNCADO" "${TABLAS_REALES[@]}"

# De otra base: bien formado y completo, pero sin las tablas de FlexArena.
DUMP_AJENO="$TMP/dump_ajeno.sql"
generar_dump "$DUMP_AJENO" clientes facturas articulos proveedores
echo "-- Dump completed on 2026-09-16 10:00:00" >> "$DUMP_AJENO"

# ── `docker` de mentira ─────────────────────────────────────────────────────
cat > "$TMP/bin/docker" <<'EOF'
#!/bin/bash
# Simula `docker compose exec -T db mysqldump ...` y `... db mysql ...`
if printf '%s ' "$@" | grep -q mysqldump; then
    case "${FAKE_DOCKER_MODO:-ok}" in
        fallar)   echo "docker: simulacion de fallo" >&2; exit 1 ;;
        vacio)    exit 0 ;;
        truncado) cat "$FAKE_DUMP_TRUNCADO" ;;
        ajeno)    cat "$FAKE_DUMP_AJENO" ;;
        *)        cat "$FAKE_DUMP_OK" ;;
    esac
    exit 0
fi
# Rama `mysql`: consume el SQL de la restauracion y lo deja anotado.
cat > "${FAKE_RESTORE_LOG:-/dev/null}"
EOF
chmod +x "$TMP/bin/docker"

export PATH="$TMP/bin:$PATH"
export FAKE_DUMP_OK="$DUMP_OK" FAKE_DUMP_TRUNCADO="$DUMP_TRUNCADO" FAKE_DUMP_AJENO="$DUMP_AJENO"

# Dejan la salida en $SALIDA y el codigo de salida en $CODIGO. No se puede usar
# SALIDA=$(correr_backup ...) porque la sustitucion abre una subshell y el
# $CODIGO asignado adentro no llegaria al proceso de afuera.
correr_backup() { # <modo>
    SALIDA=$(FAKE_DOCKER_MODO="$1" bash "$PROY/scripts/backup.sh" 2>&1)
    CODIGO=$?
}

RESTORE_LOG="$TMP/restaurado.sql"
correr_restore() { # <archivo> <respuesta a la confirmacion>
    : > "$RESTORE_LOG"
    SALIDA=$(printf '%s\n' "$2" | FAKE_RESTORE_LOG="$RESTORE_LOG" bash "$PROY/scripts/restore.sh" "$1" 2>&1)
    CODIGO=$?
}

archivos_en_backups() { find "$PROY/backups" -type f 2>/dev/null | wc -l | tr -d '[:space:]'; }
bytes_restaurados()   { wc -c < "$RESTORE_LOG" | tr -d '[:space:]'; }
limpiar_backups()     { rm -rf -- "$PROY/backups"; }

echo "backup_restore_test.sh"

# ── backup.sh ───────────────────────────────────────────────────────────────

limpiar_backups
correr_backup ok
afirmar_igual "0" "$CODIGO" "backup.sh con un volcado bueno termina con exito"
afirmar_igual "1" "$(archivos_en_backups)" "deja exactamente un archivo en backups/"
afirmar_contiene "Verificado:" "$SALIDA" "informa que verifico del volcado"
afirmar_igual "0" "$(find "$PROY/backups" -name '*.parcial' 2>/dev/null | wc -l | tr -d '[:space:]')" \
    "no queda ningun archivo .parcial"

limpiar_backups
correr_backup fallar
afirmar_igual "1" "$CODIGO" "backup.sh falla si el volcado falla"
afirmar_igual "0" "$(archivos_en_backups)" "y NO deja el .gz vacio en backups/"

limpiar_backups
correr_backup vacio
afirmar_igual "1" "$CODIGO" "backup.sh rechaza un volcado vacio"
afirmar_igual "0" "$(archivos_en_backups)" "tampoco deja archivo"
afirmar_contiene "por debajo del m" "$SALIDA" "explica que el archivo es demasiado chico"

limpiar_backups
correr_backup truncado
afirmar_igual "1" "$CODIGO" "backup.sh rechaza un volcado truncado"
afirmar_igual "0" "$(archivos_en_backups)" "tampoco deja archivo"
afirmar_contiene "Dump completed" "$SALIDA" "explica que falta la linea final de mysqldump"

limpiar_backups
correr_backup ajeno
afirmar_igual "1" "$CODIGO" "backup.sh rechaza un volcado de otra base"
afirmar_contiene "faltan tablas de FlexArena" "$SALIDA" "dice que tablas faltan"
afirmar_igual "0" "$(archivos_en_backups)" "tampoco deja archivo"

# ── restore.sh ──────────────────────────────────────────────────────────────

# 1) Respaldo vacio: el .gz de 20 bytes que dejaba el backup roto.
VACIO="$TMP/vacio.sql.gz"
: | gzip > "$VACIO"
correr_restore "$VACIO" s
afirmar_igual "1" "$CODIGO" "restore.sh rechaza un respaldo vacio"
afirmar_contiene "No se toc" "$SALIDA" "avisa que no toco la base"
afirmar_igual "0" "$(bytes_restaurados)" "no le paso nada a mysql"

# 2) Respaldo truncado (pesa lo suficiente: se rechaza por contenido, no por tamano).
TRUNCADO_GZ="$TMP/truncado.sql.gz"
gzip -c "$DUMP_TRUNCADO" > "$TRUNCADO_GZ"
correr_restore "$TRUNCADO_GZ" s
afirmar_igual "1" "$CODIGO" "restore.sh rechaza un respaldo truncado"
afirmar_contiene "Dump completed" "$SALIDA" "y dice que el motivo es el volcado incompleto"
afirmar_igual "0" "$(bytes_restaurados)" "tampoco le paso nada a mysql"

# 3) Archivo .gz corrupto (bytes al azar con extension .gz).
CORRUPTO="$TMP/corrupto.sql.gz"
head -c 4096 /dev/urandom > "$CORRUPTO"
correr_restore "$CORRUPTO" s
afirmar_igual "1" "$CODIGO" "restore.sh rechaza un .gz corrupto"
afirmar_igual "0" "$(bytes_restaurados)" "sin tocar la base"

# 4) Respaldo de otra base.
AJENO_GZ="$TMP/ajeno.sql.gz"
gzip -c "$DUMP_AJENO" > "$AJENO_GZ"
correr_restore "$AJENO_GZ" s
afirmar_igual "1" "$CODIGO" "restore.sh rechaza un respaldo de otra base"
afirmar_contiene "faltan tablas de FlexArena" "$SALIDA" "y dice cuales faltan"

# 5) Respaldo bueno: pasa la verificacion y llega a restaurar.
BUENO_GZ="$TMP/bueno.sql.gz"
gzip -c "$DUMP_OK" > "$BUENO_GZ"
correr_restore "$BUENO_GZ" s
afirmar_igual "0" "$CODIGO" "restore.sh acepta un respaldo bueno"
afirmar_contiene "completada exitosamente" "$SALIDA" "llega hasta el final"
if [ "$(bytes_restaurados)" -gt 100 ]; then
    ok "le paso el SQL a mysql"
else
    falla "le paso el SQL a mysql" "el log de restauracion quedo vacio"
fi

# 6) Respaldo bueno pero cancelando en la confirmacion.
correr_restore "$BUENO_GZ" n
afirmar_igual "0" "$CODIGO" "restore.sh sale limpio si se cancela la confirmacion"
afirmar_igual "0" "$(bytes_restaurados)" "y no restaura nada"

# ── Resumen ─────────────────────────────────────────────────────────────────
echo
if [ "$FALLIDAS" -gt 0 ]; then
    echo "RESULTADO: FALLO — $FALLIDAS de $((PASADAS + FALLIDAS)) comprobaciones."
    exit 1
fi
echo "RESULTADO: OK — $PASADAS comprobaciones."
exit 0
