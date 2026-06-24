#!/usr/bin/env bash
#
# Generate the lexik JWT keypair for the PRODUCTION Docker stack.
#
# Why a dedicated script: in prod the keys are NOT generated inside the
# container — compose.prod.yaml mounts ./config/jwt read-only (:ro), so the
# phpfpm container can't write them. They must be created on the host with the
# passphrase that compose injects from .env.prod.local, and owned by the UID the
# container's php-fpm runs as (www-data = 82 on the php:*-fpm-alpine image).
#
# This script:
#   1. reads JWT_PASSPHRASE from .env.prod.local (generates + writes one if blank)
#   2. generates config/jwt/{private,public}.pem encrypted with that passphrase
#   3. sets ownership/permissions so the in-container www-data (UID 82) can read
#   4. verifies the key actually opens with the passphrase
#
# Run on the DROPLET, from anywhere:
#   bash scripts/generate-jwt-keys.sh            # create (refuses to clobber)
#   bash scripts/generate-jwt-keys.sh --force    # overwrite existing keys
#
# NOTE: overwriting the keypair invalidates every issued JWT — all signed-in
# users get bounced to /login on their next request. Expected; just be aware.
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE="${ENV_FILE:-.env.prod.local}"
JWT_DIR="config/jwt"
PRIVATE="$JWT_DIR/private.pem"
PUBLIC="$JWT_DIR/public.pem"
KEY_BITS="${KEY_BITS:-4096}"          # matches lexik:jwt:generate-keypair default
APP_UID="${APP_UID:-82}"              # www-data inside php:*-fpm-alpine
APP_GID="${APP_GID:-82}"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m    %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

command -v openssl >/dev/null 2>&1 || die "openssl not found on PATH."
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found. Copy .env.prod.local.example first."

# ── Refuse to silently clobber existing keys ────────────────────────────────
if { [ -f "$PRIVATE" ] || [ -f "$PUBLIC" ]; } && [ "$FORCE" -ne 1 ]; then
    die "Keys already exist in $JWT_DIR. Re-run with --force to overwrite (this invalidates all issued tokens)."
fi

mkdir -p "$JWT_DIR"

# ── Resolve the passphrase from .env.prod.local (the source of truth) ────────
# compose.prod.yaml passes this exact value into the container as JWT_PASSPHRASE,
# so the key MUST be encrypted with it.
read_passphrase() {
    grep -E '^JWT_PASSPHRASE=' "$ENV_FILE" | tail -1 | cut -d= -f2- \
        | sed -e 's/^["'\'']//' -e 's/["'\'']$//' -e 's/[[:space:]]*$//'
}

PASSPHRASE="$(read_passphrase)"

if [ -z "$PASSPHRASE" ]; then
    log "JWT_PASSPHRASE is blank in $ENV_FILE — generating one and writing it back."
    PASSPHRASE="$(openssl rand -hex 32)"
    if grep -qE '^JWT_PASSPHRASE=' "$ENV_FILE"; then
        # replace the empty line in place (| as sed delimiter; hex has no |)
        sed -i "s|^JWT_PASSPHRASE=.*|JWT_PASSPHRASE=${PASSPHRASE}|" "$ENV_FILE"
    else
        printf '\nJWT_PASSPHRASE=%s\n' "$PASSPHRASE" >> "$ENV_FILE"
    fi
    warn "Wrote a fresh JWT_PASSPHRASE to $ENV_FILE."
else
    log "Using existing JWT_PASSPHRASE from $ENV_FILE."
fi

# ── Generate the keypair, encrypted with that passphrase ─────────────────────
log "Generating ${KEY_BITS}-bit RSA private key ($PRIVATE)…"
openssl genpkey -algorithm RSA -pkeyopt "rsa_keygen_bits:${KEY_BITS}" \
    -aes256 -pass "pass:${PASSPHRASE}" -out "$PRIVATE"

log "Deriving public key ($PUBLIC)…"
openssl pkey -in "$PRIVATE" -passin "pass:${PASSPHRASE}" -pubout -out "$PUBLIC"

# ── Ownership + permissions so the container's www-data (UID 82) can read ─────
# Bind-mounted host files keep their numeric owner inside the container.
log "Setting ownership ($APP_UID:$APP_GID) and permissions…"
if chown "$APP_UID:$APP_GID" "$PRIVATE" "$PUBLIC" 2>/dev/null; then
    chmod 640 "$PRIVATE"
    chmod 644 "$PUBLIC"
else
    warn "chown failed (not root?). Falling back to world-readable so the"
    warn "container UID can still read the key on this single-tenant droplet."
    chmod 644 "$PRIVATE" "$PUBLIC"
fi

# ── Prove the key opens with the passphrase compose will inject ──────────────
log "Verifying the key matches the passphrase…"
openssl rsa -in "$PRIVATE" -passin "pass:${PASSPHRASE}" -noout \
    || die "Key did not open with the passphrase — generation is inconsistent."

log "Done. Keys written to $JWT_DIR and verified against $ENV_FILE."
cat <<EOF

Next — apply them to the running stack (recreates phpfpm so the passphrase env
is injected, then mints a test token):

  docker compose --env-file $ENV_FILE -f compose.prod.yaml up -d phpfpm
  docker compose --env-file $ENV_FILE -f compose.prod.yaml exec phpfpm \\
      php bin/console lexik:jwt:generate-token someuser@example.com --no-ansi

If the second command prints a token, signing works.
EOF