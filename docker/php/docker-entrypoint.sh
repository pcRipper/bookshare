#!/bin/sh
set -e

if [ ! -f /var/www/app/vendor/autoload.php ]; then
    echo "==> Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

mkdir -p /var/www/app/var/cache \
         /var/www/app/var/log \
         /var/www/app/var/share \
         /var/www/app/var/sessions

chown -R www-data:www-data /var/www/app/var

exec "$@"
