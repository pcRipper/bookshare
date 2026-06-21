# syntax=docker/dockerfile:1
#
# Production nginx image — multi-stage.
#   Stage 1 (brotli-builder): compile the ngx_brotli dynamic modules.
#   Stage 2 (assets):         build the Vue SPA → public/build (precompressed).
#   Stage 3 (runtime):        nginx + brotli modules + the built public/ baked in.
#
# Build context is the repo root (the assets stage needs the frontend source).

ARG NGINX_VERSION=1.27
ARG NODE_VERSION=22

# ── Stage 1: compile ngx_brotli against this exact nginx version ────────────
FROM nginx:${NGINX_VERSION}-alpine AS brotli-builder

RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        gcc g++ make cmake git libc-dev \
        pcre2-dev zlib-dev openssl-dev linux-headers brotli-dev; \
    NGINX_FULL="$(nginx -V 2>&1 | sed -n 's|.*nginx/\([0-9.]*\).*|\1|p')"; \
    cd /tmp; \
    wget -q "https://nginx.org/download/nginx-${NGINX_FULL}.tar.gz"; \
    tar -xf "nginx-${NGINX_FULL}.tar.gz"; \
    git clone --depth 1 --recurse-submodules https://github.com/google/ngx_brotli.git; \
    cd "/tmp/nginx-${NGINX_FULL}"; \
    ./configure --with-compat --add-dynamic-module=/tmp/ngx_brotli; \
    make -j"$(nproc)" modules; \
    cp objs/ngx_http_brotli_filter_module.so \
       objs/ngx_http_brotli_static_module.so \
       /usr/lib/nginx/modules/

# ── Stage 2: build the frontend (shipped built) ─────────────────────────────
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app

# Install deps against the lockfile first for layer caching.
COPY package.json package-lock.json ./
RUN npm ci

# Source needed for `vite build`. publicDir is disabled in vite.config, so the
# repo public/ (incl. index.php) is carried through untouched while the build
# emits public/build/ with precompressed .br/.gz assets and a rewritten
# index.html (base '/build/').
COPY vite.config.js index.html ./
COPY assets ./assets
COPY public ./public
RUN npm run build

# ── Stage 3: runtime ────────────────────────────────────────────────────────
FROM nginx:${NGINX_VERSION}-alpine

# Shared brotli libs the dynamic modules link against at runtime.
RUN apk add --no-cache brotli-libs openssl

COPY --from=brotli-builder \
     /usr/lib/nginx/modules/ngx_http_brotli_filter_module.so \
     /usr/lib/nginx/modules/ngx_http_brotli_static_module.so \
     /usr/lib/nginx/modules/

# nginx config (main + site).
COPY docker/production/nginx/nginx.conf            /etc/nginx/nginx.conf
COPY docker/production/nginx/conf.d/default.conf   /etc/nginx/conf.d/default.conf

# Self-signed fallback cert generator (runs before nginx via the official
# entrypoint) so the container boots before the first Let's Encrypt issuance.
COPY --chmod=0755 docker/production/nginx/docker-entrypoint.d/init-certs.sh \
     /docker-entrypoint.d/10-init-certs.sh

# Built SPA + Symfony front controller. nginx needs public/index.php present so
# $realpath_root resolves; php-fpm carries the matching file at the same path.
COPY --from=assets /app/public /var/www/app/public

EXPOSE 80 443
