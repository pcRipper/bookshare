# Production Deployment

Bookshare ships to production as **two optimized, two-stage Docker images** (php-fpm + nginx)
plus PostgreSQL, orchestrated by [`compose.prod.yaml`](compose.prod.yaml). Images are **built
directly on the server** from the git checkout — there is no registry, nothing is pushed or pulled.
The multi-stage Dockerfiles keep the runtime images slim (Composer/Node only live in throwaway build
stages). The frontend is **built into the nginx image** (`vite build`), so no Node/Composer/Xdebug
ship to the runtime images.

```
docker/
├── local/        # dev stack (compose.yaml) — Xdebug, Mailpit, Grafana
└── production/   # prod stack (compose.prod.yaml)
    ├── php/      # two-stage slim php-fpm (no node/composer/xdebug)
    └── nginx/    # brotli + frontend build + TLS termination
```

## Build model

Everything happens on the server. The flow is **`git pull` → `docker compose build` → `up -d`**.

| Command | Does |
|---|---|
| `make prod-build` | Build both images locally, tag `bookshare-{php,nginx}:${IMAGE_TAG}` |
| `make prod-deploy` | `git pull` + `docker compose build` + `up -d` (migrations auto-run) |

## First-time setup

### 1. Provision the droplet (Ubuntu)

```bash
sudo bash scripts/provision-droplet.sh
```

Installs Docker Engine + compose plugin, git, certbot, ufw (OpenSSH/80/443), and a 2 GB swap file.
Log out/in afterward so the docker group applies.

### 2. Clone the repo + configure `.env`

There is **no separate prod env file**. The committed `.env` is the single source of config —
Symfony reads it inside the container (it is baked into the image at build time), and Docker Compose
reads it for `${...}` substitution. Edit it in place:

```bash
git clone <repo-url> bookshare && cd bookshare
$EDITOR .env            # set APP_ENV=prod; fill APP_SECRET, POSTGRES_PASSWORD, JWT_PASSPHRASE,
                        # CORS_ALLOW_ORIGIN, GOOGLE_*, DEFAULT_URI for production
```

Generate `APP_SECRET`: `php -r 'echo bin2hex(random_bytes(16)), "\n";'` (or `openssl rand -hex 16`).

> **Config is baked into the image.** Because `.env` is copied into the image at build time, changing
> any value means rebuilding — which the server-side flow does anyway (`make prod-deploy`, or
> `make prod-build && make prod-up`). The DB host (`postgresql` service) and `RUN_MIGRATIONS` are the
> only settings overridden at the compose layer; everything else comes from `.env`.

> **Secrets live in a committed file.** `.env` is tracked, so prod secrets placed there are committed.
> For a single-tenant hobby deploy that's the tradeoff of "one `.env`". If you'd rather not commit
> them, keep placeholders in `.env` and inject the real values as real environment variables (they win
> over `.env`) — but note `.env.local` is `.dockerignore`d and will **not** be baked in.

### 3. Generate the JWT keypair (once, on the droplet)

The keys are gitignored and mounted read-only into the phpfpm container.

```bash
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem \
    -aes256 -pass pass:"$JWT_PASSPHRASE" -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -passin pass:"$JWT_PASSPHRASE" \
    -pubout -out config/jwt/public.pem
```

(`$JWT_PASSPHRASE` must match the value in `.env`. Or use `bash scripts/generate-jwt-keys.sh`, which
mints a passphrase and the keypair for you, then prints the `JWT_PASSPHRASE` to paste into `.env`.)

### 4. First deploy (boots with a self-signed cert)

```bash
make prod-deploy         # on the server: git pull + build + up
```

This builds both images from the checkout and brings the stack up. nginx boots immediately with a
temporary self-signed cert so the ACME http-01 challenge can be served over HTTP. (Building both
images the first time is the slow step — the 2 GB swap from provisioning keeps it from OOM-ing on
small VPSes.)

### 5. Obtain the real Let's Encrypt certificate

Point your domain's DNS at the droplet first, then:

```bash
mkdir -p var/certbot
sudo certbot certonly --webroot -w "$(pwd)/var/certbot" -d your-domain.com \
    --deploy-hook "cp /etc/letsencrypt/live/your-domain.com/fullchain.pem $(pwd)/docker/production/nginx/certs/ \
                && cp /etc/letsencrypt/live/your-domain.com/privkey.pem  $(pwd)/docker/production/nginx/certs/ \
                && docker compose -f $(pwd)/compose.prod.yaml exec nginx nginx -s reload"
```

The deploy-hook copies the issued cert into the dir mounted at `/etc/nginx/certs` and reloads nginx.
Certbot installs a systemd timer that renews automatically and re-runs the hook.

## Redeploying

```bash
make prod-deploy
```

Pulls the latest branch, rebuilds the images, restarts the stack, and auto-applies pending
migrations (`RUN_MIGRATIONS=1`). Docker layer caching makes rebuilds fast when dependencies are
unchanged. To run migrations manually instead, set `RUN_MIGRATIONS=0` and use `make prod-migrate`.

## Make targets

| Target | Purpose |
|---|---|
| `make prod-build` | Build the images locally on the server |
| `make prod-up` / `prod-down` | Start / stop the stack |
| `make prod-deploy` | Full redeploy (pull code + build + up) |
| `make prod-logs` | Tail logs |
| `make prod-migrate` | Run migrations manually |

## Notes

- **PostgreSQL** publishes no host port — it is reachable only on the internal `app` network.
- **Uploads** (`var/share`) persist in the `app_uploads` named volume across deploys.
- **OPcache** runs with `validate_timestamps=0` (code is immutable in the image); a redeploy
  replaces the container, so there is nothing to invalidate.
- Excluded from production by design: Mailpit, Prometheus/Alloy/Grafana, Xdebug.
