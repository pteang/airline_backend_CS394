#!/bin/sh
set -e
cd /var/www/html

# A .env must exist for artisan; runtime env vars (from compose) take precedence.
[ -f .env ] || cp .env.example .env

# Drop any stale bootstrap caches (e.g. a host-generated packages manifest that
# references dev-only providers) so Laravel rediscovers from the --no-dev vendor.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
      bootstrap/cache/config.php bootstrap/cache/routes-*.php 2>/dev/null || true

# Make sure writable dirs are owned by the PHP user (covers mounted volumes too).
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Only the web/init service runs migrations; the queue worker just waits for the DB.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "⏳ Waiting for the database and applying migrations..."
    until php artisan migrate --force 2>&1; do
        echo "   database not ready — retrying in 3s"
        sleep 3
    done

    if [ "${DB_SEED:-false}" = "true" ]; then
        # Seed only when the database is still empty, so restarts don't wipe data.
        COUNT=$(php artisan tinker --execute="echo \\App\\Models\\Flight::count();" 2>/dev/null | grep -oE '[0-9]+' | tail -1)
        if [ -z "$COUNT" ] || [ "$COUNT" = "0" ]; then
            echo "🌱 Seeding database..."
            php artisan db:seed --force || true
        else
            echo "✓ Database already has data (${COUNT} flights) — skipping seed."
        fi
    fi
else
    # Queue worker / other services: block until migrations table is reachable.
    echo "⏳ Waiting for the database..."
    until php artisan migrate:status >/dev/null 2>&1; do
        sleep 3
    done
fi

echo "✅ Backend ready."
exec "$@"
