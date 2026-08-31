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
| `make prod-deploy-front` | SPA-only: pull + rebuild the nginx image + recreate that one container (stack stays up) |

## First-time setup

### 1. Provision the droplet (Ubuntu)

```bash
sudo bash scripts/provision-droplet.sh
```

Installs Docker Engine + compose plugin, git, certbot, ufw (OpenSSH/80/443), and a 2 GB swap file.
Log out/in afterward so the docker group applies.

### 2. Clone the repo + configure `.env`

There is **no separate prod env file**. The committed `.env` is the single source of config —
`compose.prod.yaml` mounts it read-only into the container for Symfony to read, and Docker Compose
also reads it for `${...}` substitution. Edit it in place:

```bash
git clone <repo-url> bookshare && cd bookshare
$EDITOR .env            # set APP_ENV=prod; fill APP_SECRET, POSTGRES_PASSWORD, JWT_PASSPHRASE,
                        # CORS_ALLOW_ORIGIN, GOOGLE_*, DEFAULT_URI for production
```

Generate `APP_SECRET`: `php -r 'echo bin2hex(random_bytes(16)), "\n";'` (or `openssl rand -hex 16`).

> **Config is mounted, not baked in.** `.env` is `.dockerignore`d (kept out of the image) and
> mounted read-only into the phpfpm container by `compose.prod.yaml`, so secrets never enter the
> image layers. Changing a value takes effect on a restart — no rebuild needed
> (`docker compose -f compose.prod.yaml restart phpfpm`). The DB host (`postgresql` service) and
> `RUN_MIGRATIONS` are the only settings overridden at the compose layer; everything else comes
> from `.env`.

> **Secrets live in a committed file.** `.env` is tracked, so prod secrets placed there are committed
> to git (the image is now clean, but git history is not). For a single-tenant hobby deploy that's the
> tradeoff of "one `.env`". Since `.env` is only mounted (never baked), an alternative is to keep
> placeholders in the tracked `.env` and maintain the real values in an untracked `.env.local` on the
> server (Symfony reads it with higher precedence than `.env`) — mount that too, or point the mount at
> it. Note `git pull --ff-only` in `deploy.sh` will balk if you edit the tracked `.env` in place on
> the server, so the placeholders-plus-`.env.local` split also avoids that snag.

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

### Frontend-only

```bash
make prod-deploy-front          # = bash scripts/rebuild-prod.sh --frontend-only
```

The built SPA is baked into the **nginx** image (`vite build`, stage 2 of its Dockerfile), so an
SPA-only release is a one-image job. This path never stops the stack: `phpfpm`, `postgresql` and
`mercure` keep serving — no downtime, no migration run, no dropped SSE connections — while nginx
alone is rebuilt and recreated (`up -d --no-deps nginx`).

It **refuses** if the pull also changed files the nginx image isn't built from, because shipping
nginx alone would leave PHP-FPM on the previous commit — a half-deployed release that looks like it
worked. The allowed set is a whitelist (`assets/`, `index.html`, `vite.config.js`, `package*.json`,
`docker/production/nginx/`, plus markdown), so an unfamiliar path fails into "use the full rebuild"
rather than shipping half of one. `--force` overrides it.

## Post-deploy smoke check: the public share pages

Run this after any deploy that touched `config/packages/security.yaml`, the nginx
configs, or `PublicRestController`.

The test suite **cannot** cover these: `when@test: security: ~` disables the security
bundle, so nothing in PHPUnit exercises the firewall. Four curls close that gap.
Substitute a real non-private user id, and one that is private.

```bash
BASE=https://your-host

# 1. The rest of the API is still gated.
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/books                      # ⇒ 401

# 2. A shared library is readable with no credentials.
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/public/users/{id}          # ⇒ 200

# 3. …and stays readable when the caller offers a stale token. This is the
#    regression that matters: if /api/public ever falls back under the
#    authenticating firewall, this returns 401 and every member with an expired
#    session gets bounced to /login when they open a shared link.
curl -s -o /dev/null -w '%{http_code}\n' \
     -H 'Authorization: Bearer garbage' $BASE/api/public/users/{id}           # ⇒ 200

# 4. A private member is indistinguishable from one who doesn't exist.
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/public/users/{private-id}  # ⇒ 404
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/public/users/99999999      # ⇒ 404
```

## Post-deploy smoke check: the admin gate

Run this after any deploy that touched `config/packages/security.yaml`, `User`,
or a controller under `/api/admin`.

Same blind spot, same reason: `when@test: security: ~` means no test in the suite
can make an authenticated request, so `AdminAccessConfigTest` can only assert
that the rule is present, anchored and correctly ordered — never that it fires.
This is exactly how a stale container cache once turned the dashboard into a 500
that the whole green suite had no way to see.

Mint the two tokens with `php bin/console lexik:jwt:generate-token <email>`
(pass `--no-ansi` and strip whitespace, or the colour codes corrupt the header).

```bash
BASE=https://your-host

# 1. Gated against anonymous callers.
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/admin/stats                # ⇒ 401

# 2. An ordinary member is refused — the rule fires, rather than merely existing.
curl -s -o /dev/null -w '%{http_code}\n' \
     -H "Authorization: Bearer $MEMBER_JWT" $BASE/api/admin/stats             # ⇒ 403

# 3. …with a translated body, not Symfony's untranslated "Access Denied.".
curl -s -H "Authorization: Bearer $MEMBER_JWT" \
     -H 'Accept-Language: de' $BASE/api/admin/stats   # ⇒ {"error":"Administratorzugriff ist erforderlich."}

# 4. An administrator gets the dashboard.
curl -s -o /dev/null -w '%{http_code}\n' \
     -H "Authorization: Bearer $ADMIN_JWT" $BASE/api/admin/stats              # ⇒ 200

# 5. The traffic beacon accepts a known page and rejects anything else.
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' \
     -d '{"route":"public-library"}' $BASE/api/public/pageviews               # ⇒ 204
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' \
     -d '{"route":"../../etc"}' $BASE/api/public/pageviews                    # ⇒ 422
```

Grant the first administrator on the droplet with:

```bash
docker compose exec phpfpm php bin/console app:grant-admin you@example.com
```

## Mail

Transactional mail (loan lifecycle, welcome, new follower, due/overdue reminders) is
**queued in PostgreSQL and delivered by the `messenger-worker` container**, so no API
response ever waits on an SMTP round-trip. Nothing is sent until that worker is up:
a queued mail with no consumer is indistinguishable from no mail at all.

### Provider: Brevo (free tier)

Roughly 2–3k mails/month is the expected volume. Of the free tiers that survive it,
only two have real headroom — **Brevo** (9,000/month, **300/day**) and Mailjet
(6,000/month, 200/day). Resend's 100/day makes a burst impossible, MailerSend dropped
to 500/month with a card requirement, and SES closed its free tier to new accounts in
July 2026. Brevo it is; **Mailjet is a drop-in fallback — only the DSN changes.**

The binding limit is the **daily** one: a completed loan costs 4 mails (+1 if it nears
its due date), so 300/day is about 60 loans a day.

1. Create the Brevo account, then **Senders, Domains & Dedicated IPs → Domains** and add
   the sending domain. Publish the DKIM/SPF records it gives you; an unauthenticated
   domain gets filed as spam regardless of what the relay accepts.
2. **SMTP & API → SMTP** gives a login and an SMTP key.
3. Fill in `.env` on the droplet:

```dotenv
MAILER_DSN=smtp://<brevo-login>:<brevo-smtp-key>@smtp-relay.brevo.com:587
MAILER_FROM="FolioShare <noreply@your-domain>"
MAILER_SENDER=noreply@your-domain
# Mails are rendered in a worker, which has no request context — every link in
# every mail is built from this, so a wrong value ships broken links.
DEFAULT_URI=https://your-domain
```

`.env` is mounted, not baked in, so a credential change needs a restart rather than a
rebuild: `docker compose -f compose.prod.yaml restart phpfpm messenger-worker`.

### Smoke check after a deploy

```bash
PROD="docker compose -f compose.prod.yaml"

# 1. The worker is consuming (not just running).
$PROD logs --tail=20 messenger-worker | grep 'Consuming messages'

# 2. Nothing is stuck: `async` should drain to ~0, `failed` should stay empty.
$PROD exec phpfpm php bin/console messenger:stats

# 3. Anything that did fail is inspectable, and retryable, rather than lost.
$PROD exec phpfpm php bin/console messenger:failed:show

# 4. The relay actually accepts our credentials. Sends one real mail — use your
#    own address.
$PROD exec phpfpm php bin/console mailer:test you@example.com
```

If a mail never arrives, the order to check is: `messenger:stats` (queued but not
consumed ⇒ worker), `messenger:failed:show` (consumed but rejected ⇒ credentials, or an
unverified sender domain), then the container log's `mail` channel — it carries one
record per send **and per deliberate skip**, which is how an opt-out is told from a
failure:

```bash
$PROD logs phpfpm | grep '"channel":"mail"'
```

### Daily reminders (cron)

The one mail no user action triggers. Add it to the droplet's crontab — it is idempotent
(each loan records when each reminder went out), so a double run mails once:

```cron
# Loan reminders: due tomorrow + overdue. 08:00 server time.
0 8 * * * cd /path/to/bookshare && docker compose -f compose.prod.yaml exec -T phpfpm php bin/console app:send-loan-reminders >> /var/log/bookshare-reminders.log 2>&1
```

Dry-run it first: `... app:send-loan-reminders --dry-run` lists what it would send and
records nothing.

## Make targets

| Target | Purpose |
|---|---|
| `make prod-build` | Build the images locally on the server |
| `make prod-up` / `prod-down` | Start / stop the stack |
| `make prod-deploy` | Full redeploy (pull code + build + up) |
| `make prod-deploy-front` | SPA-only redeploy (nginx image only, no downtime) |
| `make prod-logs` | Tail logs |
| `make prod-migrate` | Run migrations manually |

## Notes

- **PostgreSQL** publishes no host port — it is reachable only on the internal `app` network.
- **Uploads** (`var/share`) persist in the `app_uploads` named volume across deploys.
- **OPcache** runs with `validate_timestamps=0` (code is immutable in the image); a redeploy
  replaces the container, so there is nothing to invalidate.
- **Mail** is queued in PostgreSQL (`messenger_messages`) and sent by the `messenger-worker`
  container. It recycles itself hourly / at 96 MB; `restart: always` turns the clean exit into a
  fresh process. `RUN_MIGRATIONS` is pinned off on it — migrations belong to exactly one container.
- Excluded from production by design: Mailpit, Prometheus/Alloy/Grafana, Xdebug.
