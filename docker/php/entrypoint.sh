#!/bin/sh
set -e

if [ "${APP_ENV}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
