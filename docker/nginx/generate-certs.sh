#!/bin/sh
# Generate a self-signed TLS certificate for local development.
# Run once before the first `docker compose up`:
#
#   sh docker/nginx/generate-certs.sh
#
set -e

CERTS_DIR="$(cd "$(dirname "$0")" && pwd)/certs"
mkdir -p "$CERTS_DIR"

openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout "$CERTS_DIR/key.pem" \
  -out    "$CERTS_DIR/cert.pem" \
  -subj   "/CN=localhost" \
  -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"

echo "Done — certificates written to $CERTS_DIR/"
