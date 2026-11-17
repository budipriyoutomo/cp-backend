# Maharasa Colorplate — Backend

Laravel 10 / PHP 8.2 / PostgreSQL. Arsitektur dan konvensi: [CLAUDE.md](CLAUDE.md).

## Prasyarat lokal

- PHP 8.2 dengan ekstensi:
  - `bcmath` — dibutuhkan `php-amqplib` (worker POS & publish closing report ke BI). Tanpa ini `composer install` gagal.
  - `pdo_pgsql` — database produksi.
  - `pdo_sqlite` — test suite (SQLite in-memory, lihat `phpunit.xml`).
  - `zip`, `gd` — `phpoffice/phpspreadsheet` (template & impor produksi backdate).
- Composer 2.

`Dockerfile` sudah memasang semuanya di kedua stage; daftar di atas untuk mesin developer.

## Setup

```bash
cd src
cp .env.example .env        # isi DB lokal, AWS, RabbitMQ, BOOTSTRAP_*
composer install
php artisan key:generate
php artisan jwt:secret -f
php artisan migrate
php artisan db:seed --class=BootstrapSeeder
php artisan test
```

## Konfigurasi yang menyangkut keamanan

- **`APP_DEBUG`** — default `false` di `.env.example`. Nyalakan sendiri di lokal. Container dengan `APP_ENV=production` + `APP_DEBUG=true` **menolak start** (`docker/entrypoint.sh`) karena stack trace bocor ke klien.
- **`CORS_EXTRA_ORIGIN_PATTERNS`** — regex origin tambahan, dipisah koma. **Kosongkan di produksi.** Domain preview v0 (`*.vusercontent.net`) bisa dipakai siapa saja, jadi hanya untuk lokal/staging.

Deploy (Docker, migrate, scheduler): bagian *Deploy* di [CLAUDE.md](CLAUDE.md).
