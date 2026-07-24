#!/usr/bin/env bash
# ============================================================
# install.sh — Instalación de FlexArena en un servidor Linux
# (Debian/Ubuntu + Apache + PHP + MySQL/MariaDB).
#
# Despliegue "Opción A": el DocumentRoot apunta a public/, dejando
# el resto del código (.env, app/, core/, ...) FUERA del alcance web.
#
# Toda la configuración se toma del .env del proyecto (no se duplica).
# Idempotente: se puede re-ejecutar sin romper una instalación previa.
#
# Uso (como root):   sudo bash scripts/install.sh
# Opciones:
#   --no-deps        No instalar paquetes del sistema (apt)
#   --no-vhost       No configurar el VirtualHost de Apache
#   --root-pass PWD  Contraseña de root de MySQL (si no usa auth por socket)
#
# Nota: para entornos con Docker, usá  `docker compose up -d`  en su lugar.
# ============================================================
set -euo pipefail

# ---- Rutas ----
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_ROOT/.env"

# ---- Flags ----
INSTALL_DEPS=1; CONFIG_VHOST=1; MYSQL_ROOT_PASS=""
while [ $# -gt 0 ]; do
  case "$1" in
    --no-deps)   INSTALL_DEPS=0 ;;
    --no-vhost)  CONFIG_VHOST=0 ;;
    --root-pass) MYSQL_ROOT_PASS="${2:-}"; shift ;;
    *) echo "Opción desconocida: $1" >&2; exit 1 ;;
  esac
  shift
done

# ---- Requiere root ----
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: ejecutá como root  ->  sudo bash scripts/install.sh" >&2
  exit 1
fi

# ---- Cargar .env (ignora comentarios/blancos; soporta CRLF) ----
[ -f "$ENV_FILE" ] || { echo "ERROR: no existe $ENV_FILE" >&2; exit 1; }
while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  case "$line" in ''|\#*) continue ;; esac
  [ "${line#*=}" != "$line" ] || continue
  key="${line%%=*}"; val="${line#*=}"
  val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
  export "$key=$val"
done < "$ENV_FILE"

: "${APP_NAME:?falta APP_NAME en .env}"
: "${DB_NAME:?falta DB_NAME en .env}"
: "${DB_USER:?falta DB_USER en .env}"
: "${DB_PASS:?falta DB_PASS en .env}"
DB_HOST="${DB_HOST:-localhost}"
APP_URL="${APP_URL:-http://localhost}"
PROJECT="$DB_NAME"   # identificador para el VirtualHost / conf

# ---- Parsear APP_URL -> ServerName + puerto ----
url_noproto="${APP_URL#*://}"
SERVER_NAME="${url_noproto%%[:/]*}"
case "$url_noproto" in
  *:*) HTTP_PORT="${url_noproto#*:}"; HTTP_PORT="${HTTP_PORT%%/*}" ;;
  *)   HTTP_PORT=80 ;;
esac
[ -n "$SERVER_NAME" ] || SERVER_NAME="localhost"

echo "==> Proyecto:   $APP_NAME ($PROJECT)"
echo "==> Código:     $PROJECT_ROOT   (DocumentRoot: $PROJECT_ROOT/public)"
echo "==> Base:       $DB_NAME · usuario $DB_USER · host $DB_HOST"
echo "==> Apache:     $SERVER_NAME : $HTTP_PORT"
echo

# ---- 1) Dependencias del sistema ----
if [ "$INSTALL_DEPS" -eq 1 ]; then
  echo "==> Instalando paquetes (apache2, php, mysql/mariadb)..."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y apache2 default-mysql-server default-mysql-client \
      php php-cli php-mysql php-gd php-mbstring php-xml libapache2-mod-php
  a2enmod rewrite headers expires >/dev/null
  systemctl enable --now apache2
  systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql 2>/dev/null || true
else
  echo "==> (--no-deps) Se omite la instalación de paquetes."
fi

# ---- 2) Base de datos + usuario (privilegios mínimos) ----
if [ "$DB_HOST" = "localhost" ] || [ "$DB_HOST" = "127.0.0.1" ]; then
  echo "==> Configurando base de datos local..."
  if [ -n "$MYSQL_ROOT_PASS" ]; then
    MYSQL=(mysql -u root -p"$MYSQL_ROOT_PASS")
  else
    MYSQL=(mysql -u root)   # auth por socket (root del sistema)
  fi

  "${MYSQL[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'%'         IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL

  # Cargar schema + seed SOLO si la base está vacía (idempotente)
  TABLES=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';")
  if [ "${TABLES:-0}" -eq 0 ]; then
    echo "==> Base vacía: cargando schema.sql (como root)..."
    "${MYSQL[@]}" "$DB_NAME" < "$PROJECT_ROOT/database/schema.sql"
    # Seed propio del proyecto (SportTime usa seed_sporttime.sql; el resto seed.sql)
    if [ -f "$PROJECT_ROOT/database/seed_sporttime.sql" ]; then SEED="seed_sporttime.sql"; else SEED="seed.sql"; fi
    if [ -f "$PROJECT_ROOT/database/$SEED" ]; then
      echo "==> Cargando seed: $SEED"
      "${MYSQL[@]}" "$DB_NAME" < "$PROJECT_ROOT/database/$SEED"
    fi
  else
    echo "==> '$DB_NAME' ya tiene $TABLES tablas: no se recarga schema/seed."
  fi
else
  echo "==> DB_HOST=$DB_HOST es remoto: se omite la creación local."
  echo "    Creá la base '$DB_NAME' y el usuario '$DB_USER' en el servidor de BD"
  echo "    (podés usar database/create_db_user.sql como referencia)."
fi

# ---- 3) Permisos ----
echo "==> Ajustando permisos (www-data)..."
chown -R www-data:www-data "$PROJECT_ROOT"
find "$PROJECT_ROOT" -type d -exec chmod 755 {} \;
find "$PROJECT_ROOT" -type f -exec chmod 644 {} \;

# ---- 4) VirtualHost de Apache ----
if [ "$CONFIG_VHOST" -eq 1 ]; then
  CONF="/etc/apache2/sites-available/${PROJECT}.conf"
  echo "==> Escribiendo $CONF ..."
  if [ "$HTTP_PORT" != "80" ] && [ "$HTTP_PORT" != "443" ]; then
    if ! grep -qE "^[[:space:]]*Listen[[:space:]]+$HTTP_PORT([[:space:]]|$)" /etc/apache2/ports.conf; then
      echo "Listen $HTTP_PORT" >> /etc/apache2/ports.conf
    fi
  fi
  cat > "$CONF" <<VHOST
<VirtualHost *:$HTTP_PORT>
    ServerName $SERVER_NAME
    DocumentRoot $PROJECT_ROOT/public

    <Directory $PROJECT_ROOT/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  \${APACHE_LOG_DIR}/${PROJECT}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PROJECT}_access.log combined
</VirtualHost>
VHOST
  a2ensite "${PROJECT}.conf" >/dev/null
  a2dissite 000-default.conf >/dev/null 2>&1 || true
  apache2ctl configtest
  systemctl reload apache2
else
  echo "==> (--no-vhost) Se omite la configuración de Apache."
fi

echo
echo "============================================================"
echo " OK — $APP_NAME instalado."
echo "    URL:       $APP_URL"
echo "    Base:      $DB_NAME  (usuario $DB_USER)"
echo "    DocRoot:   $PROJECT_ROOT/public"
echo "============================================================"
