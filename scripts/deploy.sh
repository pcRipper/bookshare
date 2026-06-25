#!/usr/bin/env bash
#
# Deploy / redeploy on the server: pull the latest branch, build the images
# locally from the checkout, and bring the stack up. There is no registry —
# everything is built right here. Migrations run automatically via the phpfpm
# entrypoint.
#
#   bash scripts/deploy.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Config comes from the default .env (compose auto-loads it; the app reads it
# inside the container). Set APP_ENV=prod + production values there.
COMPOSE=(docker compose -f compose.prod.yaml)

echo "==> Pulling latest code…"
git pull --ff-only

echo "==> Building images from the checkout…"
"${COMPOSE[@]}" build

echo "==> Starting the stack…"
"${COMPOSE[@]}" up -d --remove-orphans

echo "==> Pruning dangling images…"
docker image prune -f

echo "==> Status:"
"${COMPOSE[@]}" ps
