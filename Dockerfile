FROM php:8.2-apache

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    default-mysql-client unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Configuración de Apache para permitir .htaccess
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/flexarena.conf \
    && a2enconf flexarena

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

# Apuntar Apache a public/
RUN sed -i 's|/var/www/html|/var/www/flexarena/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|/var/www/html|/var/www/flexarena/public|g' /etc/apache2/conf-available/flexarena.conf

# Permisos
RUN chown -R www-data:www-data /var/www/flexarena \
    && find /var/www/flexarena -type d -exec chmod 755 {} \; \
    && find /var/www/flexarena -type f -exec chmod 644 {} \;

EXPOSE 80
