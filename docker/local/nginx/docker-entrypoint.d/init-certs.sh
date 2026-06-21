#!/bin/sh
# Executed by the official nginx entrypoint before nginx starts.
# Generates a self-signed certificate when none exists.

CERT_DIR=/etc/nginx/certs

[ -f "$CERT_DIR/cert.pem" ] && [ -f "$CERT_DIR/key.pem" ] && exit 0

echo "==> Generating self-signed TLS certificate for local development..."
mkdir -p "$CERT_DIR"
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout "$CERT_DIR/key.pem" \
    -out    "$CERT_DIR/cert.pem" \
    -subj   "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" 2>/dev/null
echo "==> Certificate written to $CERT_DIR/"
