# /config — Docker config hygiene

Scan the project's Docker-related files for hardcoded values that should live in a
config or env file, move them there, and update this skill's **Tracked variables**
section. Run after every structural change to any Docker or config file.

---

## Project Docker layout

```
bookshare/
├── Dockerfile                          PHP 8.4-FPM + extensions + Xdebug (build context = repo root)
├── compose.yaml                        All services; monitoring behind profile "logs"
├── compose.override.yaml               Local overrides (e.g. XDEBUG_MODE)
├── .env                                Compose-time substitution vars + Symfony defaults
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile                  Custom nginx — adds openssl, drops init-certs.sh
│   │   ├── conf.d/default.conf         HTTP→HTTPS redirect + SSL server block
│   │   ├── certs/                      gitignored; auto-created by init-certs.sh on first start
│   │   ├── docker-entrypoint.d/
│   │   │   └── init-certs.sh           Generates self-signed cert if certs/ is empty
│   │   └── generate-certs.sh           Manual cert regeneration utility
│   ├── php/
│   │   ├── app.env                     Static phpfpm runtime env (APP_ENV, CORS, MAILER_DSN …)
│   │   ├── php.ini                     PHP settings — values read from container env vars
│   │   ├── xdebug.ini                  Xdebug — activated by XDEBUG_MODE env var
│   │   ├── zz-docker.conf              FPM pool overrides (REPLACES base image file — see gotchas)
│   │   └── docker-entrypoint.sh        Runs composer install if vendor missing, then exec php-fpm
│   ├── monitoring/
│   │   └── grafana.env                 Static Grafana runtime env (GF_AUTH_* vars)
│   ├── alloy/
│   │   └── config.alloy                Alloy collector — scrapes nginx/FPM/PG, writes to Prometheus
│   ├── prometheus/
│   │   └── prometheus.yaml             Prometheus config (accepts remote_write from Alloy)
│   └── grafana/
│       └── provisioning/datasources/
│           └── prometheus.yaml         Auto-provisions Prometheus datasource in Grafana
```

### Startup dependency chain

```
postgresql (healthy) → phpfpm (healthy) → nginx
```

phpfpm healthcheck: FastCGI ping via `cgi-fcgi` (the `fcgi` Alpine package).  
nginx waits for `phpfpm: condition: service_healthy` before starting.

---

## Hard constraints — never remove these

These settings look optional but are **required for Docker operation**. Removing any of
them has caused production-like restart loops in this project:

| File | Setting | Why it must stay |
|---|---|---|
| `docker/php/zz-docker.conf` | `[global] daemonize = no` | Without this, FPM master daemonizes: the foreground PID 1 exits cleanly (code 0), Docker sees the container "stopped" and restart-loops it endlessly. |
| `docker/php/zz-docker.conf` | `[global] error_log = /proc/self/fd/2` | `zz-docker.conf` **replaces** the base image's file of the same name. The base had this setting; without it FPM errors go to a file inside the container and are invisible in `docker compose logs`. |
| `docker/php/zz-docker.conf` | `[www] catch_workers_output = yes` | Same reason — worker stdout/stderr is swallowed without this. |

### Why `zz-docker.conf` is special

The official `php:fpm-alpine` image ships `/usr/local/etc/php-fpm.d/zz-docker.conf`
that sets `daemonize = no`, routing all logs to stderr, and `clear_env = no`.
Our file **completely replaces** it (same filename, later alphabetically wins).
Every setting from the original that we still need must be explicitly included.

---

## The two-tier config pattern

Docker Compose resolves variables in two separate phases:

| Tier | Source | Supports `${VAR}` expansion? |
|---|---|---|
| **Compose-time** — image tags, ports, build args | `.env` at repo root | Yes |
| **Container runtime** — env vars inside the running container | `env_file:` files OR `environment:` key | `env_file` = **No**; `environment:` = Yes |

**Rule 1 — static literals → `env_file:`**  
Plain strings in `environment:` with no `${VAR}` belong in the appropriate `env_file:`.

**Rule 2 — constructed/secret values → `environment:` in `compose.yaml`**  
Anything built from `${VAR}` references (e.g. `DATABASE_URL`, `POSTGRES_DSN`) must stay
in `environment:` — `env_file:` cannot expand variables.

**Rule 3 — tunable knobs → `.env` + `environment: ${VAR:-default}`**  
Image versions, host ports, and PHP tuning go in `.env` and are injected into the
container via `environment: KEY: ${VAR:-default}`.

---

## Canonical file map

| File | Purpose | Variable expansion |
|---|---|---|
| `.env` | Compose-time substitution + Symfony defaults (both read this file) | No (literal) |
| `.env.local` | Machine-local secret overrides — gitignored | No |
| `docker/php/app.env` | Static phpfpm runtime: APP_ENV, CORS, MAILER_DSN, JWT paths, XDEBUG_MODE | No |
| `docker/monitoring/grafana.env` | Static Grafana config: GF_AUTH_* vars | No |
| `compose.yaml environment:` | Secrets + values requiring `${VAR}` expansion at compose time | Yes |

---

## Files to scan

- `Dockerfile`
- `docker/nginx/Dockerfile`
- `compose.yaml`
- `docker/php/app.env`
- `docker/php/php.ini`
- `docker/php/zz-docker.conf`
- `docker/nginx/conf.d/default.conf`
- `docker/alloy/config.alloy`
- `docker/prometheus/prometheus.yaml`
- `docker/monitoring/grafana.env`

---

## What counts as "should be extracted"

Move a value if it is any of:

- A Docker image tag / version pin
- A host port number
- A PHP runtime tuning value (memory, upload size, timeout)
- A hostname, URL, or DSN that differs per environment
- A credential or passphrase
- A static literal inside `compose.yaml environment:` with no `${VAR}` in it

**Do NOT move:**

- Values already written as `${VAR}` or `${VAR:-default}`
- Internal container ports / paths (stable by design, no reason to override)
- Structural directives (nginx keywords, YAML/HCL keys, PHP ini key names)
- Settings listed under **Hard constraints** above

---

## How to apply changes

1. Read each file in the scan list.
2. Identify every hardcoded value matching the extraction rules.
3. For each value not already tracked below:
   a. Place it in the correct file from the canonical file map.
   b. Replace the hardcoded value in the source file with the appropriate reference.
4. Rewrite the **Tracked variables** section of THIS file to reflect current state,
   one entry per line: `- VAR_NAME — file:purpose (default)`.

---

## Tracked variables

### `.env  ###> docker ###` block

| Variable | Used in | Default |
|---|---|---|
| `PHP_VERSION` | `Dockerfile` ARG + `compose.yaml` build arg | `8.4` |
| `NGINX_VERSION` | `docker/nginx/Dockerfile` ARG + `compose.yaml` build arg | `1.27` |
| `POSTGRES_IMAGE` | `compose.yaml` postgresql service | `postgres:16-alpine` |
| `MAILPIT_IMAGE` | `compose.yaml` mailpit service | `axllent/mailpit:latest` |
| `PROMETHEUS_IMAGE` | `compose.yaml` prometheus service | `prom/prometheus:v2.55.1` |
| `ALLOY_IMAGE` | `compose.yaml` alloy service | `grafana/alloy:v1.5.0` |
| `GRAFANA_IMAGE` | `compose.yaml` grafana service | `grafana/grafana:11.4.0` |
| `HTTP_PORT` | `compose.yaml` nginx host port | `8000` |
| `HTTPS_PORT` | `compose.yaml` nginx TLS host port | `8443` |
| `POSTGRES_PORT` | `compose.yaml` postgresql host port | `5432` |
| `MAILPIT_SMTP_PORT` | `compose.yaml` mailpit SMTP host port | `1025` |
| `MAILPIT_UI_PORT` | `compose.yaml` mailpit web UI host port | `8025` |
| `GRAFANA_PORT` | `compose.yaml` grafana host port | `3000` |
| `PHP_MEMORY_LIMIT` | `compose.yaml` environment → `docker/php/php.ini` | `256M` |
| `PHP_UPLOAD_MAX` | `compose.yaml` environment → `docker/php/php.ini` | `50M` |
| `PHP_POST_MAX` | `compose.yaml` environment → `docker/php/php.ini` | `50M` |
| `PHP_MAX_EXEC` | `compose.yaml` environment → `docker/php/php.ini` | `60` |
| `POSTGRES_DB` | `compose.yaml` postgresql env + DATABASE_URL | `bookshare` |
| `POSTGRES_USER` | `compose.yaml` postgresql env + DATABASE_URL | `app` |
| `POSTGRES_PASSWORD` | `compose.yaml` postgresql env + DATABASE_URL | `changeme` |

> Note: nginx has no `NGINX_IMAGE` entry — it is **built** from `docker/nginx/Dockerfile`,
> not pulled. `NGINX_VERSION` is a build arg, not an image reference.

### `docker/php/app.env` — static phpfpm runtime

| Variable | Value | Notes |
|---|---|---|
| `APP_ENV` | `dev` | |
| `JWT_SECRET_KEY` | `/var/www/app/config/jwt/private.pem` | Path only; key content is gitignored |
| `JWT_PUBLIC_KEY` | `/var/www/app/config/jwt/public.pem` | Path only |
| `CORS_ALLOW_ORIGIN` | `'^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$'` | Covers all localhost ports (Vite dev server) |
| `MAILER_DSN` | `smtp://mailpit:1025` | Internal Docker network address |
| `XDEBUG_MODE` | `off` | Override to `develop,debug` in `compose.override.yaml` |

### `docker/monitoring/grafana.env` — static Grafana runtime

| Variable | Value |
|---|---|
| `GF_AUTH_ANONYMOUS_ENABLED` | `true` |
| `GF_AUTH_ANONYMOUS_ORG_ROLE` | `Admin` |
| `GF_SECURITY_DISABLE_INITIAL_ADMIN_CREATION` | `true` |
