# syntax=docker/dockerfile:1
#
# Production PHP-FPM image — two-stage, slim.
#   Stage 1 (build):   resolve Composer deps with an optimised, dev-free autoloader.
#   Stage 2 (runtime): a lean php-fpm-alpine carrying ONLY the runtime extensions,
#                      the app source, and vendor/. No Composer, Node, Git or Xdebug.
#
# The frontend is NOT built here — it ships inside the nginx image (see
# docker/production/nginx/Dockerfile). This image only needs public/index.php.

ARG PHP_VERSION=8.4

# ── Stage 1: Composer dependencies ──────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install deps against the lockfile first (better layer caching), without
# running scripts or building the autoloader (no source present yet).
# Platform extensions (intl, sodium, …) aren't installed in this slim build
# stage — they exist in the runtime stage where the code actually runs — so the
# platform check is skipped here.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --prefer-dist --no-scripts --no-autoloader \
        --no-progress --no-interaction --ignore-platform-reqs

# Bring in the source, then build the optimised, authoritative classmap.
# --classmap-authoritative skips filesystem lookups for unknown classes — the
# fastest autoloader, safe because prod code never generates classes at runtime.
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && rm -rf var/cache/* var/log/*

# ── Stage 2: runtime ────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

# Runtime shared libs the extensions link against. Build deps are added in a
# throwaway virtual package so the final image stays small.
RUN apk add --no-cache \
        bash \
        fcgi \
        libpq \
        libzip \
        libsodium \
        icu-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpq-dev \
        libzip-dev \
        libsodium-dev \
        icu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        sodium \
        zip \
        bcmath \
    && apk del .build-deps

# Production PHP + FPM configuration (no Xdebug, opcache locked down).
COPY docker/production/php/php.ini       /usr/local/etc/php/conf.d/app.ini
COPY docker/production/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/app

# App + vendor from the build stage. The source has already been validated and
# the autoloader optimised; nothing is installed at runtime.
COPY --from=build --chown=www-data:www-data /app /var/www/app

RUN mkdir -p var/cache var/log var/share var/sessions \
    && chown -R www-data:www-data var/

COPY --chmod=0755 docker/production/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
