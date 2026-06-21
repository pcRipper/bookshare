# Production Deployment

Bookshare ships to production as **two optimized, two-stage Docker images** (php-fpm + nginx)
plus PostgreSQL, orchestrated by [`compose.prod.yaml`](compose.prod.yaml). Images are **built and
pushed to a registry**; the droplet only pulls them. The frontend is **built into the nginx image**
(`vite build`), so no Node/Composer/Xdebug ship to production.

```
docker/
├── local/        # dev stack (compose.yaml) — Xdebug, Mailpit, Grafana
└── production/   # prod stack (compose.prod.yaml)
    ├── php/      # two-stage slim php-fpm (no node/composer/xdebug)
    └── nginx/    # brotli + frontend build + TLS termination
```

## Build model

| Where | Command | Does |
|---|---|---|
| Local / CI | `make prod-build-push` | Build both images, tag `${REGISTRY}/bookshare-{php,nginx}:${IMAGE_TAG}`, push |
| Droplet | `make prod-deploy` | `git pull` + `docker compose pull` + `up -d` (migrations auto-run) |

## First-time setup

### 1. Provision the droplet (Ubuntu)

```bash
sudo bash scripts/provision-droplet.sh
```

Installs Docker Engine + compose plugin, git, certbot, ufw (OpenSSH/80/443), and a 2 GB swap file.
Log out/in afterward so the docker group applies.

### 2. Clone the repo + configure secrets

```bash
git clone <repo-url> bookshare && cd bookshare
cp .env.prod.local.example .env.prod.local
$EDITOR .env.prod.local            # fill REGISTRY, APP_SECRET, DB/JWT/Google secrets, CORS, domain
```

Generate `APP_SECRET`: `php -r 'echo bin2hex(random_bytes(16)), "\n";'` (or `openssl rand -hex 16`).

### 3. Generate the JWT keypair (once, on the droplet)

The keys are gitignored and mounted read-only into the phpfpm container.

```bash
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem \
    -aes256 -pass pass:"$JWT_PASSPHRASE" -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -passin pass:"$JWT_PASSPHRASE" \
    -pubout -out config/jwt/public.pem
```

(`$JWT_PASSPHRASE` must match the value in `.env.prod.local`.)

### 4. First deploy (boots with a self-signed cert)

```bash
make prod-build-push     # run locally/CI first, OR build on the droplet if preferred
make prod-deploy         # on the droplet
```

nginx boots immediately with a temporary self-signed cert so the ACME http-01 challenge can be
served over HTTP.

### 5. Obtain the real Let's Encrypt certificate

Point your domain's DNS at the droplet first, then:

```bash
mkdir -p var/certbot
sudo certbot certonly --webroot -w "$(pwd)/var/certbot" -d your-domain.com \
    --deploy-hook "cp /etc/letsencrypt/live/your-domain.com/fullchain.pem $(pwd)/docker/production/nginx/certs/ \
                && cp /etc/letsencrypt/live/your-domain.com/privkey.pem  $(pwd)/docker/production/nginx/certs/ \
                && docker compose --env-file $(pwd)/.env.prod.local -f $(pwd)/compose.prod.yaml exec nginx nginx -s reload"
```

The deploy-hook copies the issued cert into the dir mounted at `/etc/nginx/certs` and reloads nginx.
Certbot installs a systemd timer that renews automatically and re-runs the hook.

## Redeploying

```bash
make prod-deploy
```

Pulls the latest branch + images, restarts the stack, and auto-applies pending migrations
(`RUN_MIGRATIONS=1`). To run migrations manually instead, set `RUN_MIGRATIONS=0` and use
`make prod-migrate`.

## Make targets

| Target | Purpose |
|---|---|
| `make prod-build-push` | Build + push images (local/CI) |
| `make prod-pull` | Pull images on the droplet |
| `make prod-up` / `prod-down` | Start / stop the stack |
| `make prod-deploy` | Full redeploy (pull code + images + up) |
| `make prod-logs` | Tail logs |
| `make prod-migrate` | Run migrations manually |

## Notes

- **PostgreSQL** publishes no host port — it is reachable only on the internal `app` network.
- **Uploads** (`var/share`) persist in the `app_uploads` named volume across deploys.
- **OPcache** runs with `validate_timestamps=0` (code is immutable in the image); a redeploy
  replaces the container, so there is nothing to invalidate.
- Excluded from production by design: Mailpit, Prometheus/Alloy/Grafana, Xdebug.
