#!/usr/bin/env bash
#
# Rebuild & redeploy the production stack from the latest code.
# Run on the server (from anywhere):  bash scripts/rebuild-prod.sh
#
# Order matters:
#   down → stash → pull → stash apply → up --build
#   Stashing BEFORE the pull is what lets `git pull --ff-only` succeed even
#   though the tracked .env is edited in place on the server (the local edits
#   are tucked away, the tree is clean for the fast-forward, then re-applied).
#   The frontend is built inside the nginx image (vite build — see
#   docker/production/nginx/Dockerfile stage 2), so `up --build` produces it;
#   there is no separate host build step.
#
set -euo pipefail

# Operate from the repo root so compose + npm paths resolve no matter where the
# script is invoked from.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

COMPOSE="docker compose -f compose.prod.yaml"

# Credentials for the non-interactive HTTPS pull. Override at call time:
#   GIT_PASSWORD=... GIT_USERNAME=... bash scripts/rebuild-prod.sh
GIT_PASSWORD="${GIT_PASSWORD:-user202}"
GIT_USERNAME="${GIT_USERNAME:-}"

echo "==> [1/5] Stopping the production stack"
$COMPOSE down

echo "==> [2/5] Stashing local server changes"
stashed=0
if ! git diff --quiet || ! git diff --cached --quiet; then
  git stash push -m "rebuild-prod: server-local changes"
  stashed=1
else
  echo "    working tree clean — nothing to stash"
fi

echo "==> [3/5] Pulling the latest code"
# Feed the password (and optionally username) to git without an interactive
# prompt via a throwaway GIT_ASKPASS helper that echoes the env values.
askpass="$(mktemp)"
trap 'rm -f "$askpass"' EXIT
cat >"$askpass" <<'ASKPASS'
#!/bin/sh
case "$1" in
  Username*) printf '%s\n' "$GIT_USERNAME" ;;
  *)         printf '%s\n' "$GIT_PASSWORD" ;;
esac
ASKPASS
chmod +x "$askpass"
GIT_PASSWORD="$GIT_PASSWORD" GIT_USERNAME="$GIT_USERNAME" \
  GIT_ASKPASS="$askpass" GIT_TERMINAL_PROMPT=0 \
  git pull --ff-only

echo "==> [4/5] Re-applying stashed local changes"
if [ "$stashed" -eq 1 ]; then
  # apply (not pop): keep the stash as a backup in case the re-apply conflicts.
  git stash apply
else
  echo "    nothing to re-apply"
fi

echo "==> [5/5] Rebuilding images and starting the stack"
$COMPOSE up -d --build

echo "==> Done."
