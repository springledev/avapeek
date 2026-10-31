#!/bin/sh
set -e

if [ "${APP_ENV}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

# Publish the object storage origin for the reverse proxy's Content-Security-Policy
# (connect-src). Only the web container sets CSP_STORAGE_ORIGIN_FILE; the bundled
# Caddy reads the file on every request. A failure must never block startup.
if [ -n "${CSP_STORAGE_ORIGIN_FILE:-}" ]; then
    php artisan storage:csp-origin --write="${CSP_STORAGE_ORIGIN_FILE}" \
        || echo "warning: could not derive the storage origin for the Content-Security-Policy; photos from external storage will be blocked. Run 'php artisan storage:csp-origin'." >&2
fi

exec "$@"
