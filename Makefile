.PHONY: docker-start docker-stop \
        prod-build prod-up prod-down prod-deploy prod-deploy-front prod-logs prod-migrate

# ── Local development ────────────────────────────────────────────────────────
docker-start:
	docker compose up -d

docker-stop:
	docker compose down

# ── Production (compose.prod.yaml — config from the default .env) ─────────────
PROD := docker compose -f compose.prod.yaml

# Build the optimized images locally on the server (no registry, no push).
prod-build:
	bash scripts/build.sh

prod-up:
	$(PROD) up -d --remove-orphans

prod-down:
	$(PROD) down

# Full redeploy on the server: git pull + build images + up (migrations auto-run).
prod-deploy:
	bash scripts/deploy.sh

# SPA-only redeploy: rebuild just the nginx image (which bakes the built Vue
# app) and recreate that container. The rest of the stack keeps serving — no
# downtime, no migration run. Refuses if the pull also changed backend code.
prod-deploy-front:
	bash scripts/rebuild-prod.sh --frontend-only

prod-logs:
	$(PROD) logs -f --tail=100

# Manually run migrations against the running stack.
prod-migrate:
	$(PROD) exec phpfpm php bin/console doctrine:migrations:migrate --no-interaction
