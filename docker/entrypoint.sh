#!/bin/sh
set -e

# Only the web container runs migrations; worker and scheduler wait for it.
if [ "$1" = "apache2-foreground" ]; then
    php artisan migrate --force
    # Uploaded logos live in storage/app/public.
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
