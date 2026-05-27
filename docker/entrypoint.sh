#!/usr/bin/env sh
set -e

APP_KEY_FILE="/var/www/html/storage/app_key"

if [ -z "${APP_KEY:-}" ]; then
    if [ -f "$APP_KEY_FILE" ]; then
        APP_KEY="$(cat "$APP_KEY_FILE")"
    else
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        echo "$APP_KEY" > "$APP_KEY_FILE"
    fi
    export APP_KEY
fi

if [ "${CONTAINER_ROLE:-app}" = "queue" ]; then
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        php artisan migrate --force
    fi

    exec php artisan queue:work --tries=1 --timeout=90
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec php-fpm -F
