.PHONY: docker-start docker-stop \
        prod-build-push prod-pull prod-up prod-down prod-deploy prod-logs prod-migrate

# ── Local development ────────────────────────────────────────────────────────
docker-start:
	docker compose up -d

docker-stop:
	docker compose down

# ── Production (compose.prod.yaml + .env.prod.local) ─────────────────────────
ENV_FILE ?= .env.prod.local
PROD := docker compose --env-file $(ENV_FILE) -f compose.prod.yaml

# Build + push images to the registry (run locally / in CI, not on the droplet).
prod-build-push:
	bash scripts/build-push.sh

# Pull prebuilt images on the droplet.
prod-pull:
	$(PROD) pull

prod-up:
	$(PROD) up -d --remove-orphans

prod-down:
	$(PROD) down

# Full redeploy on the droplet: git pull + pull images + up (migrations auto-run).
prod-deploy:
	bash scripts/deploy.sh

prod-logs:
	$(PROD) logs -f --tail=100

# Manually run migrations against the running stack.
prod-migrate:
	$(PROD) exec phpfpm php bin/console doctrine:migrations:migrate --no-interaction
