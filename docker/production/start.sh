#!/bin/sh
# Prepares the container, then hands over to supervisord.
set -e

cd /var/www/html

echo "FloatBook starting…"

# An app key is required; fail loudly rather than serving broken pages.
if [ -z "${APP_KEY}" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Bring the database up to date. The first deploy creates every table.
echo "Running migrations…"
php artisan migrate --force || {
    echo "Migrations failed. The container will not start." >&2
    exit 1
}

# Cache the framework's configuration, routes, views and event bindings.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Rebuild the demo business, in the background: it takes about a minute and the web
# server should be answering long before then.
if [ "${DEMO_MODE}" = "true" ]; then
    (php artisan floatbook:reset-demo --force || echo "Demo seeding failed; carrying on." >&2) &
fi

echo "FloatBook ready on port 8080."
exec supervisord -c /etc/supervisord.conf
