#!/usr/bin/env bash
#
# Deploy / redeploy on the droplet: pull the latest branch + images and bring
# the stack up. Migrations run automatically via the phpfpm entrypoint.
#
#   bash scripts/deploy.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE="${ENV_FILE:-.env.prod.local}"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f compose.prod.yaml)

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing $ENV_FILE — copy .env.prod.local.example and fill in the secrets." >&2
    exit 1
fi

echo "==> Pulling latest code…"
git pull --ff-only

echo "==> Pulling images from the registry…"
"${COMPOSE[@]}" pull

echo "==> Starting the stack…"
"${COMPOSE[@]}" up -d --remove-orphans

echo "==> Pruning dangling images…"
docker image prune -f

echo "==> Status:"
"${COMPOSE[@]}" ps
