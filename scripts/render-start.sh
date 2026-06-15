#!/usr/bin/env sh
set -e

mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/app/tmp \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

php artisan storage:link --force
php artisan config:clear
php artisan view:clear

if [ "${RUN_MIGRATIONS:-true}" != "false" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan view:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
