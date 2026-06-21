# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-alpine

RUN apk add --no-cache \
    bash \
    nodejs \
    npm \
    git \
    libpq-dev \
    libzip-dev \
    libsodium-dev \
    icu-dev \
    fcgi \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        sodium \
        zip \
        bcmath

# Xdebug is installed but disabled by default — activate via XDEBUG_MODE env var
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/local/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/local/php/php.ini         /usr/local/etc/php/conf.d/app.ini
COPY docker/local/php/xdebug.ini      /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /var/www/app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var/cache var/log var/share var/sessions \
    && chown -R www-data:www-data var/

COPY --chmod=0755 <<'EOF' /usr/local/bin/docker-entrypoint.sh
#!/bin/bash
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
EOF

ENTRYPOINT ["docker-entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
