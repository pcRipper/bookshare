#!/usr/bin/env bash
#
# Build the production images and push them to the registry.
# Run locally or in CI (NOT on the droplet — the droplet only pulls).
#
#   REGISTRY=registry.example.com/acme IMAGE_TAG=v1.2.3 bash scripts/build-push.sh
#
# Reads REGISTRY / IMAGE_TAG from the environment or .env.prod.local.
#
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE="${ENV_FILE:-.env.prod.local}"
[ -f "$ENV_FILE" ] && set -a && . "./$ENV_FILE" && set +a

: "${REGISTRY:?set REGISTRY (e.g. registry.example.com/acme) in env or .env.prod.local}"
IMAGE_TAG="${IMAGE_TAG:-latest}"

echo "==> Building images ${REGISTRY}/bookshare-{php,nginx}:${IMAGE_TAG}…"
docker compose --env-file "$ENV_FILE" -f compose.prod.yaml build

echo "==> Pushing to ${REGISTRY}…"
docker compose --env-file "$ENV_FILE" -f compose.prod.yaml push

echo "==> Done. On the droplet run: make prod-deploy"
