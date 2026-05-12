# =========================================
# STAGE 1 — Build Laravel
# =========================================
FROM php:8.2-fpm AS builder

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libxml2-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip opcache bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY src/ .

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache


# =========================================
# STAGE 2 — Runtime
# =========================================
FROM php:8.2-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    nginx supervisor cron curl libpq-dev redis-tools \
    && docker-php-ext-install pdo pdo_pgsql pgsql opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=builder /var/www/html /var/www/html

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/laravel-cron /etc/cron.d/laravel-cron

RUN chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord"]