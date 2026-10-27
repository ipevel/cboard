#!/bin/sh
set -e

# Ensure runtime directories exist and are writable by the application user.
# This also covers bind-mounted storage that arrives owned by the host user.
if [ "$(id -u)" = "0" ]; then
    mkdir -p /www/storage/framework/cache /www/storage/framework/sessions \
        /www/storage/framework/views /www/storage/logs
    chown -R www:www /www/storage /www/bootstrap/cache /www/config/theme 2>/dev/null || true
fi

# Wait for the database to become reachable before booting the application.
if [ -f /www/.env ]; then
    db_host=$(grep -E '^DB_HOST=' /www/.env | cut -d= -f2- | tr -d '"' || true)
    if [ -n "$db_host" ] && [ "$db_host" != "localhost" ] && [ "$db_host" != "127.0.0.1" ]; then
        db_port=$(grep -E '^DB_PORT=' /www/.env | cut -d= -f2- | tr -d '"' || true)
        db_port=${db_port:-3306}
        echo "Waiting for database at ${db_host}:${db_port}..."
        i=0
        until nc -z "$db_host" "$db_port" 2>/dev/null; do
            i=$((i + 1))
            if [ "$i" -ge 120 ]; then
                echo "Database at ${db_host}:${db_port} did not become reachable in time." >&2
                exit 1
            fi
            sleep 2
        done
        echo "Database is reachable."
    fi

    # Warm the Laravel config cache as the application user.
    su-exec www php /www/artisan config:cache || true
fi

# When a command is supplied (for example the Horizon worker), run it as the app user.
if [ "$#" -gt 0 ]; then
    exec su-exec www "$@"
fi

# Start nginx (master as root, workers as the nginx user), then PHP-FPM in the foreground.
nginx
exec php-fpm -F
