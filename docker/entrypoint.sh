#!/bin/sh
#
# Cache config dibangun saat container start, bukan saat image build.
#
# Dulu `config:cache` jalan di stage builder. Efeknya: apa pun isi src/.env di
# mesin yang mem-build ikut dibekukan ke bootstrap/cache/config.php, dan
# APP_ENV / APP_DEBUG / REDIS_HOST yang diset di docker-compose.yml dibaca dari
# cache yang dibuat sebelum nilai-nilai itu ada — jadi diam-diam diabaikan.
# Build dari mesin developer menghasilkan image produksi dengan APP_DEBUG=true
# (stack trace bocor ke klien) dan DB_HOST=localhost (tidak ada DB di sana).
#
# Sekarang urutannya benar: env masuk lebih dulu lewat `env_file:`, baru
# di-cache. Konsekuensinya, ganti environment variable = restart container.
set -e

cd /var/www/html

# config:cache sudah membersihkan cache lama, tapi kalau file cache basi ikut
# tersalin ke image, config:cache akan membacanya duluan. Jadi buang eksplisit.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
