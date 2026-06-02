ARG NGINX_VERSION=1.27
FROM nginx:${NGINX_VERSION}-alpine

RUN apk add --no-cache openssl

COPY docker-entrypoint.d/init-certs.sh /docker-entrypoint.d/01-init-certs.sh
RUN chmod +x /docker-entrypoint.d/01-init-certs.sh
