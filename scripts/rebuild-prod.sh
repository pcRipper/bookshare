#!/usr/bin/env bash
#
# Rebuild & redeploy the production stack from the latest code.
# Run on the server (from anywhere):
#
#   bash scripts/rebuild-prod.sh                    # full rebuild (default)
#   bash scripts/rebuild-prod.sh --frontend-only    # SPA only, stack stays up
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
# --frontend-only exists because that same fact makes an SPA-only release a
# one-image job: nginx bakes the built SPA, so nothing else has to move. The
# stack is never stopped — phpfpm, postgresql and mercure keep serving (no
# downtime, no migration run, no dropped SSE connections) while nginx alone is
# rebuilt and recreated. See the guard after the pull: shipping only nginx when
# the pull also changed backend code would leave PHP-FPM on the old commit — a
# half-deployed release that looks like it worked.
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

frontend_only=0
force=0
while [ $# -gt 0 ]; do
  case "$1" in
    -f|--frontend-only) frontend_only=1 ;;
    --force)            force=1 ;;
    -h|--help)
      cat <<'USAGE'
Rebuild & redeploy the production stack from the latest code.

  bash scripts/rebuild-prod.sh                  Full rebuild: stop the stack,
                                                pull, rebuild every image, up.
  bash scripts/rebuild-prod.sh --frontend-only  Rebuild only the nginx image
                                                (which bakes the SPA) and
                                                recreate that container. The
                                                rest of the stack keeps running
                                                — no downtime, no migrations.
                                                Refuses if the pull also
                                                changed backend code.

Options:
  -f, --frontend-only  As above.
      --force          With --frontend-only: ship anyway when the pull touched
                       backend code. PHP-FPM stays on the old commit.
  -h, --help           This text.

Environment:
  GIT_USERNAME, GIT_PASSWORD   Credentials for the non-interactive HTTPS pull.
USAGE
      exit 0
      ;;
    *) echo "Unknown option: $1 (try --help)" >&2; exit 2 ;;
  esac
  shift
done

# Paths the nginx image is actually built from (Dockerfile stages 2 and 3),
# plus markdown, which no image's behaviour depends on — a docs commit riding
# along with a CSS fix is the common case, and refusing it would only teach the
# operator to reach for --force, which is the guard turned off.
# Otherwise a WHITELIST, deliberately: a path nobody has thought about yet must
# fail into "needs the full rebuild" rather than quietly ship half a release.
FRONTEND_PATHS='^(assets/|index\.html$|vite\.config\.js$|package\.json$|package-lock\.json$|docker/production/nginx/)|\.md$'

if [ "$frontend_only" -eq 1 ]; then
  steps=4
else
  steps=5
fi

step=1
if [ "$frontend_only" -eq 1 ]; then
  echo "==> Frontend-only redeploy — the stack stays up"
else
  echo "==> [$step/$steps] Stopping the production stack"
  $COMPOSE down
  step=$((step + 1))
fi

echo "==> [$step/$steps] Stashing local server changes"
stashed=0
if ! git diff --quiet || ! git diff --cached --quiet; then
  git stash push -m "rebuild-prod: server-local changes"
  stashed=1
else
  echo "    working tree clean — nothing to stash"
fi

step=$((step + 1))
echo "==> [$step/$steps] Pulling the latest code"
before="$(git rev-parse HEAD)"
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

# The guard. Only meaningful on the fast path — the full rebuild ships
# everything anyway.
if [ "$frontend_only" -eq 1 ]; then
  backend_changes="$(git diff --name-only "$before" HEAD | grep -Ev "$FRONTEND_PATHS" || true)"
  if [ -n "$backend_changes" ]; then
    echo
    echo "!!! This pull changed files the nginx image is NOT built from:"
    printf '%s\n' "$backend_changes" | sed 's/^/      /'
    echo
    echo "    Shipping nginx alone would leave PHP-FPM on $(git rev-parse --short "$before")."
    echo "    Run the full rebuild instead:  bash scripts/rebuild-prod.sh"
    if [ "$force" -eq 0 ]; then
      # Put the server's local .env edits back before bailing — the stash is a
      # step of this script, not something the operator should be left holding.
      if [ "$stashed" -eq 1 ]; then
        echo "    (re-applying your stashed local changes first)"
        git stash apply
      fi
      exit 1
    fi
    echo "    --force given — continuing anyway."
  fi
fi

step=$((step + 1))
echo "==> [$step/$steps] Re-applying stashed local changes"
if [ "$stashed" -eq 1 ]; then
  # apply (not pop): keep the stash as a backup in case the re-apply conflicts.
  git stash apply
else
  echo "    nothing to re-apply"
fi

step=$((step + 1))
if [ "$frontend_only" -eq 1 ]; then
  echo "==> [$step/$steps] Rebuilding nginx (SPA) and recreating that container"
  $COMPOSE build nginx
  # --no-deps: without it compose follows nginx's depends_on and recreates
  # phpfpm too, which is the one thing this path exists to avoid.
  $COMPOSE up -d --no-deps nginx
else
  echo "==> [$step/$steps] Rebuilding images and starting the stack"
  $COMPOSE up -d --build
fi

echo "==> Done."
