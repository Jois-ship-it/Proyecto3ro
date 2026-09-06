FROM php:8.2-apache

# Forzar IPv4 en apt: en servidores con IPv6 mal enrutado (típico en VPS),
# apt intenta cada conexión por IPv6 primero, espera el timeout completo y
# recién ahí cae a IPv4 — multiplicado por las muchas conexiones que hace
# apt-get, esto puede volver el build muchísimo más lento. Sin costo en
# servidores con IPv6 sano.
RUN echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99force-ipv4

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    default-mysql-client unzip openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite (enrutamiento), mod_ssl (HTTPS en 443, ver ssl/README.md),
# mod_headers y mod_expires: public/.htaccess ya declara headers de
# seguridad (X-Frame-Options, X-Content-Type-Options, etc.) y caché de
# assets envueltos en <IfModule>, pero sin estos dos módulos habilitados
# Apache los ignora en silencio — nunca se aplicaban.
RUN a2enmod rewrite && a2enmod ssl && a2enmod headers && a2enmod expires

# El vhost de HTTP (puerto 80) NO sirve contenido: redirige todo a HTTPS.
# Usa el host de la petición (no un dominio fijo) para que funcione igual
# entrando por el dominio o por la IP del servidor — pero descarta el
# puerto que haya venido en esa petición y siempre agrega el puerto HTTPS
# real (__HTTPS_PORT__, reemplazado por docker-entrypoint.sh con la
# variable de entorno HTTPS_PORT). Sin esto, entrar por un puerto HTTP no
# estándar (ej. 8080 en desarrollo local) redirigiría al mismo número de
# puerto en HTTPS, que casi nunca es el correcto.
# Reemplaza por completo el 000-default.conf que trae la imagen base.
RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    ServerName localhost' \
    '    RewriteEngine On' \
    '    RewriteCond %{HTTP_HOST} ^([^:]+)' \
    '    RewriteRule ^ https://%1:__HTTPS_PORT__%{REQUEST_URI} [L,R=301]' \
    '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Configuración de Apache para permitir .htaccess
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/flexarena.conf \
    && a2enconf flexarena

# VirtualHost HTTPS (443). El certificado lo prepara docker-entrypoint.sh
# en /etc/apache2/ssl/ antes de que arranque Apache (real si esta en
# ssl/, autofirmado si no, ver ssl/README.md).
RUN printf '%s\n' \
    '<IfModule mod_ssl.c>' \
    '<VirtualHost *:443>' \
    '    ServerName localhost' \
    '    DocumentRoot /var/www/flexarena/public' \
    '    SSLEngine on' \
    '    SSLCertificateFile /etc/apache2/ssl/certificate.crt' \
    '    SSLCertificateKeyFile /etc/apache2/ssl/private.key' \
    '    <Directory /var/www/flexarena/public>' \
    '        Options -Indexes +FollowSymLinks' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '</VirtualHost>' \
    '</IfModule>' > /etc/apache2/sites-available/flexarena-ssl.conf \
    && a2ensite flexarena-ssl

# PHP: timezone y error reporting según entorno
RUN echo "date.timezone = America/Argentina/Buenos_Aires" >> /usr/local/etc/php/php.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/php.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/php.ini \
    && echo "error_log = /var/log/apache2/php_errors.log" >> /usr/local/etc/php/php.ini \
    && echo "session.cookie_httponly = 1" >> /usr/local/etc/php/php.ini \
    && echo "session.use_strict_mode = 1" >> /usr/local/etc/php/php.ini

# Copiar código fuente (public/ es el document root)
COPY . /var/www/flexarena/
WORKDIR /var/www/flexarena

# Apuntar Apache a public/ (000-default.conf ya no referencia /var/www/html:
# se reescribió arriba como vhost de redirección, sin DocumentRoot).
RUN sed -i 's|/var/www/html|/var/www/flexarena/public|g' /etc/apache2/conf-available/flexarena.conf

# Permisos
RUN chown -R www-data:www-data /var/www/flexarena \
    && find /var/www/flexarena -type d -exec chmod 755 {} \; \
    && find /var/www/flexarena -type f -exec chmod 644 {} \;

# Entrypoint: prepara el certificado SSL (real o autofirmado) antes de
# arrancar Apache.
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80 443
ENTRYPOINT ["docker-entrypoint.sh"]
