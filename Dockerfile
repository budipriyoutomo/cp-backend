# =========================================
# STAGE 1 — Build Laravel
# =========================================
FROM php:8.2-fpm AS builder

WORKDIR /var/www/html

# gd dan zip dipasang untuk phpoffice/phpspreadsheet (template impor produksi
# backdate): keduanya ada di daftar require paketnya, jadi tanpa ini `composer
# install` di bawah gagal — bukan request pertama yang gagal.
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libxml2-dev libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql zip gd opcache bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# .dockerignore menahan src/.env, vendor/, dan cache milik mesin developer.
COPY src/ .

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# Sengaja TIDAK ada config:cache / route:cache / view:cache di sini.
#
# Tidak ada .env pada tahap ini, jadi cache yang dibuat sekarang akan berisi
# nilai kosong — dan config yang di-cache mengalahkan environment variable saat
# runtime, jadi kesalahannya akan diam. Ketiganya dipindah ke
# docker/entrypoint.sh, yang jalan setelah env masuk.


# =========================================
# STAGE 2 — Runtime
# =========================================
FROM php:8.2-fpm

WORKDIR /var/www/html

# `cron` sudah tidak dipasang: scheduler sekarang dijalankan supervisord lewat
# `artisan schedule:work`. Lihat komentar di docker/supervisord.conf.
# gd dan zip juga dipasang di sini, bukan hanya di builder: vendor/ disalin apa
# adanya dari tahap sebelumnya, jadi ekstensi yang hilang di runtime baru
# ketahuan sebagai 500 saat operator mengunduh template.
RUN apt-get update && apt-get install -y \
    nginx supervisor curl libpq-dev redis-tools \
    libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip gd opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=builder /var/www/html /var/www/html

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

# Isi direktori ini ditahan .dockerignore (cache mesin developer), tapi Laravel
# tetap butuh direktorinya ada — view:cache di entrypoint gagal tanpa itu.
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord"]
