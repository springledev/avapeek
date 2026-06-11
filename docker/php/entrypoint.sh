#!/bin/sh
set -e

# Cache Laravel config and routes with the correct production env
php artisan config:cache
php artisan route:cache

# Execute the main container command (frankenphp run ...)
exec "$@"
