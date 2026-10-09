#!/usr/bin/env bash
#
# Khởi động container ứng dụng.
#
# CONTAINER_ROLE=app (mặc định): chuẩn bị ứng dụng rồi mới chạy PHP-FPM
#   - tạo .env nếu chưa có, cài thư viện Composer nếu chưa có vendor/
#   - sinh APP_KEY, chạy migration, đồng bộ vai trò & quyền
# Các role khác (queue, scheduler): chạy thẳng lệnh được giao; chúng chỉ khởi
# động sau khi container app đã sẵn sàng (xem depends_on trong docker-compose.yml).
set -euo pipefail

cd /var/www/html

log() {
    echo "[entrypoint] $*"
}

if [ "${CONTAINER_ROLE:-app}" != "app" ]; then
    exec "$@"
fi

rm -f /tmp/app-ready

if [ ! -f .env ]; then
    log "Chưa có .env → tạo từ .env.example"
    cp .env.example .env
fi

# Đọc một biến trong .env (Laravel đọc trực tiếp file này, không qua biến môi trường).
env_value() {
    grep -E "^$1=" .env | tail -n 1 | cut -d '=' -f 2- | tr -d "\"'\r" || true
}

APP_ENV_VALUE="$(env_value APP_ENV)"
APP_ENV_VALUE="${APP_ENV_VALUE:-production}"
AUTO_MIGRATE_VALUE="$(env_value AUTO_MIGRATE)"
AUTO_MIGRATE_VALUE="${AUTO_MIGRATE_VALUE:-true}"

mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    log "Chưa có vendor/ → cài thư viện bằng Composer (lần đầu mất vài phút)"
    if [ "$APP_ENV_VALUE" = "production" ]; then
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    else
        composer install --no-interaction --prefer-dist
    fi
fi

if ! grep -qE '^APP_KEY=base64:.+' .env; then
    log "Sinh APP_KEY"
    php artisan key:generate --force --no-interaction
fi

php artisan storage:link --no-interaction >/dev/null 2>&1 || true

if [ "$AUTO_MIGRATE_VALUE" != "false" ]; then
    log "Chạy migration"
    php artisan migrate --force --no-interaction
    log "Đồng bộ vai trò & quyền hệ thống"
    php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction
fi

php artisan filament:assets --no-interaction

if [ "$APP_ENV_VALUE" = "production" ]; then
    log "Tối ưu cho production"
    php artisan optimize --no-interaction
    php artisan filament:optimize --no-interaction
else
    php artisan optimize:clear --no-interaction
fi

# PHP-FPM chạy bằng user www-data nên cần quyền ghi vào hai thư mục này.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

touch /tmp/app-ready
log "Sẵn sàng"

exec "$@"
