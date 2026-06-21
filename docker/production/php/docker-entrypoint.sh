#!/usr/bin/env bash
# Production phpfpm entrypoint.
#   1. ensure writable runtime dirs exist
#   2. (optional) apply pending Doctrine migrations
#   3. clear + warm the prod cache
#   4. hand off to the CMD (php-fpm)
set -euo pipefail

cd /var/www/app

mkdir -p var/cache var/log var/share var/sessions

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "==> Applying database migrations…"
    php bin/console doctrine:migrations:migrate \
        --no-interaction --all-or-nothing --allow-no-migration
fi

echo "==> Warming the production cache…"
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

exec "$@"
