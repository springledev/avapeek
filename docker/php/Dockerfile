FROM dunglas/frankenphp:1.13.5-php8.5.12-alpine

RUN install-php-extensions \
    pdo_pgsql \
    gd \
    zip \
    opcache \
    pcntl \
    intl \
    redis

# Backups (backup:database / backup:verify). pg_dump's major version must be >= the
# server's: keep postgresql18-client in step with the postgres image in the compose
# files (postgres:18.x-alpine). age is for optional BACKUP_AGE_RECIPIENT encryption.
RUN apk add --no-cache postgresql18-client age

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2.11.1 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/ .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV SERVER_NAME=:80

COPY docker/php/Caddyfile /etc/caddy/Caddyfile
COPY docker/php/opcache.prod.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/security.ini /usr/local/etc/php/conf.d/security.ini
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
