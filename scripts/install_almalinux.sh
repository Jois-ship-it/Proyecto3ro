#!/usr/bin/env bash
# ============================================================
# install_almalinux.sh — Despliegue Docker en un servidor AlmaLinux
# (AlmaLinux 8/9, con SELinux y firewalld activos)
#
# Instala Docker Engine + Compose plugin (no vienen en los repos
# base de AlmaLinux), prepara .env, y levanta la app con
# `docker compose up -d` sobre la red bridge propia del proyecto
# (ver docker-compose.yml). Deja SOLO los puertos publicados
# (app + phpMyAdmin) abiertos en firewalld — la base de datos NO
# se publica al host, solo es alcanzable dentro de la red interna
# de Docker por los demás contenedores (app, pma).
#
# Pensado para tolerar los problemas más comunes de una instalación
# real: red inestable (reintentos en dnf), la carrera de arranque de
# MySQL en la primera inicialización, disco casi lleno, puertos ya
# ocupados, y el daemon de Docker tardando en responder — cada uno
# se detecta con un mensaje claro en vez de dejar que un comando
# interno falle de forma confusa.
#
# Idempotente: se puede re-ejecutar sin romper una instalación previa
# (docker compose up -d simplemente recrea lo que cambió).
#
# Uso (como root):   sudo bash scripts/install_almalinux.sh
# Opciones:
#   --no-deps       No instalar Docker Engine (asume que ya está instalado)
#   --no-firewall   No tocar firewalld
#   --no-selinux    No aplicar contextos/booleans SELinux
#   --no-up         Preparar todo pero no levantar los contenedores todavía
#
# Nota: para instalación NATIVA (sin Docker: httpd + PHP + MariaDB
# directo sobre el SO) usá scripts/install.sh como referencia y
# adaptalo — este script asume el modelo de despliegue por contenedores.
# ============================================================
set -euo pipefail

# ---- Rutas ----
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_ROOT/.env"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"

# Reintenta un comando hasta 3 veces con una pausa entre intentos — para
# operaciones de red (dnf) en conexiones inestables, donde un fallo
# transitorio puntual no debería tirar abajo toda la instalación.
retry() {
  local n=0 max=3
  until "$@"; do
    n=$((n + 1))
    if [ "$n" -ge "$max" ]; then
      echo "ERROR: '$*' falló $max veces seguidas." >&2
      return 1
    fi
    echo "    (falló, reintentando en 5s: intento $((n + 1))/$max)"
    sleep 5
  done
}

# ---- Flags ----
INSTALL_DEPS=1; CONFIG_FIREWALL=1; CONFIG_SELINUX=1; DO_UP=1
while [ $# -gt 0 ]; do
  case "$1" in
    --no-deps)      INSTALL_DEPS=0 ;;
    --no-firewall)  CONFIG_FIREWALL=0 ;;
    --no-selinux)   CONFIG_SELINUX=0 ;;
    --no-up)        DO_UP=0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 1 ;;
  esac
  shift
done

# ---- Requiere root ----
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: ejecutá como root  ->  sudo bash scripts/install_almalinux.sh" >&2
  exit 1
fi

# ---- Confirmar que es una familia RHEL (AlmaLinux/RHEL/Rocky) ----
if [ ! -f /etc/os-release ] || ! grep -qiE "almalinux|rhel|rocky" /etc/os-release; then
  echo "ADVERTENCIA: /etc/os-release no identifica AlmaLinux/RHEL/Rocky." >&2
  echo "             Este script asume dnf + SELinux + firewalld." >&2
fi
EL_VERSION="$(rpm -E %rhel 2>/dev/null || echo 9)"

[ -f "$COMPOSE_FILE" ] || { echo "ERROR: no existe $COMPOSE_FILE" >&2; exit 1; }

# ---- Espacio en disco disponible ----
# Las imágenes (php, mysql, phpmyadmin) + capas de build + datos de MySQL
# pueden sumar varios GB. Si el disco está muy justo, 'docker compose
# build'/'up' pueden fallar a mitad de camino con un error confuso de
# "no space left on device" — mejor avisar (o frenar si es crítico) antes.
AVAILABLE_GB="$(df -Pk "$PROJECT_ROOT" 2>/dev/null | awk 'NR==2 {print int($4/1024/1024)}')"
if [ -n "${AVAILABLE_GB:-}" ]; then
  if [ "$AVAILABLE_GB" -lt 2 ]; then
    echo "ERROR: solo hay ${AVAILABLE_GB} GB libres — no alcanza ni para las imágenes base." >&2
    echo "       Liberá espacio antes de continuar." >&2
    exit 1
  elif [ "$AVAILABLE_GB" -lt 5 ]; then
    echo "ADVERTENCIA: solo hay ${AVAILABLE_GB} GB libres en disco. Se recomiendan 5+ GB" >&2
    echo "             para las imágenes (php, mysql, phpmyadmin) y los datos de MySQL." >&2
  fi
fi

# ---- .env: si no existe, se genera desde .env.example ----
if [ ! -f "$ENV_FILE" ]; then
  if [ -f "$PROJECT_ROOT/.env.example" ]; then
    cp "$PROJECT_ROOT/.env.example" "$ENV_FILE"
    echo "==> No existía .env: se creó a partir de .env.example."
    echo "    Revisá APP_SECRET / DB_PASS / DB_ROOT_PASS antes de exponer esto en producción."
  else
    echo "ERROR: no existe .env ni .env.example en $PROJECT_ROOT" >&2
    exit 1
  fi
fi
# Contiene contraseñas en texto plano: que solo root pueda leerlo.
chmod 600 "$ENV_FILE"

# Cargar .env (para el resumen final y para avisar si quedaron placeholders)
APP_NAME=""; APP_URL=""; DB_PASS=""; DB_ROOT_PASS=""; APP_SECRET=""
while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  case "$line" in ''|\#*) continue ;; esac
  [ "${line#*=}" != "$line" ] || continue
  key="${line%%=*}"; val="${line#*=}"
  case "$key" in
    APP_NAME)     APP_NAME="$val" ;;
    APP_URL)      APP_URL="$val" ;;
    DB_PASS)      DB_PASS="$val" ;;
    DB_ROOT_PASS) DB_ROOT_PASS="$val" ;;
    APP_SECRET)   APP_SECRET="$val" ;;
  esac
done < "$ENV_FILE"

# Aviso si quedaron los valores de ejemplo de .env.example sin cambiar —
# no bloquea la instalación (puede ser un ambiente de prueba a propósito),
# pero es fácil olvidarse de esto antes de ir a producción de verdad.
PLACEHOLDER_FOUND=0
case "$DB_PASS$DB_ROOT_PASS" in
  *"-2026_Db"*|*"-Root-2026"*|*change_this_db_password*|*change_this_root_password*)
    PLACEHOLDER_FOUND=1 ;;
esac
case "$APP_SECRET" in change_this_secret_key*) PLACEHOLDER_FOUND=1 ;; esac
if [ "$PLACEHOLDER_FOUND" -eq 1 ]; then
  echo "ADVERTENCIA: .env todavía tiene valores de ejemplo (DB_PASS/DB_ROOT_PASS/APP_SECRET)." >&2
  echo "             Cambialos antes de dejar esto expuesto en producción." >&2
fi

echo "==> Proyecto:    ${APP_NAME:-(ver .env)}"
echo "==> Código:      $PROJECT_ROOT"
echo "==> AlmaLinux:   EL$EL_VERSION"
echo

# ---- 1) Docker Engine + Compose plugin ----
if [ "$INSTALL_DEPS" -eq 1 ] && ! command -v docker >/dev/null 2>&1; then
  command -v dnf >/dev/null 2>&1 || {
    echo "ERROR: no se encontró 'dnf'. ¿Es este un sistema AlmaLinux/RHEL?" >&2
    echo "       Si Docker ya está instalado por otro medio, usá --no-deps." >&2
    exit 1
  }
  echo "==> Instalando Docker Engine (repo oficial de Docker para la familia RHEL)..."
  retry dnf install -y dnf-plugins-core
  retry dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
  retry dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
elif [ "$INSTALL_DEPS" -eq 1 ]; then
  echo "==> Docker ya está instalado ($(docker --version))."
  systemctl enable --now docker 2>/dev/null || true
else
  echo "==> (--no-deps) Se omite la instalación de Docker."
fi

command -v docker >/dev/null 2>&1 || { echo "ERROR: docker no está disponible en PATH." >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "ERROR: falta el plugin 'docker compose'." >&2; exit 1; }

echo "==> Esperando a que el daemon de Docker responda..."
DAEMON_READY=0
for i in $(seq 1 15); do
  docker info >/dev/null 2>&1 && { DAEMON_READY=1; break; }
  sleep 2
done
if [ "$DAEMON_READY" -ne 1 ]; then
  echo "ERROR: el daemon de Docker no respondió a tiempo. Revisá 'systemctl status docker'." >&2
  exit 1
fi

# Validar que docker-compose.yml + .env realmente parsean antes de seguir.
# Si hay un error de sintaxis, es mejor un mensaje claro acá que dejar que
# falle más adelante a mitad de un paso (con 'set -e' eso mataría el script
# sin explicación, justo al calcular los puertos publicados).
if ! (cd "$PROJECT_ROOT" && docker compose config >/dev/null 2>&1); then
  echo "ERROR: docker-compose.yml (o .env) tiene un error — 'docker compose config' falló." >&2
  echo "       Salida completa:" >&2
  (cd "$PROJECT_ROOT" && docker compose config) >&2 || true
  exit 1
fi

# ---- 2) SELinux: permitir que los contenedores lean el código montado ----
# AlmaLinux trae SELinux 'enforcing' por defecto. Los bind mounts de
# docker-compose.yml ya usan el flag ':z' para autoetiquetarse en tiempo
# de montaje, así que normalmente no hace falta nada más acá. Este paso
# es un refuerzo por si el runtime de contenedores en uso no honra ':z'.
if [ "$CONFIG_SELINUX" -eq 1 ] && command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != "Disabled" ]; then
  echo "==> SELinux en modo $(getenforce): aplicando contexto container_file_t al proyecto..."
  if command -v semanage >/dev/null 2>&1; then
    semanage fcontext -a -t container_file_t "${PROJECT_ROOT}(/.*)?" 2>/dev/null \
      || semanage fcontext -m -t container_file_t "${PROJECT_ROOT}(/.*)?"
    restorecon -Rv "$PROJECT_ROOT" >/dev/null || true
  else
    echo "    (semanage no disponible; instalá policycoreutils-python-utils si hace falta)"
  fi
else
  echo "==> (--no-selinux o SELinux Disabled) Se omiten contextos SELinux."
fi

# ---- 3) Permisos: que www-data pueda LEER todo el proyecto y ESCRIBIR uploads ----
# El contenedor 'app' corre como www-data, uid/gid 33 en la imagen
# php:8.2-apache (Debian). El bind mount comparte el mismo UID/GID numérico
# entre host y contenedor — pero el chown/chmod que hace el Dockerfile
# ocurre DENTRO de la imagen en build time, y el bind mount lo tapa por
# completo en runtime con lo que tenga el host. Si el código llegó por
# scp/rsync como root (o con un umask restrictivo), "otros" puede no tener
# ni permiso de traversal, y Apache falla con "Server unable to read
# htaccess file, denying access to be safe" al no poder leer
# public/.htaccess (imprescindible: ahí están las reglas de reescritura
# que enrutan todo a index.php).
echo "==> Asegurando que www-data pueda leer el proyecto..."
find "$PROJECT_ROOT" -type d -exec chmod o+rx {} \;
find "$PROJECT_ROOT" -type f -exec chmod o+r {} \;

# La carpeta de subida de archivos además necesita escritura real (logos,
# avatares, fotos de perfil) — "otros" con chmod 775 no alcanza sin ser
# también el dueño, por eso el chown explícito acá.
UPLOADS_DIR="$PROJECT_ROOT/public/assets/uploads"
mkdir -p "$UPLOADS_DIR"
chown -R 33:33 "$UPLOADS_DIR"
chmod -R 775 "$UPLOADS_DIR"

# ---- 4) Puertos: detectarlos una sola vez y chequear que estén libres ----
# Se leen resolviendo el compose con `docker compose config` (no del archivo
# crudo) para no desincronizar la lista a mano y porque los puertos pueden
# venir de variables en .env (ej. HTTP_PORT/PMA_HTTP_PORT) en vez de estar
# hardcodeados. Hoy son el de la app y el de phpMyAdmin — la base de datos
# no tiene "ports:" (no se publica), así que nunca aparece acá.
PUBLISHED_PORTS="$( (cd "$PROJECT_ROOT" && docker compose config 2>/dev/null | grep -oE 'published: "[0-9]+"' | grep -oE '[0-9]+' | sort -u) || true)"

if [ "$DO_UP" -eq 1 ] && [ -n "$PUBLISHED_PORTS" ]; then
  for p in $PUBLISHED_PORTS; do
    if (exec 3<>"/dev/tcp/127.0.0.1/${p}") 2>/dev/null; then
      exec 3>&- 3<&- 2>/dev/null || true
      echo "ADVERTENCIA: el puerto ${p} ya está en uso por otro proceso del servidor." >&2
      echo "             'docker compose up' puede fallar por esto — liberalo o cambiá" >&2
      echo "             HTTP_PORT/HTTPS_PORT/PMA_HTTP_PORT en .env." >&2
    fi
  done
fi

# ---- 5) firewalld: abrir SOLO los puertos que docker-compose.yml publica ----
if [ "$CONFIG_FIREWALL" -eq 1 ] && command -v firewall-cmd >/dev/null 2>&1; then
  systemctl enable --now firewalld >/dev/null 2>&1 || true
  if [ -n "$PUBLISHED_PORTS" ]; then
    echo "==> Abriendo en firewalld los puertos publicados por docker-compose.yml:"
    for p in $PUBLISHED_PORTS; do
      echo "    - ${p}/tcp"
      firewall-cmd --permanent --add-port="${p}/tcp"
    done
    firewall-cmd --reload
  fi
else
  echo "==> (--no-firewall o firewalld no instalado) Se omite la apertura de puertos."
fi

# ---- 6) Construir y levantar los contenedores ----
if [ "$DO_UP" -eq 1 ]; then
  echo "==> Construyendo imágenes..."
  retry bash -c "cd '$PROJECT_ROOT' && docker compose build"

  DB_SERVICE="$(cd "$PROJECT_ROOT" && docker compose config --services | grep -E '^db$' || true)"

  echo "==> Levantando contenedores (app + db + pma) en su red Docker..."
  # En la primerísima inicialización (volumen de MySQL vacío), 'db' puede
  # tardar más de lo que da el healthcheck (start_period + retries), y
  # 'docker compose up -d' aborta con "dependency failed to start: db is
  # unhealthy" ANTES de crear app/pma (con 'set -e' eso mataría el script
  # acá mismo). Si pasa: esperamos a que 'db' termine de inicializar por su
  # cuenta y reintentamos — la segunda vez es casi instantánea porque 'db'
  # ya está healthy.
  if ! (cd "$PROJECT_ROOT" && docker compose up -d); then
    echo "==> Primer intento interrumpido ('db' no llegó a healthy a tiempo — es normal"
    echo "    en la primera inicialización). Esperando a que termine..."
    if [ -n "$DB_SERVICE" ]; then
      for i in $(seq 1 60); do
        STATUS="$(cd "$PROJECT_ROOT" && docker compose ps --format '{{.Health}}' db 2>/dev/null || true)"
        [ "$STATUS" = "healthy" ] && break
        sleep 3
      done
    fi
    echo "==> Reintentando 'docker compose up -d'..."
    (cd "$PROJECT_ROOT" && docker compose up -d)
  fi

  echo
  echo "==> Estado de los contenedores:"
  (cd "$PROJECT_ROOT" && docker compose ps)
else
  echo "==> (--no-up) Todo preparado; no se levantaron los contenedores."
fi

# ---- 7) Verificación de aislamiento de red ----
echo
if [ "$DO_UP" -eq 1 ] && [ -n "${DB_SERVICE:-}" ]; then
  DB_EXPOSED="$(cd "$PROJECT_ROOT" && docker compose port db 3306 2>/dev/null || true)"
  if [ -n "$DB_EXPOSED" ]; then
    echo "ADVERTENCIA: el servicio 'db' tiene un puerto publicado al host ($DB_EXPOSED)."
    echo "             Revisá docker-compose.yml — no debería tener 'ports:' en 'db'."
  else
    echo "OK: la base de datos NO está publicada al host (solo accesible dentro de la red interna de Docker)."
  fi
fi

# Direcciones REALES publicadas (no lo que diga .env, que puede estar
# desactualizado si HTTP_PORT/HTTPS_PORT se cambiaron después) — se usan acá
# y también en el resumen final.
if [ "$DO_UP" -eq 1 ]; then
  HTTP_ADDR="$(cd "$PROJECT_ROOT" && docker compose port app 80 2>/dev/null | sed 's/^0\.0\.0\.0:/127.0.0.1:/' || true)"
  HTTPS_ADDR="$(cd "$PROJECT_ROOT" && docker compose port app 443 2>/dev/null | sed 's/^0\.0\.0\.0:/127.0.0.1:/' || true)"
fi

# ---- 8) Prueba real de humo: confirmar que el sitio responde ----
if [ "$DO_UP" -eq 1 ] && command -v curl >/dev/null 2>&1; then
  echo
  echo "==> Probando que el sitio responda..."

  if [ -n "$HTTP_ADDR" ]; then
    # HTTP (80) es un vhost de puro redirect (ver Dockerfile) — nunca sirve
    # contenido, así que la respuesta esperada es 301, no 200.
    CODE=""
    for i in $(seq 1 20); do
      CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://${HTTP_ADDR}/" 2>/dev/null || true)"
      [ "$CODE" = "301" ] && break
      sleep 3
    done
    if [ "$CODE" = "301" ]; then
      echo "    OK: HTTP redirige (301) a HTTPS en http://${HTTP_ADDR}/"
    else
      echo "    ADVERTENCIA: HTTP no devolvió 301 (último código: '${CODE:-sin respuesta}')." >&2
      echo "                 Revisá 'docker compose logs app' — puede ser un problema real." >&2
    fi
  fi
  if [ -n "$HTTPS_ADDR" ]; then
    CODE="$(curl -sk -o /dev/null -w '%{http_code}' "https://${HTTPS_ADDR}/" 2>/dev/null || true)"
    if [ "$CODE" = "200" ]; then
      echo "    OK: HTTPS responde (200) en https://${HTTPS_ADDR}/"
    else
      echo "    ADVERTENCIA: HTTPS no devolvió 200 (código: '${CODE:-sin respuesta}')." >&2
    fi
  fi
elif [ "$DO_UP" -eq 1 ]; then
  echo "    ('curl' no disponible; se omite la prueba de humo — verificá a mano con un navegador)"
fi

# URL a mostrar: el dominio sale de APP_URL (el nombre "humano" que hay que
# escribir en el navegador), pero el puerto se toma del que Docker publicó
# de verdad — no del que diga .env, que puede haber quedado desactualizado
# si HTTP_PORT/HTTPS_PORT se cambiaron después de crear el .env.
DOMAIN_ONLY="$(printf '%s' "${APP_URL:-http://localhost}" | sed -E 's#^[a-z]+://##; s#[:/].*##')"
[ -n "$DOMAIN_ONLY" ] || DOMAIN_ONLY="localhost"
if [ -n "${HTTPS_ADDR:-}" ]; then
  REAL_PORT="${HTTPS_ADDR##*:}"
  if [ "$REAL_PORT" = "443" ]; then
    DISPLAY_URL="https://${DOMAIN_ONLY}/"
  else
    DISPLAY_URL="https://${DOMAIN_ONLY}:${REAL_PORT}/"
  fi
else
  DISPLAY_URL="${APP_URL:-ver .env} (contenedores no levantados — ver .env para la URL configurada)"
fi

echo
echo "============================================================"
echo " OK — ${APP_NAME:-proyecto} desplegado con Docker en AlmaLinux EL$EL_VERSION."
echo "    URL:          ${DISPLAY_URL}"
echo "    Contenedores: docker compose ps"
echo "    Logs:         docker compose logs -f"
echo "    Bajar todo:   docker compose down"
echo "============================================================"
