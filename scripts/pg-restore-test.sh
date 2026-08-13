#!/usr/bin/env bash
#
# Restores the newest backup into a throwaway database and checks it is usable.
#
# The whole point: an untested backup is a guess. Run this monthly, and after
# any change to pg-backup.sh or the schema.
#
#   ./pg-restore-test.sh                       # arsip terbaru
#   ./pg-restore-test.sh /path/to/file.dump    # arsip tertentu
#
# Database uji dibuat dan dihapus lagi; database asli tidak disentuh.

set -Eeuo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-maharasa}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/maharasa}"

TEST_DB="maharasa_restore_test_$(date +%s)"

archive="${1:-}"
if [ -z "$archive" ]; then
  archive="$(ls -t "${BACKUP_DIR}"/maharasa-*.dump 2>/dev/null | head -1 || true)"
fi

if [ -z "$archive" ] || [ ! -f "$archive" ]; then
  echo "GAGAL: tidak ada arsip backup di ${BACKUP_DIR}" >&2
  exit 1
fi

echo "Menguji arsip: ${archive}"

cleanup() {
  dropdb --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --if-exists "$TEST_DB" || true
}
trap cleanup EXIT

createdb --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" "$TEST_DB"

pg_restore \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --username="$DB_USER" \
  --dbname="$TEST_DB" \
  --no-owner \
  --exit-on-error \
  "$archive"

# Tabel yang kalau kosong berarti restore-nya tidak berguna.
required="outlets plate_colors menus users"
failed=0

for table in $required; do
  count="$(psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" \
    --dbname="$TEST_DB" --tuples-only --no-align \
    --command="SELECT COUNT(*) FROM ${table};" 2>/dev/null || echo 0)"

  printf '  %-16s %s baris\n' "$table" "$count"

  if [ "$count" -eq 0 ]; then
    echo "  PERINGATAN: ${table} kosong" >&2
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "HASIL: arsip bisa di-restore, TAPI ada tabel master yang kosong. Periksa." >&2
  exit 1
fi

echo "HASIL: restore berhasil dan data master terisi."
