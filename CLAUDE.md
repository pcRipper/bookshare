# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working Agreement (follow on EVERY update request)

This is the mandatory workflow for any change. It overrides default behavior.

1. **Plan with impact analysis.** Always produce a plan — even for a small change. The value is the impact analysis: what elsewhere in the project this touches (entities, API shape, stores, migrations, tests, docs) and how to avoid regressions. Skip the plan only for truly trivial edits (typo/comment/doc wording).
2. **Get agreement.** Present the plan and wait for approval before writing code. Use plan mode for anything non-trivial.
3. **Implement.** Write the code and add/adjust tests when behavior changes (backend → PHPUnit under `tests/`; frontend has no test runner — verify by build + browser).
4. **Validate.** Run the relevant checks — `php bin/phpunit`, `npm run build`, `php bin/console lint:container`, scripts — before considering the work done.
5. **Fix and re-validate.** If anything fails, return to step 3. Never commit red.
6. **Commit in small, meaningful units.** Prefer several focused commits over one large one — one logical change each. Short imperative messages matching the repo style (lowercase, no trailing period), with the `Co-Authored-By` trailer.
7. **Update docs.** When the change affects them, update `CLAUDE.md` and `todolist.md` (tick checkboxes) — as their **own** commit, separate from code.
8. **Update the changelog.** Reflect user-facing changes in `assets/src/data/changelog.js` (bump the version, add dated notes) — as its own commit.

## Architecture

Bookshare is a **monorepo** where the Symfony project is the repo root. The frontend and backend are coupled by directory structure but decoupled at runtime — they communicate exclusively through a JSON REST API.

```
bookshare/
├── assets/src/          # Vue 3 SPA source (Composition API, JS)
│   ├── main.js          # App bootstrap — registers router + pinia, mounts #app
│   ├── App.vue          # Root: <AppErrorBoundary> → <RouterView /> + <ToastHost />
│   ├── api/index.js     # axios instance (baseURL '/api', Bearer + 401 interceptors)
│   ├── router/          # vue-router (history mode) + auth guard
│   ├── stores/          # Pinia stores (auth, library, discover, profile, toast)
│   ├── views/           # Route-level pages
│   ├── components/      # layout/, library/, discover/, profile/, ui/
│   ├── composables/     # useMercure (real-time SSE subscription)
│   └── utils/           # categoryColors, languages, apiError, time
├── src/                 # Symfony PHP source (autowired, autoconfigured)
│   ├── Controller/      # API controllers — *RestController, #[Route] attributes
│   ├── Entity/          # Doctrine entities — mapped via PHP attributes
│   ├── Enum/            # Backed enums (BookStatus, RequestStatus, …)
│   ├── Repository/      # Doctrine repositories (read queries; persist, never flush)
│   ├── Service/         # Domain logic (BookService, LibraryRequestService, …)
│   ├── Dto/             # Request payload objects (#[MapRequestPayload]) + Assert
│   ├── Api/             # ResponseMapper — entity → JSON shaping
│   ├── Category/        # CategoryPalette (colour allow-list, single source of truth)
│   ├── Language/        # LanguageCatalog (book-language vocabulary, single source of truth)
│   ├── Security/Voter/  # BookVoter — edit/delete authorization
│   ├── EventSubscriber/ # RateLimitSubscriber (kernel.request)
│   └── DataFixtures/    # Dev seed data (AppFixtures)
├── config/
│   ├── packages/        # Bundle config (doctrine, security, nelmio_cors, lexik_jwt,
│   │                    #   rate_limiter, dh_auditor, mercure…)
│   ├── routes.yaml      # Imports src/Controller/ under the shared `/api` prefix
│   └── jwt/             # RSA keypair — gitignored, generated once
├── migrations/          # Doctrine migrations (incl. *_audit tables)
├── tests/               # PHPUnit suite (unit-level: Entity/Service/Dto/Api/Security…)
├── public/
│   ├── index.php        # Symfony front controller
│   └── build/           # Vite production output — gitignored
├── index.html           # Vite entry point (at repo root)
└── vite.config.js
```

**Request flow in dev:**
- Browser → Vite (`:5173`) for all Vue assets
- `fetch('/api/…')` → Vite proxy → Symfony (`:8000`)
- SSE: `EventSource('/.well-known/mercure')` → Nginx/Vite proxy → **Mercure hub** (standalone container), never PHP-FPM
- In prod, Nginx serves both `public/build/` (Vue) and proxies to PHP-FPM (Symfony)

## Tech Stack

**Backend** — Symfony **7.4** LTS on **PHP 8.4**, PostgreSQL via Doctrine ORM 3.

| Concern | Package |
|---|---|
| Framework | `symfony/framework-bundle` 7.4, `console`, `dotenv`, `flex`, `runtime` |
| ORM / DB | `doctrine/orm` ^3.6, `doctrine-bundle` ^3.2, `doctrine-migrations-bundle` ^4 |
| Auth | `lexik/jwt-authentication-bundle` ^3.2, `symfony/security-bundle` |
| HTTP / serialization | `symfony/serializer`, `validator`, `property-access`, `property-info`, `http-client` |
| CORS | `nelmio/cors-bundle` ^2.6 |
| Rate limiting | `symfony/rate-limiter` |
| Audit | `damienharper/auditor-bundle` `6.3.*` (see _Audit trail_) |
| Real-time | `symfony/mercure-bundle` — SSE via a standalone Mercure hub container (see _Real-time_) |
| Dev/test | `phpunit/phpunit` ^13.2, `doctrine-fixtures-bundle`, `maker-bundle`, `browser-kit`, `css-selector`, `debug-bundle` |

**Frontend** — Vue 3 SPA, plain JS (no TypeScript), Composition API throughout.

| Concern | Package |
|---|---|
| Core | `vue` ^3.5, `vue-router` ^4.5 (history mode), `pinia` ^3 |
| HTTP | `axios` ^1.16 (single instance in `assets/src/api/index.js`) |
| Build/tooling | `vite` ^6.3, `@vitejs/plugin-vue` ^5.2, `eslint` ^10, `eslint-plugin-vue`, `prettier` |

## Product

### Overview
FolioShare is a community book-sharing platform. Readers catalog their physical books, lend them to other community members, track borrow requests through a full lifecycle, and discover each other's collections. The UI brand name is **FolioShare**; the repo/project name is **Bookshare**.

### Authentication & access
Sign-in is **Google OAuth only** (the original email/password + register screens were not built). Flow: `LoginView` → `GET /api/auth/google` returns an authorization URL → Google → `POST /api/auth/google/callback` mints a **JWT** (lexik). The SPA stores `token` + `user` in `localStorage` (Pinia `auth` store); axios attaches `Authorization: Bearer <token>` and, on a `401`, drops the stale credentials and bounces to `/login`. The router guard gates every non-public route on `isAuthenticated`.

### Screens & Routes (SPA, vue-router)

| Route | View | Description |
|---|---|---|
| `/login` | `LoginView` | "Continue with Google" button; surfaces `?error=` from the callback |
| `/auth/google/callback` | `GoogleCallbackView` | Exchanges the OAuth code, stores JWT, redirects to `/library` |
| `/library` | `LibraryView` | The signed-in user's library. Profile header (avatar, name, bio, stats) + tabs: **Collection** (book grid, with a **text search** + CSV **import/export** toolbar), **Lending**, **Borrowing** (active loans — books in hand), **Requests** (unified in-flight inbox: *incoming* — Approve/Decline/Confirm — **and** the viewer's own *outgoing* pending requests — Cancel), **History** (loan timeline) |
| `/discover` | `DiscoverView` | Browse the community. Search, category filter pills, **language filter**, trending/recommended grids |
| `/profile/:id` | `ProfileView` | Public profile. Avatar, bio, stats; a **text search** over the **read-only** book collection with "Request to Borrow" (own profile is a preview — book CRUD lives in `/library`, not here) |
| `/settings` | `SettingsView` | Account profile (avatar, name, bio 300-char, location), **privacy toggle**, sign out |
| `/changelog` | `ChangelogView` | Static **Release Notes** — a flat list of versions (label + date + change notes). Data lives in `assets/src/data/changelog.js` (no API); reached via the footer's "Release Notes" link (the old dead-end footer links were removed) |
| `/` | — | Redirects to `/library` |
| `/:pathMatch(.*)*` | `NotFoundView` | Catch-all 404 |

> **Activity feed**: the backend (`ActivityItem`, `ActivityRestController` at `/api/activity`, `ActivityRecorder`) exists and records events, but there is **no SPA route or header link** for it — the nav entry was deliberately removed. Don't re-add it without a product decision.

**Manage Book modal** — overlays `/library` (not a route), `ManageBookModal.vue`. Triggered by "Add New Book" or clicking a book card. Fields: cover, title*, author*, a **description** textarea (≤500, live counter), ISBN, status, a **searchable language picker** (`ui/LanguageSelect.vue`), and a **search-or-create category picker** (`CategorySelector.vue`). Saves `categoryIds` (not names). **In create mode only** the modal has two tabs — *Create manually* (the form) and *Find a template* (`BookTemplateSearch.vue`, see _Book templates_); picking a template pre-fills the manual form and switches to it. When a book is out on loan the modal is **read-only** (see _Authorization_): inputs disabled, a lock notice shows, only Close is offered (driven by the server's `canEdit` flag).

### Domain Model (`src/Entity/`, implemented)

**User** — `email`, `password_hash` (unused for Google users), `full_name`, `bio` (≤300), `location`, `avatar_url`, `is_private` (hides profile + collection from others), `roles`. Derived stats (total books / shared / loaned) come from `UserStatsProvider`, not stored.

**Book** — `title`*, `author`*, `description` (nullable free-text, ≤500), `isbn`, `cover_path`, `status` (`own | lent | unavailable | currently_reading` — `currently_reading` behaves like `unavailable` for borrowing but stays visible in Discover and counts as shared), `language` (nullable ISO 639-1 code, see _Languages_); `owner → User` **and `current_holder → User`**; `categories → Category[]` (many-to-many). `isHome()` ⇔ `currentHolder === owner` (the book is physically with its owner); this gates editability.

**Category** — `name` (unique, global), `color_hex` (one of `CategoryPalette::COLORS`).

**LibraryRequest** — `book`, `requester`, `status` (`RequestStatus`: `pending | approved | declined | return_pending | returned`), `requested_at`, `resolved_at`, **`due_date`**, **`returned_at`**, and an ordered **`events → LibraryRequestEvent[]`** timeline.

**LibraryRequestEvent** — append-only audit of a request: `type` (`requested | approved | declined | return_requested | returned`), `actor`, `due_date?`, **`message?`** (optional ≤255-char note — the owner's reason on a decline), `created_at`. Rendered as a timeline (`RequestTimeline.vue`), which shows the note on its step. `POST /api/requests/{id}/decline` accepts an optional `{ message? }`; `ResponseMapper` emits `message` on every event.

**ActivityItem** — `actor`, `action_type` (`borrowed | returned | commented | followed | added_book`), nullable `target_book` / `target_user`, `comment_text?`, `created_at`.

### Lending lifecycle (the request state machine)
Owned by `LibraryRequestService`; each transition appends a `LibraryRequestEvent` and the controller flushes once, then publishes a real-time Mercure signal to the affected party (see _Real-time_).

```
requester creates ──▶ pending
owner approve(dueDate) ──▶ approved   (book.status=lent, current_holder=requester, due_date set)
owner decline ──▶ declined
requester requestReturn ──▶ return_pending
owner confirmReturn ──▶ returned   (book.status=own, current_holder=owner, returned_at set)
```

**Time-landing rule** (a product requirement): the **due date is set unilaterally by the lending (owner) side at approval** — the borrower neither proposes nor approves it.

Authorization within the machine: only the **owner** may approve / decline / confirm-return; only the **requester** may request a return. The **requester** may also **withdraw** their own request while it's still `pending` — `DELETE /api/requests/{id}` (`LibraryRequestService::cancel`) **deletes the request row outright** (its events cascade away via the FK), no tombstone status. Once the request is approved (or otherwise resolved) the withdrawal is rejected (409). You can't borrow your own book, a book that isn't available, from a private library, or file a duplicate pending request. Ownership violations → `AccessDeniedException` (403); business-rule violations → `\DomainException` (409).

**`lent` is lifecycle-only.** It is set solely by `approve()` (which moves status *and* `current_holder` together) and cleared by `confirmReturn()`. It is **not** a manually-settable status: `BookInput.status`'s `Assert\Choice` accepts only `own | unavailable | currently_reading` (sending `lent` → 422), the Manage Book modal omits it from its picker (only surfacing it read-only when viewing an already-lent book), and CSV import rejects it. This prevents the inconsistent "flagged on-loan while still home" state. `currently_reading` is a manual, owner-set status that behaves like `unavailable` for borrowing (the borrow gate allows only `own`) but stays visible in Discover and counts as shared.

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

Category chips use a curated **10-tone muted palette** (see _Categories_). The footer year is rendered dynamically (`new Date().getFullYear()`).

## Dev Commands

### Start both servers
```bash
# Terminal 1 — Symfony API
symfony server:start          # or: php -S localhost:8000 -t public/
# Terminal 2 — Vue SPA (http://localhost:5173)
npm run dev
```

### Frontend
```bash
npm run build      # production build → public/build/
npm run preview    # preview production build locally
npm run lint       # ESLint over assets/src/  ⚠ currently broken (see note)
```

> ⚠ `npm run lint` fails: ESLint is v10 (flat-config only) but the repo still ships a legacy `.eslintrc.cjs` and no `eslint.config.js`. Migrate the config before relying on lint. There is **no JS test runner** — verify frontend behaviour by building and driving the SPA in a browser.

### Symfony console
```bash
php bin/console make:entity                  # scaffold entity + repository
php bin/console doctrine:migrations:diff     # generate migration from entity changes
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load       # load dev seed data
php bin/console debug:router                 # list all registered routes
php bin/console lint:container               # verify service wiring
```

### Tests
```bash
php bin/phpunit            # full suite (config: phpunit.dist.xml)
```

## Key Conventions

### API routes
All endpoints live under the **`/api`** prefix. The prefix is applied **once, at the routing-config level** (`config/routes.yaml` imports `src/Controller/` with `prefix: /api`); individual controllers carry only their resource segment (e.g. `#[Route('/books')]`). Controllers are named **`*RestController`** (`BookRestController`, `AuthRestController`, …) and always return `JsonResponse` — there are no Twig templates for app output. Auto-generated route names therefore look like `app_bookrest_list`; nothing references route names, so renaming controllers is safe.

### Entities
PHP attributes (`#[ORM\Entity]`, `#[ORM\Column]`) in `src/Entity/`. Doctrine uses the **underscore naming strategy** and **PostgreSQL identity columns** for primary keys. Enums are backed enums in `src/Enum/`.

### Persistence & flushing
Repositories and services may `persist()` and mutate entities, but **must not call `flush()`** — the controller owns the transaction boundary and flushes **exactly once** per request, after all changes are staged. This keeps each request a single unit of work. A no-op flush is harmless.

### Categories
A **shared, global vocabulary** (unique names), not per-user. Flow is _search-or-create_:
- `GET /api/categories?q=…` — case-insensitive substring search (empty ⇒ UI offers creation); without `q`, lists all.
- `POST /api/categories` (`{ name, colorHex }`) — `422` blank name · `409` duplicate · `201` created.
- **Books reference categories by id**, never by name: `BookInput.categoryIds` (int[]); `BookService` resolves via `CategoryRepository::findByIds()`.
- **Colour palette is one source of truth, duplicated front+back — keep in sync:** backend `App\Category\CategoryPalette::COLORS` (enforced by `CategoryInput`'s `Assert\Choice`) mirrors frontend `assets/src/utils/categoryColors.js` `CATEGORY_PALETTE` (each entry adds chip text/border styling). There are **10 muted tones**. `ResponseMapper` emits `colorHex` on every category; `resolveCategoryColors()` falls back gracefully for legacy/unknown hexes.

### Languages
A book's language is an optional **ISO 639-1 code** validated against one source of truth: `App\Language\LanguageCatalog::LANGUAGES` (`code => English name`, enforced by `BookInput.language`'s `Assert\Choice`). The frontend never duplicates the list — `GET /api/languages` serves it (`[{ code, name }]`, sorted), memoized client-side by `utils/languages.js` and consumed by the searchable `ui/LanguageSelect.vue`. `ResponseMapper` emits both `language` (code) and `languageName` (resolved label) on every book, so cards display the name without a lookup. Discover filters via `?language={code}` (`BookRepository::findForDiscover`).

### Import / export (CSV)
`App\Service\BookCsvService` round-trips a user's collection. `GET /api/books/export` streams a CSV download; `POST /api/books/import` (multipart `file` + `mode` + `onError` fields) bulk-creates books. Columns: `title, author, description, isbn, cover, language, status, categories` (`cover` is the raw `coverPath` string; categories semicolon-joined names). Import is header-based, so the column set can grow without breaking older files. Import is parameterised on two axes — **`mode`**: `append` | `replace` (replace removes only **home** books, never active loans), **`onError`**: `skip` (import valid rows, report skips) | `abort` (any invalid row ⇒ import nothing, returns `422`). Each row is validated through `BookInput`; categories are **matched to existing names only** (`CategoryRepository::findByNames`, unknowns ignored); importable statuses are `own | unavailable | currently_reading` — `status=lent` is rejected (a loan needs a live borrower). Import is **idempotent on title+author** (case/whitespace-insensitive): a row matching a book the owner will still hold — or an earlier row in the same file — is skipped, not duplicated (in `replace` mode the dedup set is just the surviving loaned-out books). Duplicates are reported in `errors` and counted in `skipped` but, unlike invalid rows, **never trigger an abort**. Returns `{ imported, skipped, aborted, errors[] }`. Driven by `ImportBooksModal.vue`; export reuses the single-flush controller boundary.

### Book templates (fill-from-template)
The create-mode "Find a template" tab pre-fills a new book from existing metadata. `GET /api/books/templates?q=&source=&page=` searches by **title or ISBN** and returns an **infinite-scroll envelope** `{ items, hasMore }` (page size `BookRestController::TEMPLATE_PER_PAGE`, 24; `page` via the shared `Pagination` DTO). Each item carries copyable fields only: `{ title, author, description, isbn, coverPath, language, languageName }` — **never** owner/id/status, so it can span **every** library (private included) without leaking who holds a book. The envelope is deliberately **`hasMore`-only** (not the standard `{ total, totalPages }` shape): deduped/external results have no reliable total. Blank `q` ⇒ `{ items: [], hasMore: false }`; unknown `source` ⇒ 400. Providers return `App\Dto\BookTemplateResult` (`items` + `hasMore`) from `search($query, $limit, $offset)`.

Sources are a **strategy pattern**: `App\Service\BookTemplate\BookTemplateProvider` (interface, `key()` + `search()`), tagged `app.book_template_provider` via `_instanceof` in `services.yaml` and collected by `BookTemplateSearch` (`#[AutowireIterator]`, indexed by key). There are **three**: `site`, `external` (Open Library), `bookfinder` (bookfinder.com.ua). `SiteBookTemplateProvider` (`key='site'`) queries `BookRepository::searchTemplates()` then **collapses duplicates** — two `App\Dto\BookTemplate`s are the same only when title+author+language+ISBN+cover all match (`BookTemplate::dedupeKey()`). It is **single-page** (`hasMore=false`, no infinite scroll): dedup runs *after* the fetch, so a SQL OFFSET would slice before the collapse and drift page-to-page; it returns one bounded page (`offset > 0` ⇒ empty). `ExternalBookTemplateProvider` (`key='external'`) calls the **Open Library Search API** (`openlibrary.org/search.json`, ISBN- vs title-index by query shape) through the scoped `openlibrary.client` (`framework.yaml`); it maps docs to templates (cover URL from `cover_i`, first author/ISBN, **MARC→ISO 639-1** language unmapped ⇒ null, and `first_sentence[0]` as a best-effort **description** — the Search API has no full description field) and is **best-effort** — any transport/HTTP/decode failure is logged and returns an empty page, so a slow/down upstream never breaks the search. It **pages** via OL's `page` param (`page = offset/limit + 1`), with `hasMore` = "a full `limit` of raw docs came back" (independent of how many survive mapping). It sends a `User-Agent` from `OPENLIBRARY_USER_AGENT` (`.env`) for Open Library's higher 3 req/s identified rate limit. Responses are **cached** in a dedicated `cache.openlibrary` pool (backed by `cache.app`), **one entry per page**: only the **raw docs** are stored (mapping runs on read, so transformation fixes apply without waiting out the TTL) and only **successful** fetches (a transient outage never sticks as "no results"); hits live `OPENLIBRARY_CACHE_TTL` (default **30 days** — bibliographic data is static; scrolling back over a page is a cache hit), empty results a short 10 min. The query is **normalized** (case/whitespace; ISBN hyphenation stripped) so equivalent inputs share one cache entry *and* one upstream request. The **site** source is deliberately **not** cached (local DB; must reflect a just-added book). `ResponseMapper::bookTemplate()` shapes each item.

`BookFinderBookTemplateProvider` (`key='bookfinder'`) calls the **bookfinder.com.ua** API (`/api/books?query=`) through the scoped `bookfinder.client`, mirroring the Open Library provider's best-effort + cache-raw-docs-on-read design (dedicated `cache.bookfinder` pool, `BOOKFINDER_CACHE_TTL` default **30 days**, `BOOKFINDER_USER_AGENT` — the API needs no identification, so the UA is optional/polite). It targets the **Ukrainian market** Open Library barely covers. Differences from Open Library: the API is a **single full-text `query`** index returning a bare array (no `docs` envelope) sorted by relevance, with **no server-side limit** — so the **whole set is fetched once and cached** (key ignores limit/offset), then **deduped over the entire set and windowed** `array_slice($offset, $limit)`, so every infinite-scroll page after the first is a **cache hit** (one upstream call per query, ever) and slicing stays stable across pages. It supplies **neither ISBN nor language**, and the same book recurs across shops with **different cover URLs**, so results are collapsed on **title+author only** (not `BookTemplate::dedupeKey()`, which keys on the cover too), keeping the first (highest-relevance) hit.

Frontend `BookTemplateSearch.vue`: a **source dropdown** (the shared `ui/BaseSelect.vue`, three options, default `site`) with the active source's hint beneath it, a **per-source debounce** (site 250ms, external & bookfinder 800ms — network sources wait long enough that letter-by-letter typing rarely fires an intermediate, soon-aborted upstream call), a **minimum query length** for the network sources (`MIN_QUERY_LEN`: external & bookfinder require 3+ chars before any request goes out — the broad `h`/`ha` calls are never sent; the site source has no minimum and shows a "Type at least N characters…" hint until met), and **infinite scroll** — an `IntersectionObserver` on a bottom sentinel (root = the scroll list) fetches the next `page` while `hasMore`, accumulating results and dropping exact cross-page repeats via a client-side seen-key set (title+author+isbn+lang+cover, matching the backend dedupe fields). Concurrency is a **search-generation guard**: `searchSeq` is advanced in the `watch` on **every** query/source change (not only inside `runSearch`), at the same moment the in-flight `AbortController` is aborted. Every request (initial *and* load-more) captures its generation and gates **all** shared-state writes — results, `hasMore`, and crucially the `searching`/`loadingMore` flags — on `seq === searchSeq`, so a superseded request is fully inert whether it rejects (aborted) or resolves after losing the abort race. Bumping the generation only inside `runSearch` (the earlier design) left an aborted request able to clear the loading flag of the newer pending search, which could strand the panel in a blank/false-empty state until a single clean request (e.g. switching source) reset it.

### Pagination
List endpoints that can grow unbounded are **offset-paginated** behind one shared shape. `App\Dto\Pagination::fromRequest($request, $defaultPerPage)` parses `?page=&perPage=` — input is **clamped, never rejected** (`page ≥ 1`, `perPage ∈ [1, 100]`; garbage ⇒ default), so a browse UI never 422s on a stray param. Repositories return `App\Dto\PaginatedResult` (`items` + `total`), and `ResponseMapper::paginated($items, $total, $pagination, $mapFn)` emits the **one envelope** every paginated endpoint uses:

```json
{ "items": [ … ], "pagination": { "page": 1, "perPage": 24, "total": 137, "totalPages": 6, "hasMore": true } }
```

Per-list page sizes are **controller constants** (the "reasonable preset" per list): collection & Discover books **24**, Discover accounts **18**, loan History & Following **20**. Repos page via Doctrine `Paginator` when the query fetch-joins to-one associations; the History queries page on **root fields only** then hydrate the to-many `events` in a **second query** (`LibraryRequestRepository::paginateWithEvents`) — the Paginator can't page a fetch-joined collection, and lazy events would N+1.

**What paginates (browse/growing):** Library collection & profile shelf (`GET /books`), Discover books (`/books/discover`) and accounts (`/users/discover`), loan **History** (`/requests/{incoming,outgoing}?status=all`), and the **Following** list (`/subscriptions`). **What deliberately stays a bare array** (the documented "real excuse" — naturally bounded and, for loans, refetched wholesale on Mercure signals): the in-flight request slices (`/requests/*` for `open`/`active`/`pending`), the active **Lending** grid (shares `/books` but the store fetches one generous `perPage`), the **subscription feed**, and the **categories** vocabulary (consumed whole by pickers/pills). History reuses the `/requests` endpoints and returns the envelope **only** for `status=all`; other statuses keep the bare array.

Frontend: the numbered control is the shared `ui/Pagination.vue` (prev/next + page numbers with ellipsis; renders nothing for a single page) — never hand-roll list paging. Paginated stores hold `{ items, page, perPage, total, totalPages }` and expose `fetchX(page)` that **replaces** the page. Refetches triggered by Mercure default their `page` arg to the current page so a signal never yanks the user back to page 1.

### Authorization (voters)
`App\Security\Voter\BookVoter` decides `BOOK_EDIT` / `BOOK_DELETE`: the actor must be the **owner** *and* the book must be **home** (`isHome()`) — a book that's out on loan is frozen. Controllers call `denyAccessUnlessGranted(...)`; `ResponseMapper` emits a **`canEdit`** boolean on every book so the SPA disables the Manage Book modal without re-deriving the rule client-side. Private profiles: `UserRestController::show` returns 403 to non-owners (mirrors the private-library book listing).

### Rate limiting
`config/packages/rate_limiter.yaml` defines three limiters — `auth_ip` (per-IP, guards `/api/auth/*`), `api_user` (per authenticated user), `api_ip_user` (IP+user). `App\EventSubscriber\RateLimitSubscriber` applies them on `kernel.request` at **priority 6** (after the firewall at 8, so the user is resolved). Over-limit → **429 + Retry-After**. The `when@test` block raises limits so tests aren't throttled.

### Audit trail
`damienharper/auditor-bundle` (`config/packages/dh_auditor.yaml`) writes an `<table>_audit` companion (insert/update/delete diffs + acting user) for a **whitelist**: `Book`, `User`, `Category`, `LibraryRequest`. Append-only logs (`ActivityItem`, `LibraryRequestEvent`) are intentionally excluded. The bundle's web **viewer is disabled** (this is a JSON API); its Twig/asset/translation deps come along only to satisfy the bundle and are unused. Pinned to `6.3.*` because 7.x requires Symfony 8.

### Real-time (Mercure / SSE)
Loan-lifecycle changes are pushed to clients over **Server-Sent Events** through a **standalone Mercure hub** (the `mercure` Docker service, `dunglas/mercure`) — long-lived connections live on the Go hub, never on the 5-worker PHP-FPM pool. Config: `config/packages/mercure.yaml` + `MERCURE_URL` / `MERCURE_PUBLIC_URL` (kept **relative** so the subscribe-cookie follows the serving host) / `MERCURE_JWT_SECRET` in `.env`; Nginx proxies `/.well-known/mercure` to the hub with **buffering and gzip off** and request-time DNS resolution.

Design is **signal-and-refetch, not state-push**: after a transition commits, `App\Service\LoanEventPublisher` publishes a **private** `{ reason, requestId }` signal to the affected user's `user/{id}` topic. Publishing happens **after `flush()`** (the controller boundary) so any client refetch reads committed truth, and it is **best-effort** — a hub outage is logged, never fails the transition. The SPA (`assets/src/composables/useMercure.js`) shows a toast and refetches the affected lists via the **existing authenticated store actions**, so authorization stays in the REST layer and the channel is reconnect/race-safe.

- **Recipients:** `request.received` / `return.requested` / `request.cancelled` → book **owner**; `request.approved` / `request.declined` / `return.confirmed` → **requester**. (`request.cancelled` fires when a borrower withdraws a pending request; since the row is deleted, the controller captures the owner id + request id before flush and calls `LoanEventPublisher::publishToUser(...)` after.)
- **Subscriber auth:** EventSource can't send the JWT header, so `GET /api/mercure/token` (`MercureRestController`) mints a signed, HttpOnly subscribe-cookie scoped to the caller's **own** `user/{id}` topic; the `private` flag enforces per-user isolation at the hub.
- **Reconnect:** the composable refreshes the cookie and reconnects with backoff, and on reconnect refetches every loan list to catch signals missed during the gap (the cookie's JWT expires ~hourly).

### Frontend imports & UX patterns
- The `@` alias resolves to `assets/src/` — `import Foo from '@/components/Foo.vue'`.
- **Errors → toasts, not error pages.** `AppErrorBoundary` only catches truly unexpected render errors (→ `ErrorView`); expected API failures must be caught locally and surfaced via the `toast` store (`toast.error(apiErrorMessage(e, fallback))`). `utils/apiError.js` reads RFC7807 `detail`, then `error`, then `message`. `<ToastHost>` lives at the App root.
- **Loading states** use shimmer skeletons (`ui/BaseSkeleton`, `BookCardSkeleton`, `BookGridSkeleton`) and `BaseSpinner` (also for in-button loading), never bare "Loading…" text. `ui/StatusScreen` renders empty/error states.
- **State** lives in Pinia stores (`auth`, `library`, `discover`, `profile`, `toast`); use `storeToRefs` to keep reactivity when destructuring.
- **Book detail modal.** Clicking a book you can't edit opens the read-only `ui/BookDetailModal.vue` — a large cover, full metadata (status pill, owner link, language, ISBN, category chips) and the **complete `description` in normal top-to-bottom flow** (`white-space: pre-line`; the info column scrolls if it overflows). It carries a footer "Request to Borrow" action mirroring the card button states, emitting `request` (parents reuse their existing `onRequest`/`requesting` set) and `close` (also Escape / overlay-click); an **`isSelf`** prop suppresses that footer button (you can't borrow your own book — the footer shows only Close). It opens from `DiscoverBookCard` (Discover + Following feed) and from **every** `BorrowBookCard` on a profile via `@open` — **including your own profile** (the profile book section is read-only: own cards show no action button and open this preview, not the Manage Book editor; that editor lives only in `/library`). There is no hover/tap blurb overlay — the modal replaced it (the old `ui/BookBlurb.vue` clipped the start of long text and was removed).
- **Consistency by default.** Styles and interaction patterns must stay consistent across the app — reuse the existing shared component/token rather than hand-rolling a one-off. Diverge only for a real reason (a genuinely different affordance or requirement), not convenience. Dropdowns are the shared combobox look: `ui/LanguageSelect.vue` (searchable) and `ui/BaseSelect.vue` (plain option list) — never a bare native `<select>`. Text-search boxes are `ui/SearchInput.vue` (search icon + native `type="search"`, self-owned debounce, emits `search`; a right-side `BaseSpinner` while a search is pending/`loading` — matching `BookTemplateSearch` — else a clear button once there's text); it drives the `?q=` filter (title/author/ISBN) on the library collection and profile shelves, taking the list's loading flag via the `loading` prop. It's **uncontrolled** (owns its own text) — reset it by remounting via `:key` (ProfileView keys on profile id + shelf so a filter never leaks across them).

### CORS
`nelmio/cors-bundle`. `CORS_ALLOW_ORIGIN` in `.env` defaults to a regex matching any `localhost` port (covers the Vite dev server). Adjust for production in `.env.local` / deployment config.

### Testing
PHPUnit suite under `tests/`, run with `php bin/phpunit`. It is **unit-level** (mirrors `src/`: `Entity/`, `Service/`, `Dto/`, `Api/`, `Security/Voter/`, `EventSubscriber/`, `Category/`, `Language/`) — no kernel boot or DB, so it runs fast and doesn't need the audit tables. `phpunit.dist.xml` sets `failOnDeprecation` / `failOnNotice` / `failOnWarning` = **true**, so under PHPUnit 13: use `createStub()` (not `createMock()`) when you only need a return value, and pair `->with(...)` with an explicit `->expects(...)`. There is no HTTP/`WebTestCase` layer (the test env disables the firewall: `when@test: security: ~`).

## Environment Setup Notes (Windows-specific)

- **PHP ini extensions** to enable in `D:\code\Software\php-8.4.5\php.ini`:
  - `ext-sodium` — `lexik/jwt-authentication-bundle` (via `lcobucci/jwt`)
  - `pdo_pgsql` + `pgsql` — PostgreSQL; without them `doctrine:*` fails with "could not find driver"
  - `intl` — required by `auditor-bundle` (`php_intl.dll` + ICU DLLs already ship with this PHP)
- **Dumped-env gotcha:** a committed `.env.local.php` exists, and Symfony reads **only** it in dev (ignoring `.env`). After editing `.env`, run `composer dump-env dev` — otherwise you get `Environment variable not found: "…"`.
- JWT keypair was generated via the system **OpenSSL CLI**, not `lexik:jwt:generate-keypair` (PHP's `openssl_pkey_new()` misbehaves on this Windows install).
- Dev DB password is `changeme` (matches `POSTGRES_PASSWORD`); `DATABASE_URL` uses it in `.env`.
- `lexik:jwt:generate-token <email>` mints a JWT for manual API testing — pass `--no-ansi` and strip whitespace before putting it in an `Authorization: Bearer` header (colour codes corrupt the header → nginx 400).
