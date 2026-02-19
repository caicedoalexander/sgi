FROM php:8.4-fpm

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    nginx \
    unzip \
    git \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libonig-dev \
    libexif-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        intl \
        mbstring \
        opcache \
        gd \
        zip \
        exif \
    && rm -rf /var/lib/apt/lists/*

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
    tmp/debug_kit tmp/sessions tmp/tests logs webroot/files \
    && chown -R www-data:www-data tmp logs webroot/files \
    && chmod -R 775 tmp logs webroot/files

# Nginx and PHP config
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/99-production.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
