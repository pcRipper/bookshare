# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture

Bookshare is a **monorepo** where the Symfony project is the repo root. The frontend and backend are coupled by directory structure but decoupled at runtime — they communicate exclusively through a JSON REST API.

```
bookshare/
├── assets/src/          # Vue 3 SPA source (Composition API, JS)
│   ├── main.js          # App bootstrap — registers router + pinia, mounts #app
│   ├── App.vue          # Root component (<RouterView />)
│   ├── router/          # vue-router (history mode)
│   ├── stores/          # Pinia stores
│   └── components/
├── src/                 # Symfony PHP source (autowired, autoconfigured)
│   ├── Controller/      # API controllers — all routes use #[Route] attributes
│   ├── Entity/          # Doctrine entities — mapped via PHP attributes
│   ├── Repository/      # Doctrine repositories
│   └── DataFixtures/    # Dev seed data (AppFixtures)
├── config/
│   ├── packages/        # Bundle config (doctrine, security, nelmio_cors, lexik_jwt…)
│   └── jwt/             # RSA keypair — gitignored, generated once
├── public/
│   ├── index.php        # Symfony front controller
│   └── build/           # Vite production output — gitignored
├── index.html           # Vite entry point (at repo root)
└── vite.config.js
```

**Request flow in dev:**
- Browser → Vite (`:5173`) for all Vue assets
- `fetch('/api/…')` → Vite proxy → Symfony (`:8000`)
- In prod, Nginx serves both `public/build/` (Vue) and proxies to PHP-FPM (Symfony)

## Product

### Overview
FolioShare is a community book-sharing platform. Readers catalog their physical books, lend them to other community members, track borrow requests, and discover each other's collections. The UI brand name is **FolioShare**; the repo/project name is **Bookshare**.

### Screens & Routes

| Route | Design references | Description |
|---|---|---|
| `/login` | `login`, `login_mobile` | JWT sign-in form; email + password, "Remember me", "Forgot password", social auth links (UI only) |
| `/register` | `create_account`, `create_account_mobile` | Sign-up form: full name, email, password (min 8 chars) |
| `/library` | `my_library`, `my_library_mobile`, `my_library_incoming_requests`, `my_library_requests_tab_added`, `my_library_no_header_search` | Authenticated user's library. Profile header (avatar, name, bio, stats). Four tabs: **Collection** (book grid), **Lending** (lent-out books), **Requests** (incoming borrow requests — Approve / Decline actions), **History** |
| `/discover` | `discover_mobile`, `discover_search` | Browse the community. Search bar, category filter pills, "Trending Near You" + "Recommended for You" grids, "Curator Choice" feature card |
| `/activity` | `activity_feed`, `activity_feed_mobile`, `activity_feed_no_header_search` | Social feed. Activity types: *borrowed* (book + actor), *commented* (with quote preview + Reply), *followed*, *added_book* |
| `/profile/:id` | `user_profile_jane_doe`, `user_profile_mobile` | Public user profile. Avatar, name, bio, stats (Total Books / Lending / Rating). Tabbed book collection with "Request to Borrow" buttons |
| `/settings` | `settings`, `settings_mobile` | Left-sidebar: **Account Profile** (avatar upload, name, bio 300-char, location), **Privacy & Security**, **Notifications**, **Sign Out** |

**Manage Book modal** — overlays `/library` (not a separate route). Triggered by "Add New Book" or clicking an existing book card. Fields: cover upload, title*, author*, ISBN, status dropdown, and a **search-or-create category picker** (see _Categories_ under Key Conventions). On save it sends `categoryIds` (not names).

### Domain Model

These are the entities to implement in `src/Entity/`:

**User** — `id`, `email`, `password_hash`, `full_name`, `bio` (max 300 chars), `location`, `avatar_path`; derived stats: total books, shared count, loaned count, rating.

**Book** — `id`, `title`*, `author`*, `isbn`, `cover_path`, `status` (enum: `own | lent | unavailable`); `owner → User`; `categories → Category[]` (many-to-many).

**Category** — `id`, `name`, `color_hex` (muted accent tone per design).

**LibraryRequest** — `id`, `book → Book`, `requester → User`, `status` (enum: `pending | approved | declined`), `requested_at`.

**ActivityItem** — `id`, `actor → User`, `action_type` (enum: `borrowed | commented | followed | added_book`), `target_book → Book` (nullable), `target_user → User` (nullable), `comment_text` (nullable), `created_at`.

### Design System
Full token spec: `references/design/literary_commons/DESIGN.md`

| Token | Value | Usage |
|---|---|---|
| Primary green | `#274738` | Primary buttons, focus rings, active tab indicator |
| Paper white | `#fbf9f5` | Page background |
| Surface | `#ffffff` | Cards, modals |
| Error/destructive | `#ba1a1a` | Delete actions, error states |
| Outline | `#727974` | Borders, dividers |
| Headline font | Playfair Display (serif) | Page titles, modal headers, book titles on cards |
| UI/body font | Work Sans (sans-serif) | All other text |
| Border radius — standard | 4px | Buttons, inputs, cards |
| Border radius — modals | 8px | Modal containers |
| Border radius — tags | 9999px (pill) | Category chips |
| Spacing base | 8px | All spacing is multiples of 8 |
| Section separator | 80px (`xl`) | Between major page sections |

## Dev Commands

### Start both servers
```bash
# Terminal 1 — Symfony API
symfony server:start
# or: php -S localhost:8000 -t public/

# Terminal 2 — Vue SPA (http://localhost:5173)
npm run dev
```

### Frontend
```bash
npm run build      # production build → public/build/
npm run preview    # preview production build locally
npm run lint       # ESLint over assets/src/  ⚠ currently broken (see note below)
```

> ⚠ `npm run lint` fails: ESLint is v9+ but the project still has the legacy `.eslintrc` format and no flat `eslint.config.js`. Migrate the config before relying on lint. There is **no JS test runner** wired up (no vitest/jest) — to verify frontend behaviour, build and drive the SPA in a browser.

### Symfony console
```bash
php bin/console make:entity          # scaffold entity + repository
php bin/console make:controller      # scaffold controller
php bin/console doctrine:migrations:diff   # generate migration from entity changes
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load     # load dev seed data
php bin/console debug:router         # list all registered routes
php bin/console debug:autowiring     # list injectable services
```

### Database
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

## Key Conventions

### API routes
All API endpoints live under `/api` prefix. Controllers in `src/Controller/` use `#[Route]` PHP attributes. Return `JsonResponse` — never render Twig templates (there are none).

### Entities
Defined with PHP attributes (`#[ORM\Entity]`, `#[ORM\Column]`, etc.) in `src/Entity/`. Doctrine uses underscore naming strategy and PostgreSQL identity columns for primary keys.

### Persistence & flushing
Repositories may `persist()` and mutate entities, but **must not call `flush()`** — the controller owns the transaction boundary and flushes **exactly once** per request, after all entity changes are staged. This keeps each request a single unit of work and avoids redundant/partial commits. Example: `UserRepository::findOrCreateFromGoogle()` only persists/mutates; `AuthController::googleCallback()` calls `$this->entityManager->flush()` once. A no-op flush (when nothing changed) is harmless.

### Categories
Categories are a **shared, global vocabulary** (unique names), not per-user. The flow is _search-or-create_:
- `GET /api/categories?q=…` searches by name (case-insensitive substring; empty result ⇒ the UI offers creation). Without `q` it lists all.
- `POST /api/categories` (`{ name, colorHex }`) creates one explicitly — `422` blank name · `409` duplicate (case-insensitive) · `201` created.
- **Books reference categories by id**, never by name: `BookInput.categoryIds` (int[]); `BookService` resolves ids via `CategoryRepository::findByIds()`. New categories are created up-front via the POST endpoint, then attached by id. (The old name-based auto-create path is gone.)
- **Colour palette is a single source of truth, duplicated front+back — keep them in sync:** backend `App\Category\CategoryPalette::COLORS` (allowed `colorHex` values, enforced by `CategoryInput`'s `Assert\Choice`) mirrors frontend `assets/src/utils/categoryColors.js` (the `bg` of each `CATEGORY_PALETTE` entry, which also carries chip text/border styling). `ResponseMapper` emits `colorHex` on every category so chips/cards render the stored colour; `resolveCategoryColors()` falls back gracefully for legacy/unknown hexes.

### Frontend imports
The `@` alias resolves to `assets/src/`. Use `import Foo from '@/components/Foo.vue'` everywhere.

### Authentication
JWT via `lexik/jwt-authentication-bundle`. Keys are in `config/jwt/` (gitignored). The passphrase is in `.env` (`JWT_PASSPHRASE`). Security firewall and `access_control` rules are configured in `config/packages/security.yaml`.

### CORS
Handled by `nelmio/cors-bundle`. `CORS_ALLOW_ORIGIN` in `.env` defaults to a regex matching any `localhost` port, which covers the Vite dev server. Adjust for production in `.env.local` or deployment config.

## Environment Setup Notes (Windows-specific)

- `ext-sodium` must be enabled in `php.ini` — required by `lexik/jwt-authentication-bundle` (dependency on `lcobucci/jwt`)
- `extension=pdo_pgsql` and `extension=pgsql` must be enabled in `php.ini` — the app runs on PostgreSQL; without them `doctrine:*` CLI commands fail with "could not find driver"
- JWT keypair was generated via the system OpenSSL CLI, not `php bin/console lexik:jwt:generate-keypair`, because PHP's `openssl_pkey_new()` has issues on this Windows install
- PHP binary: `D:\code\Software\php-8.4.5\php.ini`
- The dev DB password is `changeme` (matches `POSTGRES_PASSWORD`); `DATABASE_URL` uses it in `.env`
- `lexik:jwt:generate-token <email>` mints a JWT for manual API testing — pass `--no-ansi` and strip whitespace before putting it in an `Authorization: Bearer` header (colour codes corrupt the header → nginx 400)
