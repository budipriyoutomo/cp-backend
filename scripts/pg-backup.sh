#!/usr/bin/env bash
#
# Nightly PostgreSQL backup for maharasa.
#
# Written after a `migrate:fresh` against the production database found that no
# backup existed. A backup that has never been restored is not a backup — see
# pg-restore-test.sh, and run it monthly.
#
# Cron (di server DB, bukan di container app):
#   15 2 * * * /opt/maharasa/scripts/pg-backup.sh >> /var/log/maharasa-backup.log 2>&1
#
# Kredensial: taruh di ~/.pgpass (chmod 600), jangan di skrip ini.
#   host:port:database:user:password

set -Eeuo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-maharasa_db}"
DB_USER="${DB_USER:-maharasa}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/maharasa}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

timestamp="$(date +%Y%m%d-%H%M%S)"
target="${BACKUP_DIR}/maharasa-${timestamp}.dump"

mkdir -p "$BACKUP_DIR"

echo "[$(date -Is)] Backup mulai: ${DB_NAME} @ ${DB_HOST}:${DB_PORT}"

# -Fc = custom format: kompresi, dan bisa di-restore selektif per tabel.
pg_dump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --username="$DB_USER" \
  --dbname="$DB_NAME" \
  --format=custom \
  --compress=6 \
  --file="$target"

# Verifikasi minimal: file terbaca sebagai arsip pg_dump yang utuh.
if ! pg_restore --list "$target" > /dev/null 2>&1; then
  echo "[$(date -Is)] GAGAL: arsip tidak terbaca, dihapus: ${target}" >&2
  rm -f "$target"
  exit 1
fi

size="$(du -h "$target" | cut -f1)"
tables="$(pg_restore --list "$target" | grep -c 'TABLE DATA' || true)"

echo "[$(date -Is)] Backup selesai: ${target} (${size}, ${tables} tabel berisi data)"

# Backup kosong biasanya berarti salah target atau database memang kosong —
# dua-duanya perlu diketahui, bukan didiamkan.
if [ "$tables" -eq 0 ]; then
  echo "[$(date -Is)] PERINGATAN: tidak ada tabel berisi data di arsip ini." >&2
fi

deleted="$(find "$BACKUP_DIR" -name 'maharasa-*.dump' -mtime "+${RETENTION_DAYS}" -print -delete | wc -l)"
echo "[$(date -Is)] Retensi ${RETENTION_DAYS} hari: ${deleted} arsip lama dihapus"
