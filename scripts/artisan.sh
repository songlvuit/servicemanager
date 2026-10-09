#!/usr/bin/env bash
#
# Chạy lệnh artisan trong container app bằng user www-data (để file sinh ra
# trong storage/ không bị thuộc quyền root).
#
#   ./scripts/artisan.sh migrate:fresh --seed
#   ./scripts/artisan.sh test
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -t 0 ]; then
    exec docker compose exec -u www-data app php artisan "$@"
fi

exec docker compose exec -T -u www-data app php artisan "$@"
