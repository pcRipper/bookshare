# FolioShare

> A community book-sharing platform — catalog your physical books, lend them to other
> members, track every borrow request through its full lifecycle, and discover what your
> community is reading.

**FolioShare** is the product/brand name; **Bookshare** is the repository name. It's a
single-page Vue app talking to a Symfony JSON API, with real-time updates over Server-Sent
Events.

> 🤖 **Written and reviewed by AI.** Every part of this project — backend, frontend,
> infrastructure, tests, and documentation — was authored and reviewed by AI (Claude) under
> human direction. See [Authorship](#authorship) for the full statement.

---

## Table of contents

- [Overview & features](#overview--features)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Project structure](#project-structure)
- [Local development](#local-development)
- [Production deployment](#production-deployment)
- [Real-time notifications](#real-time-notifications)
- [Testing](#testing)
- [Conventions](#conventions)
- [Authorship](#authorship)
- [License](#license)

---

## Overview & features

FolioShare lets readers run a shared, lendable library within a community.

**Library & catalog**
- Personal **collection** of physical books — title, author, ISBN, cover, language
  (ISO 639-1), and a search-or-create **category** system with a curated colour palette.
- **CSV import / export** of a collection — round-trips title, author, ISBN, cover, language,
  status and categories, with append/replace modes, skip/abort error handling, and
  idempotent de-duplication on title + author.

**Lending lifecycle** — a guarded state machine:

```
requester creates ──▶ pending
owner approve(dueDate) ──▶ approved   (book is lent, holder = requester, due date set)
owner decline ──▶ declined
requester requestReturn ──▶ return_pending
owner confirmReturn ──▶ returned   (book is home again, holder = owner)
```

- Only the **owner** may approve / decline / confirm-return; only the **requester** may
  request a return. You can't borrow your own book, an unavailable book, from a private
  library, or file a duplicate request.
- The **due date is set unilaterally by the lender** at approval.
- Every transition appends to an append-only **event timeline** and is **audited**.
- A book out on loan is **frozen** — its details can't be edited until it returns home.

**Discovery & profiles**
- **Discover** community books with search, category pills, and a language filter; browse
  trending / recommended grids and other members' accounts.
- Public **profiles** with avatar, bio, stats and a borrowable collection.
- **Privacy toggle** — a private profile hides its collection and details from others.

**Real-time**
- Live toast notifications + auto-refreshing lists the moment a request is made, approved,
  declined, or returned — no page reload. See [Real-time notifications](#real-time-notifications).

**Authentication**
- Sign-in is **Google OAuth only**, exchanged server-side for a **JWT** the SPA stores and
  sends as a Bearer token.

---

## Tech stack

**Backend** — Symfony **7.4** LTS on **PHP 8.4**, PostgreSQL **16** via Doctrine ORM 3.

| Concern | Package |
|---|---|
| Framework | `symfony/framework-bundle` 7.4 (+ console, dotenv, flex, runtime) |
| ORM / DB | `doctrine/orm` ^3.6, `doctrine-bundle`, `doctrine-migrations-bundle` |
| Auth | `lexik/jwt-authentication-bundle`, `symfony/security-bundle` |
| HTTP / serialization | `symfony/serializer`, `validator`, `property-access/-info`, `http-client` |
| CORS | `nelmio/cors-bundle` |
| Rate limiting | `symfony/rate-limiter` |
| Audit | `damienharper/auditor-bundle` |
| Real-time | `symfony/mercure-bundle` (SSE via a standalone Mercure hub) |
| Test | `phpunit/phpunit` ^13 |

**Frontend** — Vue 3 SPA, plain JS (no TypeScript), Composition API throughout.

| Concern | Package |
|---|---|
| Core | `vue` ^3.5, `vue-router` ^4.5 (history mode), `pinia` ^3 |
| HTTP | `axios` (single configured instance) |
| Build | `vite` ^6, `@vitejs/plugin-vue`, brotli/gzip precompression |

**Infrastructure** — Docker Compose (php-fpm, nginx, PostgreSQL, **Mercure** hub), nginx as
TLS terminator + static/SPA server, Let's Encrypt for prod certs.

---

## Architecture

A **monorepo**: the Symfony project *is* the repo root, with the Vue source under
`assets/src/`. Frontend and backend are coupled by directory but decoupled at runtime — they
talk **only** through a JSON REST API under the `/api` prefix.

**Request flow**

- **Dev:** browser → Vite (`:5173`) for SPA assets; `fetch('/api/…')` → Vite proxy → Symfony.
- **Prod:** nginx serves the built SPA (`public/build/`) and proxies `/api` to PHP-FPM.
- **SSE (both):** `EventSource('/.well-known/mercure')` → nginx/Vite proxy → the **Mercure
  hub** (a standalone Go container) — long-lived connections never touch PHP-FPM.

**Key design choices**

- **Single flush boundary:** repositories/services `persist()` and mutate, but the controller
  owns the transaction and `flush()`es exactly once per request.
- **Single sources of truth:** the category colour palette and the book-language vocabulary
  each live in one backend class, surfaced to the SPA via the API (never duplicated in JS).
- **Authorization in the API:** a voter gates book edit/delete; private profiles 403 to
  others. The SPA mirrors these via flags the API emits (e.g. `canEdit`), never re-deriving
  rules client-side.

---

## Project structure

```
bookshare/
├── assets/src/          # Vue 3 SPA (api, router, stores, views, components, composables, utils)
├── src/                 # Symfony PHP (Controller, Entity, Enum, Repository, Service, Dto, …)
├── config/              # Bundle config + routes + jwt keypair (gitignored)
├── migrations/          # Doctrine migrations
├── tests/               # PHPUnit suite (unit-level)
├── docker/
│   ├── local/           # dev stack (Xdebug, Mailpit, Grafana)
│   └── production/       # slim two-stage php-fpm + nginx (frontend built into the image)
├── compose.yaml         # local stack
├── compose.prod.yaml    # production stack
├── Makefile             # docker-start / prod-deploy / …
└── vite.config.js
```

---

## Local development

The full stack runs in Docker (PHP-FPM, nginx, PostgreSQL, **Mercure**). The Vue dev server
runs on the host for hot-module reload.

### Prerequisites

- Docker + Docker Compose
- Node.js (for the Vite dev server)
- Google OAuth credentials (client id/secret) for sign-in

### Setup

```bash
# 1. Configure environment (copy/edit defaults; never commit real secrets)
cp .env .env.local        # set GOOGLE_CLIENT_ID/SECRET, JWT_PASSPHRASE, DB creds, MERCURE_*

# 2. Generate the JWT keypair (once)
bash scripts/generate-jwt-keys.sh     # or see DEPLOY.md for the manual openssl commands

# 3. Bring up the backend stack (php-fpm, nginx, postgres, mercure)
make docker-start                     # docker compose up -d

# 4. Database
docker compose exec phpfpm php bin/console doctrine:migrations:migrate
docker compose exec phpfpm php bin/console doctrine:fixtures:load   # optional dev seed data

# 5. Frontend dev server (hot reload)
npm install
npm run dev                           # Vite on http://localhost:5173
```

> After editing `.env`, run `composer dump-env dev` — Symfony reads the compiled
> `.env.local.php` in dev and ignores `.env` otherwise.

### Useful commands

```bash
make docker-start / docker-stop       # start / stop the local stack
npm run dev / build / preview          # Vite dev server / production build / preview
docker compose exec phpfpm php bin/console <cmd>   # Symfony console (migrations, router, …)
docker compose exec phpfpm php bin/phpunit         # run the test suite
```

> Note: `npm run lint` is currently broken (ESLint v10 needs a flat config the repo doesn't
> yet ship). There is no JS test runner — verify frontend behaviour by building and driving
> the app in a browser.

---

## Production deployment

Production ships as **two slim, two-stage Docker images** (php-fpm + nginx) plus PostgreSQL
and the Mercure hub, orchestrated by `compose.prod.yaml`. Images are **built on the server**
from the git checkout — no registry, nothing pushed or pulled — and the frontend is built
into the nginx image.

```bash
make prod-deploy        # on the server: git pull + build images + up -d (migrations auto-run)
make prod-logs          # tail logs
make prod-migrate       # run migrations manually (if RUN_MIGRATIONS=0)
```

Configuration comes from a single `.env`, **mounted** read-only into the container (so secrets
never enter image layers). nginx boots with a self-signed cert, then Let's Encrypt issues the
real certificate via the ACME http-01 challenge.

📖 **Full first-time setup, certificates, and notes:** see **[DEPLOY.md](DEPLOY.md)**.

For Mercure specifically in prod: set a strong `MERCURE_JWT_SECRET`, keep `MERCURE_PUBLIC_URL`
relative (`/.well-known/mercure`) so the subscribe-cookie follows the serving domain, and set
`MERCURE_CORS_ORIGINS` to your public origin.

---

## Real-time notifications

Loan-lifecycle changes are pushed to clients over **Server-Sent Events** through a standalone
**Mercure hub** — long-lived connections live on the Go hub, never on the limited PHP-FPM
worker pool.

The design is **signal-and-refetch, not state-push**:

1. After a request transition commits, the backend publishes a **private**
   `{ reason, requestId }` signal to the affected user's `user/{id}` topic (best-effort — a hub
   outage never fails the transition).
2. The SPA shows a toast and refetches the affected lists through the existing authenticated
   API endpoints — so authorization stays server-side and the channel is reconnect/race-safe
   (a refetch always reads committed state).

| Signal | Recipient |
|---|---|
| `request.received`, `return.requested` | book **owner** |
| `request.approved`, `request.declined`, `return.confirmed` | **requester** |

Because `EventSource` can't send a Bearer header, the SPA first calls `GET /api/mercure/token`
to receive a short-lived, HttpOnly subscribe-cookie scoped to its own topic; the client
refreshes it and reconnects with backoff, catching up on any signals missed during a gap.

---

## Testing

```bash
docker compose exec phpfpm php bin/phpunit    # or: php bin/phpunit
```

The PHPUnit suite is **unit-level** — it mirrors `src/` (entities, services, DTOs, API
mappers, voters, subscribers) and boots neither the kernel nor a database, so it runs fast.
The config treats deprecations, notices and warnings as failures.

---

## Conventions

Detailed engineering conventions — persistence/flushing, API route layout, the category &
language single-sources-of-truth, authorization voters, rate limiting, the audit trail, the
Mercure real-time design, and frontend UX patterns — are documented for contributors (and AI
agents) in **[CLAUDE.md](CLAUDE.md)**.

---

## Authorship

**This entire project was written and reviewed by AI.** The backend (Symfony API, domain
services, entities), the frontend (Vue SPA), the infrastructure (Docker, nginx, Mercure,
deploy scripts), the tests, and this documentation were all authored and reviewed by AI
(Claude), under human direction. It is intended as a demonstration of AI-assisted full-stack
engineering — read and run it with that in mind.

---

## License

ISC. See `package.json`.
