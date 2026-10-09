#!/usr/bin/env bash
#
# Khôi phục PostgreSQL từ một file do backup-db.sh tạo ra.
#
#   ./scripts/restore-db.sh backups/db-20260101-020000.dump
#
# CẢNH BÁO: dữ liệu hiện tại trong database sẽ bị thay bằng dữ liệu trong file.
# Thêm tham số --yes để bỏ qua bước hỏi xác nhận (dùng trong script tự động).
set -euo pipefail

cd "$(dirname "$0")/.."

FILE="${1:-}"
ASSUME_YES="${2:-}"

if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
    echo "Cách dùng: ./scripts/restore-db.sh <file .dump> [--yes]" >&2
    exit 1
fi

env_value() {
    [ -f .env ] || return 0
    grep -E "^$1=" .env | tail -n 1 | cut -d '=' -f 2- | tr -d "\"'\r" || true
}

DB_NAME="$(env_value DB_DATABASE)"
DB_NAME="${DB_NAME:-service_manager}"
DB_USER="$(env_value DB_USERNAME)"
DB_USER="${DB_USER:-service_manager}"

if [ "$ASSUME_YES" != "--yes" ]; then
    echo "Sắp khôi phục database \"$DB_NAME\" từ: $FILE"
    echo "Toàn bộ dữ liệu hiện tại sẽ bị ghi đè."
    read -r -p "Gõ 'yes' để tiếp tục: " ANSWER
    if [ "$ANSWER" != "yes" ]; then
        echo "Đã huỷ."
        exit 1
    fi
fi

docker compose exec -T postgres pg_restore \
    -U "$DB_USER" -d "$DB_NAME" \
    --clean --if-exists --no-owner --no-privileges < "$FILE"

# Xoá cache để ứng dụng đọc lại dữ liệu (vai trò, quyền...) vừa khôi phục.
docker compose exec -T -u www-data app php artisan optimize:clear >/dev/null 2>&1 || true

echo "Đã khôi phục xong từ $FILE."
