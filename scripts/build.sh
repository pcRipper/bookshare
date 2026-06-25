#!/usr/bin/env bash
#
# Build the production images locally on the server from the current checkout.
# There is no registry — nothing is pushed; the built images are tagged
# bookshare-{php,nginx}:${IMAGE_TAG:-latest} and consumed straight from the
# local Docker image store by compose.prod.yaml.
#
#   bash scripts/build.sh
#   IMAGE_TAG=v1.2.3 bash scripts/build.sh
#
# IMAGE_TAG and image versions are read from the default .env (Docker Compose
# auto-loads it) or the environment.
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Config (incl. IMAGE_TAG, image versions) comes from the default .env, which
# Docker Compose auto-loads for ${...} substitution.
echo "==> Building production images…"
docker compose -f compose.prod.yaml build

echo "==> Done. Bring the stack up with: make prod-up   (or full deploy: make prod-deploy)"
