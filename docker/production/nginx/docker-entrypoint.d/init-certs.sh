#!/bin/sh
# Runs via the official nginx entrypoint before nginx starts.
# Generates a self-signed certificate at the fixed cert path ONLY when no cert
# is present yet — so the container boots before the first Let's Encrypt
# issuance. Once certbot writes the real fullchain.pem / privkey.pem here (via
# its --deploy-hook), this is a no-op.

CERT_DIR=/etc/nginx/certs

[ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ] && exit 0

echo "==> No TLS certificate found — generating a temporary self-signed pair…"
mkdir -p "$CERT_DIR"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$CERT_DIR/privkey.pem" \
    -out    "$CERT_DIR/fullchain.pem" \
    -subj   "/CN=localhost" 2>/dev/null
echo "==> Self-signed certificate written to $CERT_DIR/ (replace with Let's Encrypt — see DEPLOY.md)"
