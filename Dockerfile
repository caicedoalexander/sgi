FROM php:8.5-fpm

# Base image: php:8.5-fpm = Debian 13 (Trixie). Package names alineados a Trixie.

# 1) Runtime + build dependencies (libs *-dev quedan instaladas porque las
#    extensiones compartidas las enlazan dinámicamente en runtime).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        unzip \
        git \
        ca-certificates \
        libicu-dev \
        libfreetype-dev \
        libjpeg-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libexif-dev \
    && rm -rf /var/lib/apt/lists/*

# 2) Configurar gd (freetype + jpeg) y compilar extensiones de forma secuencial.
#    Sin -j$(nproc): la build paralela tiene un race condition documentado en
#    PHP 8.5 (cp: cannot stat 'modules/*': No such file or directory).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install intl \
    && docker-php-ext-install mbstring \
    && docker-php-ext-install opcache \
    && docker-php-ext-install gd \
    && docker-php-ext-install zip \
    && docker-php-ext-install exif

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application
COPY . .
RUN composer dump-autoload --optimize --no-dev

# Generate app_local.php from example (uses env vars at runtime)
RUN cp config/app_local.example.php config/app_local.php

# Create directories and set permissions
RUN mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views \
    tmp/debug_kit tmp/sessions tmp/tests logs webroot/files webroot/uploads \
    && chown -R www-data:www-data tmp logs webroot/files webroot/uploads \
    && chmod -R 775 tmp logs webroot/files webroot/uploads

# Nginx and PHP config
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/99-production.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
