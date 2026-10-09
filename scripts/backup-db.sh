#!/usr/bin/env bash
#
# Sao lưu PostgreSQL ra thư mục backups/.
#
#   ./scripts/backup-db.sh
#   BACKUP_RETENTION_DAYS=14 ./scripts/backup-db.sh   # đồng thời xoá bản cũ hơn 14 ngày
#
# File tạo ra có dạng backups/db-YYYYmmdd-HHMMSS.dump (định dạng custom của
# pg_dump), khôi phục bằng ./scripts/restore-db.sh <file>.
set -euo pipefail

cd "$(dirname "$0")/.."

env_value() {
    [ -f .env ] || return 0
    grep -E "^$1=" .env | tail -n 1 | cut -d '=' -f 2- | tr -d "\"'\r" || true
}

DB_NAME="$(env_value DB_DATABASE)"
DB_NAME="${DB_NAME:-service_manager}"
DB_USER="$(env_value DB_USERNAME)"
DB_USER="${DB_USER:-service_manager}"

mkdir -p backups
FILE="backups/db-$(date +%Y%m%d-%H%M%S).dump"

echo "Đang sao lưu database \"$DB_NAME\"..."

if ! docker compose exec -T postgres pg_dump -U "$DB_USER" -d "$DB_NAME" --format=custom --no-owner > "$FILE"; then
    rm -f "$FILE"
    echo "Sao lưu thất bại." >&2
    exit 1
fi

if [ ! -s "$FILE" ]; then
    rm -f "$FILE"
    echo "Sao lưu thất bại: file rỗng." >&2
    exit 1
fi

echo "Đã sao lưu: $FILE ($(du -h "$FILE" | cut -f 1))"

if [ -n "${BACKUP_RETENTION_DAYS:-}" ]; then
    find backups -name 'db-*.dump' -type f -mtime +"$BACKUP_RETENTION_DAYS" -print -delete
fi
