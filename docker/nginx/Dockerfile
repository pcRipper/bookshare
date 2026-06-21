ARG NGINX_VERSION=1.27

# ── Stage 1: compile the ngx_brotli dynamic modules ─────────────────────────
# Built against this exact nginx version; --with-compat keeps the resulting
# .so files ABI-compatible with the stock binary in the runtime stage.
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

# ── Stage 2: runtime image ──────────────────────────────────────────────────
FROM nginx:${NGINX_VERSION}-alpine

# Shared brotli libs the dynamic modules link against at runtime.
RUN apk add --no-cache brotli-libs

COPY --from=brotli-builder \
     /usr/lib/nginx/modules/ngx_http_brotli_filter_module.so \
     /usr/lib/nginx/modules/ngx_http_brotli_static_module.so \
     /usr/lib/nginx/modules/

# Main config loads the brotli modules (load_module must live in the main
# context) and enables compression; site config stays in conf.d/ (mounted).
COPY nginx.conf /etc/nginx/nginx.conf
