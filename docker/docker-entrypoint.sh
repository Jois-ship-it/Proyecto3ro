#!/usr/bin/env bash
# ============================================================
# Entrypoint del contenedor 'app': prepara el certificado SSL y
# espera a que la base de datos acepte conexiones reales antes de
# arrancar Apache.
#
# Si en ssl/certificate.crt + ssl/private.key (provistos por la
# institución, montados vía bind mount del proyecto) hay un
# certificado real, se usa ese. Si no están todavía, se genera
# un certificado autofirmado temporal para que HTTPS funcione
# igual en desarrollo (el navegador va a mostrar advertencia de
# "no seguro", esperable hasta que se coloque el certificado real).
# ============================================================
set -euo pipefail

PROJECT_SSL_DIR="/var/www/flexarena/ssl"
APACHE_SSL_DIR="/etc/apache2/ssl"

mkdir -p "$APACHE_SSL_DIR"

if [ -f "$PROJECT_SSL_DIR/certificate.crt" ] && [ -f "$PROJECT_SSL_DIR/private.key" ]; then
    echo "==> SSL: usando certificado provisto en ssl/certificate.crt + ssl/private.key"
    cp "$PROJECT_SSL_DIR/certificate.crt" "$APACHE_SSL_DIR/certificate.crt"
    cp "$PROJECT_SSL_DIR/private.key"     "$APACHE_SSL_DIR/private.key"
else
    echo "==> SSL: no se encontró ssl/certificate.crt + ssl/private.key todavía."
    echo "    Generando certificado autofirmado temporal (SOLO para desarrollo)."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$APACHE_SSL_DIR/private.key" \
        -out "$APACHE_SSL_DIR/certificate.crt" \
        -subj "/CN=localhost" \
        -addext "basicConstraints=critical,CA:FALSE" \
        -addext "subjectAltName=DNS:localhost" 2>/dev/null
fi

chmod 600 "$APACHE_SSL_DIR/private.key"
chmod 644 "$APACHE_SSL_DIR/certificate.crt"

# ServerName de los dos vhosts (HTTP redirect + SSL) = host de APP_URL
# (evita el warning de Apache; por default queda "localhost" en desarrollo
# si APP_URL no está seteada).
SERVER_NAME="$(printf '%s' "${APP_URL:-http://localhost}" | sed -E 's#^[a-z]+://##; s#[:/].*##')"
[ -n "$SERVER_NAME" ] || SERVER_NAME="localhost"
sed -i "s/^\(\s*ServerName\).*/\1 ${SERVER_NAME}/" /etc/apache2/sites-available/flexarena-ssl.conf
sed -i "s/^\(\s*ServerName\).*/\1 ${SERVER_NAME}/" /etc/apache2/sites-available/000-default.conf

# Puerto HTTPS real (el publicado al host) para el redirect de :80 -> :443.
# Sin esto, entrar por un puerto HTTP no estándar (ej. 8080 en desarrollo
# local) redirigiría al mismo número de puerto en HTTPS.
sed -i "s/__HTTPS_PORT__/${HTTPS_PORT:-443}/" /etc/apache2/sites-available/000-default.conf

# Esperar a que la base de datos acepte conexiones REALES (con PDO, el mismo
# camino que usa la app) antes de arrancar Apache. El healthcheck de
# docker-compose.yml puede dar "healthy" contra el servidor temporal que
# usa la imagen de MySQL para correr schema.sql/seed.sql en la primera
# inicialización — ese servidor se apaga y el definitivo tarda unos
# segundos más en levantar, dejando un hueco real sin nada escuchando en
# el puerto. Esto evita arrancar justo en ese hueco.
echo "==> Esperando a que la base de datos acepte conexiones..."
DB_READY=0
for i in $(seq 1 60); do
    if php -r '
        $h = getenv("DB_HOST") ?: "db";
        $p = getenv("DB_PORT") ?: "3306";
        $n = getenv("DB_NAME");
        $u = getenv("DB_USER");
        $pw = getenv("DB_PASS");
        try {
            new PDO("mysql:host={$h};port={$p};dbname={$n}", $u, $pw);
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' 2>/dev/null; then
        DB_READY=1
        break
    fi
    sleep 2
done
if [ "$DB_READY" -eq 1 ]; then
    echo "==> Base de datos lista."
else
    echo "==> ADVERTENCIA: la base de datos no respondió a tiempo; arrancando igual." >&2
fi

# ── Datos de demostración (una vez por instalación) ──────────────────────────
# La imagen de MySQL corre schema.sql y seed.sql desde /docker-entrypoint-initdb.d,
# pero seed_demo.php es PHP y no puede ejecutarse ahí: se corre desde este
# contenedor, que sí tiene PHP y llega a la base por la red interna.
#
# La condición anterior era "sembrar solo si no hay ningún torneo", y nunca se
# cumplía: seed.sql ya crea 6. Los datos de demostración no se cargaban jamás,
# aunque el README dijera que sí.
#
# Ahora se usa una marca en /var/lib/flexarena, que es un volumen propio del
# stack. Eso da exactamente la semántica que hace falta:
#
#   docker compose restart / up   → la marca sigue ahí, no se vuelve a sembrar
#   docker compose down -v        → se borra con el resto, se siembra de nuevo
#
# Importante: seed_demo.php TRUNCA las tablas de datos antes de sembrar. Por eso
# la decisión no se infiere del contenido de la base —donde un falso negativo
# borraría trabajo real— sino de una marca explícita. SEED_DEMO=0 lo desactiva;
# SEED_DEMO=force siembra igual, pisando lo que haya.
SEED_DEMO_SCRIPT="/var/www/flexarena/database/seed_demo.php"
ESTADO_DIR="/var/lib/flexarena"
MARCA_DEMO="$ESTADO_DIR/datos_demo_cargados"

if [ "$DB_READY" -eq 1 ] && [ "${SEED_DEMO:-1}" != "0" ]; then
    if [ ! -f "$SEED_DEMO_SCRIPT" ]; then
        echo "==> No está $SEED_DEMO_SCRIPT (¿falta el bind mount del proyecto?): se omite la siembra." >&2
    elif [ -f "$MARCA_DEMO" ] && [ "${SEED_DEMO:-1}" != "force" ]; then
        echo "==> Los datos de demostración ya se cargaron en esta instalación."
        echo "    Para volver a cargarlos: SEED_DEMO=force, o 'docker compose down -v' y arrancar de cero."
    else
        echo "==> Cargando datos de demostración (database/seed_demo.php)."
        echo "    Tarda un par de minutos y se hace una sola vez por instalación."
        if php "$SEED_DEMO_SCRIPT"; then
            mkdir -p "$ESTADO_DIR"
            date -Iseconds > "$MARCA_DEMO"
            echo "==> Datos de demostración cargados."
        else
            echo "==> ADVERTENCIA: falló la carga de datos de demostración; la app arranca igual." >&2
        fi
    fi
fi

# Apache en primer plano: es el proceso principal del contenedor.
exec apache2-foreground
