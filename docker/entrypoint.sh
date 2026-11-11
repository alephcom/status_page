#!/bin/sh
set -e

# Only the web container runs migrations; worker and scheduler wait for it.
if [ "$1" = "apache2-foreground" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
