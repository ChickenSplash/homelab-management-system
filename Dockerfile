# syntax=docker/dockerfile:1

# 1. PHP dependencies. pcntl and posix only exist in the runtime image.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader \
        --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix

# 2. Runtime. One image for every service: the API (Octane on FrankenPHP), Reverb,
#    the queue worker and the scheduler only differ in their command.
FROM dunglas/frankenphp:1-php8.4-alpine

# pcntl lets Reverb and the queue worker stop cleanly on SIGTERM.
RUN install-php-extensions pcntl

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor

# Octane copies its worker script into public/ on start, which www-data cannot write to.
RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/

# Caddy (inside FrankenPHP) keeps its state under the XDG dirs, which default to
# root-owned /config and /data in this image.
ENV XDG_CONFIG_HOME=/app/storage/caddy/config \
    XDG_DATA_HOME=/app/storage/caddy/data

RUN mkdir -p data \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
        storage/caddy/config storage/caddy/data bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache data

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data

EXPOSE 3000 8080

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=3000", "--workers=4", "--max-requests=500"]
