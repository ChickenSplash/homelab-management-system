#!/bin/sh
set -e

# Laravel needs an APP_KEY. There is no .env in the image, so read it from the
# environment and fall back to an ephemeral one (which invalidates sessions on
# restart -- set APP_KEY in the environment to avoid that).
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
    echo "entrypoint: no APP_KEY given, generated an ephemeral one for this container."
fi

# SQLite lives on a volume, so the file may not exist yet on a fresh container.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    : "${DB_DATABASE:=/app/data/database.sqlite}"
    touch "$DB_DATABASE"
fi

# Only the API container migrates; the others wait for it to report healthy,
# so four containers never race on the same SQLite file.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --graceful
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
