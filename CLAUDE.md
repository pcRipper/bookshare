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
│   ├── main.js          # App bootstrap — registers router + pinia + i18n, mounts #app
│   ├── App.vue          # Root: <AppErrorBoundary> → <RouterView /> + <ToastHost />
│   ├── api/index.js     # axios instance (baseURL '/api', Bearer + 401 interceptors)
│   ├── router/          # vue-router (history mode) + auth guard
│   ├── stores/          # Pinia stores (auth, library, collections, discover,
│   │                    #   profile, subscriptions, toast)
│   ├── views/           # Route-level pages
│   ├── components/      # layout/, library/, discover/, profile/, ui/
│   ├── composables/     # useMercure (real-time SSE subscription)
│   ├── i18n/            # vue-i18n instance + setLocale; locales/{en,de,es,fr,uk}.json
│   └── utils/           # categoryColors, languages, apiError, time
├── src/                 # Symfony PHP source (autowired, autoconfigured)
│   ├── Controller/      # API controllers — *RestController, #[Route] attributes
│   ├── Entity/          # Doctrine entities — mapped via PHP attributes
│   ├── Enum/            # Backed enums (BookStatus, RequestStatus, …)
│   ├── Repository/      # Doctrine repositories (read queries; persist, never flush)
│   ├── Service/         # Domain logic (BookService, LibraryRequestService, …)
│   ├── Dto/             # Request payload objects (#[MapRequestPayload]) + Assert
│   ├── Api/             # ResponseMapper (entity → JSON), ApiError (translated failures)
│   ├── Category/        # CategoryPalette (colour allow-list, single source of truth)
│   ├── Language/        # LanguageCatalog (book-language vocabulary, single source of truth)
│   ├── I18n/            # LocaleCatalog (UI-language allow-list, mirrored in the SPA)
│   ├── Mail/            # MailType (the 8 mails) + Mailer (one send path) + LoanMailer
│   ├── Exception/       # DomainRuleException — translatable business-rule violations
│   ├── Security/Voter/  # BookVoter — edit/delete authorization
│   ├── EventSubscriber/ # RateLimit / Locale / ApiException (kernel.request, .exception)
│   └── DataFixtures/    # Dev seed data (AppFixtures)
├── config/
│   ├── packages/        # Bundle config (doctrine, security, nelmio_cors, lexik_jwt,
│   │                    #   rate_limiter, dh_auditor, mercure…)
│   ├── routes.yaml      # Imports src/Controller/ under the shared `/api` prefix
│   └── jwt/             # RSA keypair — gitignored, generated once
├── migrations/          # Doctrine migrations (incl. *_audit tables)
├── templates/emails/    # Twig mail templates (html + txt per MailType) + shared layout
├── translations/        # API message + validator + mail catalogs (de/es/fr/uk; en needs none)
├── tests/               # PHPUnit suite (unit-level: Entity/Service/Dto/Api/Security…)
│   └── e2e/             # Playwright specs — the mail delivery path against Mailpit
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
- In prod, Nginx serves both `public/build/` (Vue) and proxies to PHP-FPM (Symfony). Hashed assets under `/build/` are cached `1y, immutable`, but **`/build/index.html` is an exact-match `no-cache` location** — it's the SPA entry `try_files` serves for every route, and its path is stable while its contents change every release, so caching it strands returning browsers on the previous bundle

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
| Mail | `symfony/mailer` + Twig templates; queued through `symfony/messenger` + `symfony/doctrine-messenger` and sent by the `messenger-worker` container (see _Mail_) |
| Logging | `symfony/monolog-bundle` ^4 — incl. the dedicated `book_template` and `mail` channels (see _Book templates_, _Mail_) |
| QR codes | `endroid/qr-code` ^6.1 — SVG only (`SvgWriter`); PNG would need `ext-gd` (see _Public library access_) |
| DQL extras | `beberlei/doctrineextensions` ^1.5 — registered for PostgreSQL `DATE_TRUNC` only; Doctrine ships no date function (see _Analytics_) |
| Dev/test | `phpunit/phpunit` ^13.2, `doctrine-fixtures-bundle`, `maker-bundle`, `browser-kit`, `css-selector`, `debug-bundle` |

**Frontend** — Vue 3 SPA, plain JS (no TypeScript), Composition API throughout.

| Concern | Package |
|---|---|
| Core | `vue` ^3.5, `vue-router` ^4.5 (history mode), `pinia` ^3 |
| i18n | `vue-i18n` ^11 (Composition mode; bundled catalogs — see _Internationalization_) |
| HTTP | `axios` ^1.16 (single instance in `assets/src/api/index.js`) |
| Charts | `chart.js` ^4.5 — admin dashboard only, confined to that route's chunk (see _Analytics_) |
| Build/tooling | `vite` ^6.3, `@vitejs/plugin-vue` ^5.2, `eslint` ^10, `eslint-plugin-vue`, `prettier` |

## Product

### Overview
FolioShare is a community book-sharing platform. Readers catalog their physical books, lend them to other community members, track borrow requests through a full lifecycle, and discover each other's collections. The UI brand name is **FolioShare**; the repo/project name is **Bookshare**.

### Authentication & access
Sign-in is **Google OAuth only** (the original email/password + register screens were not built). Flow: `LoginView` → `GET /api/auth/google` returns an authorization URL → Google → `POST /api/auth/google/callback` mints a **JWT** (lexik). The SPA stores `token` + `user` in `localStorage` (Pinia `auth` store); axios attaches `Authorization: Bearer <token>` and, on a `401`, drops the stale credentials and bounces to `/login`. The token is good for **24 h** (`token_ttl` in `lexik_jwt_authentication.yaml`, a literal rather than an env var so it can't trip the `.env.local.php` gotcha below). It's set deliberately long because there is **no refresh-token or revocation path**: the bundle default of one hour expired tokens mid-session and that 401 interceptor was the only thing catching it. The trade-off is that a leaked token stays valid for a day. The router guard gates every non-public route on `isAuthenticated`.

### Screens & Routes (SPA, vue-router)

| Route | View | Description |
|---|---|---|
| `/login` | `LoginView` | "Continue with Google" button; surfaces `?error=` from the callback |
| `/auth/google/callback` | `GoogleCallbackView` | Exchanges the OAuth code, stores JWT, redirects to `/library` |
| `/library` | `LibraryView` | The signed-in user's library. Profile header (avatar, name, bio — no stat block; the three figures it showed restated what the shelves themselves do) + **three** tabs: **Books** (the whole catalogue — three shelves as subtabs: the book grid with a **text search** + CSV **import/export** toolbar, **Collections** (owner's curated groups — create/edit/delete; see _Book collections_), and **Wish List** (books wanted rather than held, with a priority sort + filter; see _Wish list_) — see _Books tab_), **Sharing** (the whole loan lifecycle — see _Sharing tab_), **Following**. Collection loans surface grouped throughout Sharing. Three tabs fit a phone, so the strip is a **static** equal-pitch list with icons and a sliding active marker — no scroller, no `v-hscroll`, nothing off-screen (see _Books tab_) |
| `/discover` | `DiscoverView` | Browse the community in two modes — **books** (search, category filter pills, **language filter**, card/table view) and **readers** (`/users/discover`, follow/unfollow inline). **Neither mode needs a query**: an empty box browses — books newest-first, readers newest-member-first — and typing filters (books by title/author, readers by name, alphabetically) |
| `/profile/:id` | `ProfileView` | Public profile. Avatar, bio, stats; tabs for the **read-only** book shelves (Available to Borrow / All Books, with a **text search**), a **Collections** tab (read-only, each with a "Borrow collection" modal — see _Book collections_) and a read-only **Wish List** tab, all with "Request to Borrow" (own profile is a preview — book/collection CRUD lives in `/library`, not here; a wish-list book offers no borrow action at all) |
| `/subscriptions` | `SubscriptionsView` | **Following** — recent books from the readers you follow (paginated feed; empty state links to Discover). Reached from the header/bottom nav |
| `/settings` | `SettingsView` | Account profile (avatar, name, bio 300-char, location), **privacy toggle**, **notification opt-ins**, sign out. The UI language is deliberately **not** here — it lives in the header switcher (see _Internationalization_) |
| `/public/library/:id` | `PublicLibraryView` | **Public, no account needed** — a read-only view of one member's books + collections, reached by share link or QR (see _Public library access_). Renders in the public layout variant |
| `/admin` | `AdminView` | **Admin only** — the operator panel shell: layout, page header and a `SubTabNav` strip over its sections. Redirects to `/admin/members`. Reached from the account dropdown, which only shows the link to an administrator. See _Admin panel_ |
| `/admin/members` | `AdminMembersView` | **Admin only** — the member table: search, status filter, suspend / reinstate / delete. See _Admin panel_ |
| `/admin/stats` | `AdminStatsView` | **Admin only** — the analytics section: growth, engagement, traffic and library health, over a 7/30/90-day window. Keeps its original path and route name, from when it was the whole of `/admin`. See _Analytics_ |
| `/changelog` | `ChangelogView` | Static **Release Notes** — a flat list of versions (label + date + change notes). Data lives in `assets/src/data/changelog.js` (no API); reached via the footer's "Release Notes" link (the old dead-end footer links were removed) |
| `/` | — | Redirects to `/library` |
| `/:pathMatch(.*)*` | `NotFoundView` | Catch-all 404 |

> **Activity feed**: the backend (`ActivityItem`, `ActivityRestController` at `/api/activity`, `ActivityRecorder`) exists and records events, but there is **no SPA route or header link** for it — the nav entry was deliberately removed. Don't re-add it without a product decision.

**Manage Book modal** — overlays `/library` (not a route), `ManageBookModal.vue`. Triggered by "Add New Book" or clicking a book card. Fields: cover, title*, author*, a **description** textarea (≤500, live counter), ISBN, status, a **searchable language picker** (`ui/LanguageSelect.vue`), and a **search-or-create category picker** (`CategorySelector.vue`). Saves `categoryIds` (not names). **In create mode only** the modal has two tabs — *Create manually* (the form) and *Find a template* (`BookTemplateSearch.vue`, see _Book templates_); picking a template pre-fills the manual form and switches to it. When a book is out on loan the modal is **read-only** (see _Authorization_): inputs disabled, a lock notice shows, only Close is offered (driven by the server's `canEdit` flag).

### Domain Model (`src/Entity/`, implemented)

**User** — `email`, `password_hash` (unused for Google users), `full_name`, `bio` (≤300), `location`, `avatar_url`, `is_private` (hides profile + collection from others), `roles`. Derived stats (total books / shared / loaned) come from `UserStatsProvider`, not stored.

**UserSettings** — per-user preferences, deliberately split off `User` (knobs, not identity) and served from `/api/me/settings`: `allow_requests`, `show_location`, the four `notify_*` opt-ins, and **`locale`** (the UI language, **nullable** — see _Internationalization_). A user with no row yet behaves as if every setting is at its default.

**Book** — `title`*, `author`*, `description` (nullable free-text, ≤500), `isbn`, `cover_path`, `status` (`own | lent | unavailable | currently_reading` — `currently_reading` behaves like `unavailable` for borrowing but stays visible in Discover and counts as shared), `language` (nullable ISO 639-1 code, see _Languages_), **`is_read`** (owner's personal "already read" flag, boolean; orthogonal to `status` — emitted as `isRead`, shown as a "Read" badge on every book card/detail surface), **`is_wished`** + **`wish_priority`** (the wish list — see _Wish list_); `owner → User` **and `current_holder → User`**; `categories → Category[]` (many-to-many). `isHome()` ⇔ `currentHolder === owner` (the book is physically with its owner); this gates editability.

**Category** — `name` (unique, global), `color_hex` (one of `CategoryPalette::COLORS`).

**LibraryRequest** — `book`, `requester`, `status` (`RequestStatus`: `pending | approved | declined | return_pending | returned`), `requested_at`, `resolved_at`, **`due_date`**, **`returned_at`**, and an ordered **`events → LibraryRequestEvent[]`** timeline.

**LibraryRequestEvent** — append-only audit of a request: `type` (`requested | approved | declined | return_requested | returned`), `actor`, `due_date?`, **`message?`** (optional ≤255-char note — the owner's reason on a decline), `created_at`. Rendered as a timeline (`RequestTimeline.vue`), which shows the note on its step. `POST /api/requests/{id}/decline` accepts an optional `{ message? }`; `ResponseMapper` emits `message` on every event.

**BookCollection** — an owner-curated, named grouping of the owner's own books (e.g. a series), borrowable as a unit. `name`, `description?` (≤500), **`cover_url?`** (optional card image), `owner → User`, `books ←→ Book` (many-to-many `book_collection_book`; a book may sit in several collections), `created_at`. A collection needs **≥2** books.

**CollectionRequest** — the **parent** of a collection borrow (whole-group lifecycle): `collection`, `requester`, `status` (reuses `RequestStatus`), `requested_at`/`resolved_at?`/`due_date?`/`returned_at?`, `decline_message?`, and `children → LibraryRequest[]`. Each selected book fans out into a child **`LibraryRequest`** carrying a nullable **`parent_request_id`** back to this parent (deleting the parent cascades the children). See _Book collections_.

**ActivityItem** — `actor`, `action_type` (`borrowed | returned | commented | followed | added_book`), nullable `target_book` / `target_user`, `comment_text?`, `created_at`.

**PageViewDaily** / **PageViewVisitor** — the traffic counters: one row per (`route`, `day`) with a `views` tally, and one row per (`day`, `visitor_hash`, `authenticated`). Aggregates only, never per-visit rows; see _Analytics_.

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

Authorization within the machine: only the **owner** may approve / decline / confirm-return; only the **requester** may request a return. The **requester** may also **withdraw** their own request while it's still `pending` — `DELETE /api/requests/{id}` (`LibraryRequestService::cancel`) **deletes the request row outright** (its events cascade away via the FK), no tombstone status. Once the request is approved (or otherwise resolved) the withdrawal is rejected (409). You can't borrow your own book, a book that isn't available, from a private library, or file a duplicate pending request. Ownership violations → `AccessDeniedException` (403, rendered as a translated `{ error }` body — see _Internationalization_); business-rule violations → `App\Exception\DomainRuleException` (a `\DomainException`, so `catch (\DomainException)` still works) → 409.

**`lent` is lifecycle-only.** It is set solely by `approve()` (which moves status *and* `current_holder` together) and cleared by `confirmReturn()`. It is **not** a manually-settable status: `BookInput.status`'s `Assert\Choice` accepts only `own | unavailable | currently_reading` (sending `lent` → 422), the Manage Book modal omits it from its picker (only surfacing it read-only when viewing an already-lent book), and CSV import rejects it. This prevents the inconsistent "flagged on-loan while still home" state. `currently_reading` is a manual, owner-set status that behaves like `unavailable` for borrowing (the borrow gate allows only `own`) but stays visible in Discover and counts as shared.

### Books tab

The Library's own catalogue, in one tab. Books, Collections and Wish List were three top-level tabs holding three shelves of the same thing — the wish list is literally the Books shelf under `is_wished` (see _Wish list_), and a collection is a grouping *of* the Books shelf — so finding a title meant knowing which shelf it had been filed on before you could look for it. Same argument, and the same shape, as the _Sharing tab_ one level over.

- **The shelf is a subtab**, on the shared `ui/SubTabNav.vue` — the pill strip Sharing already used for Borrowing/Lending, extracted once a second caller wanted it. Both of the Library's split tabs now carry the identical second-level control.
- **The top strip carries no counters at all.** Books can't have one — a shelf size is not a task — so a number on the other two made the three tabs three different kinds of thing, and on a phone, where the icon sits above the label, a badge has nowhere to go but on top of the icon. Every counter lives one level down, on the pills: a `badge` (navy) for something waiting on you, which is what the Borrowing / Lending pair carries, and a `count` (muted) for how big a shelf is, which is what the three shelf pills carry. Keep those two distinct. Every count comes off the one eager `fetchMe()` — `UserStatsProvider` supplies `totalBooks`/`collections`/`wished` together — so none of them reads 0 until its shelf is opened, which is the trap the Sharing badges had to be written around.
- **One panel renders both book shelves.** `components/library/BookShelfPanel.vue` owns the toolbar, the search box, the layout toggle, the skeletons, the no-matches state, the grid-or-table switch, the lead card and the pager; the shelves differ only in the store slice, the lead card's wording and two slots — `filters` (the wish list's priority pills and sort) and `actions` (share / import / export, which only the owned shelf has). Before this they were two near-identical hundred-line blocks. Collections keeps its own grid: it renders a different entity, not a different shelf of books. `.toolbar-btn` therefore lives in `tokens.css`, not in a scope — the row spans a component boundary, so no one scope id covers both the panel's Add button and the caller's slotted ones.
- The **search boxes stay uncontrolled and seeded with `:initial`**, for the reason the shelves are `v-if`-ed: leaving one unmounts the box while the store keeps the filter.
- The lazy shelves (`collections`, `wishlist`) load on first entry and are then cached behind `loaded`. That is deliberately *unlike* the Sharing panel, which refetches its whole side on every entry: a loan changes when the other party acts, but nothing outside this page writes to your own shelves.

**The tab strip is static, and only three tabs make that possible.** At five it needed 565px in the 342 a 390-wide phone has, so it was a horizontal scroller with a hidden scrollbar and edge shadows standing in for the affordance the scrollbar would have been. Three tabs fit, and the whole apparatus is gone — no scroll container, no `v-hscroll` (the directive stays; Discover and Subscriptions still use it), no gradients. What replaces it:

- **One pitch, `--tab-w`, that the tabs are laid out on and the marker is measured from**, so the two cannot disagree. Phones divide the width evenly; from 768px the tabs take a fixed 168px and sit left — an equal third of a 1200px page put 400px of marker under a 60px label.
- **The active marker is one element, not a border on the selected button**, so it travels rather than blinking. Because every tab is one pitch wide, `translateX(index * 100%)` lands it exactly — no measuring, no `ResizeObserver`, and it stays correct through a locale switch that changes every label's width. Verified at 320/390/1280 in all five locales.
- **Icons carry the state change** (fill + a slight scale) while the label only shifts colour: the icon is what the eye returns to once the labels have been read once. All of it drops under `prefers-reduced-motion`.
- **Arrow keys walk both strips** and each is a single tab stop, which is what `role="tablist"` promises and none of the app's tab strips did before.
- **Translated labels are the binding constraint, not English.** The subtab pills share the row on phones rather than taking content width — at content width the German, Spanish and French shelf labels overflowed a 390-wide track, and an inline-flex track with `nowrap` children pushes the last pill out of reach instead of scrolling to it. Where equal segments still aren't enough the **count** is dropped before the label is truncated. At 320px some subtab labels do ellipsise; the top strip does not, in any locale.

### Sharing tab
The whole lending lifecycle lives in **one** library tab, split by **your role in the loan** rather than by list. Borrowing, Lending, Requests and History used to be four top-level tabs holding four slices of the same machine, so finding a loan meant guessing which slice held it; the loan history was *already* split down this exact axis by an inner toggle, which is now the subtab pair itself.

- **Two subtabs — Borrowing (books you hold) / Lending (books you own)** — rendered by the shared `ui/SubTabNav.vue` pill pair, the control the history toggle used, so this is a rename plus a badge, not a new component. (The Books tab wanted the same strip for its shelves, which is what pulled it out of this view into a component — see _Books tab_.) `sharingSide` (`'borrowing' | 'lending'`) is the single state.
- **One list, one card, state as a pill.** The first cut stacked three headed blocks per side — requests, active loans, past loans — and each block kept its own card component and its own grid, so one panel changed layout three times down a single column and read as three unrelated pages. Now every loan on a side is normalised into one shape and rendered by one component, with the lifecycle carried on the card instead of in a heading. **Never reintroduce per-state sections here**; a new state is a new pill and a new branch in the card.
  - `assets/src/utils/loans.js` — pure functions, no component ever sniffs which payload it got. `toLoan(payload, kind, perspective)` maps both `ResponseMapper::request()` and `::collectionRequest()` (already near-parallel) onto one shape, resolving `counterpart` per side and keying the render on `kind:id` because **ids collide between the two request tables**.
  - `components/library/LoanCard.vue` replaces **five** components — `RequestCard`, `PendingRequestCard`, `BorrowingCard`, `LoanHistoryCard` and `CollectionRequestCard`. It derives its variant from `(perspective, status)` rather than taking one as a prop, so a caller cannot mislabel a card, and emits the **whole loan** rather than an id (the parent needs `kind` to pick a store). Lending·pending is the only state with a form (due date + optional reason), so it is the only one that grows a second row. `RequestTimeline` is reused behind a collapsed **Details** disclosure, now available on live loans and not just finished ones.
  - **Sort is triage, not chronology** — `STATUS_RANK` puts pending, then return-pending, then approved, then the finished tail, each newest-first. Sorting the live loans purely by recency floated a just-approved loan above a request nobody had answered. Keeping `finished` last is also what lets one pager sit at the bottom.
  - **Filter pills** (All / Awaiting / On loan / Finished) use the shared `.filter-row` / `.filter-pill`, the same control the wish list uses for priority — the colour modifiers are the wish list's alone. The **Finished count comes from the server totals**, not the page in hand. Switching side resets the filter to All, so a narrowing that matches nothing on the other side can't read as an empty side.
- **The three status keywords must stay disjoint.** `pending` / `active` / `resolved` partition all five statuses; the incoming lists deliberately do **not** use `open`, which shares `ReturnPending` with `active` and would put the same loan in the list twice now that the slices merge. A return awaiting confirmation therefore sits under *On loan* — truthfully, since the book is still out — carrying its Confirm button. For the same reason the lending side reads `/requests/incoming?status=active` rather than `/books?status=lent`: only the request carries the borrower, due date and timeline, and `parentRequest IS NULL` already does what `excludeCollectionLoans` did.
- **"Finished" reads `?status=resolved`** (declined + returned), **not `all`**: `all` includes the live rows that are already in the same list, which showed each in-flight loan twice. `resolved` therefore had to start paginating — `isPaginatedSlice()` in both `LibraryRequestRestController` and `CollectionRequestRestController` — since it grows unbounded exactly as `all` does. It was already a defined keyword and previously unused by the SPA. Two paginated sources (per-book and collection) sit under **one** pager: pages advance together and the shorter source runs out.
- **The panel refetches its whole visible side on every entry**, with no `loaded` flag. That was already the History tab's rule (a loan's state changes when the *other* party acts, so a cached list goes stale with nothing on this page touching it) and it now covers the requests and active loans that share the panel. `loaded` keeps only `collections` / `following` / `wishlist`.
- **Badges count only what is fetched eagerly on mount** — Borrowing = active borrows + outgoing pending; Lending = incoming requests. `lending` / `cLending` are lazy and would read `0` until opened, which is why the old Lending tab carried no badge at all. The parent Sharing badge is the sum of the two, so it can never contradict its children. The active pill and `.tab-badge` both fill with the primary green, so the badge inverts on the active pill or the count disappears into it.
- The stores keep `requestedAt` as the **ISO timestamp** (it is the list's sort key) and the card derives the human phrasing with `relativeTime`; the old `toCardRequest`/`toCard` mappers that overwrote it with a relative string are gone.

### Design System
**`assets/src/styles/tokens.css` is the single source of truth.** Read values from there, never
from `references/design/literary_commons/DESIGN.md` — that file is the *original* design study and
still describes the green "Literary Commons" palette the app was recoloured away from (`d7f565c`
navy + brass, `8b3ec78` cool neutrals, `b01246f` Literata + IBM Plex Sans). It is kept for the
type-scale and component sketches; its colours and faces are historical. The mail templates were
built from it once, and shipped a green mail inside a navy app.

| Token | Value | Usage |
|---|---|---|
| Primary — Maritime Navy | `#223b54` | Primary buttons, focus rings, active tab indicator, mail brand bar |
| Accent — Antique Brass | `#a9781f` | Used **sparingly**: active / selected states only |
| Page ground | `#f4f6f9` | Page background (cool, biased toward the navy) |
| Surface | `#ffffff` | Cards, modals |
| Container (low) | `#eef1f5` | Inset blocks — e.g. the mail loan-summary panel |
| On-surface / variant | `#171b21` / `#3c434c` | Body text and its muted second level |
| Error/destructive | `#ba1a1a` | Delete actions, error states |
| Outline | `#6b727b` | Borders, dividers, muted labels |
| Headline font | Literata (serif) | Page titles, modal headers, book titles on cards |
| UI/body font | IBM Plex Sans (sans-serif) | All other text |
| Border radius — standard | 4px | Buttons, inputs, cards |
| Border radius — modals | 8px | Modal containers |
| Border radius — tags | 9999px (pill) | Category chips |
| Spacing base | 8px | All spacing is multiples of 8 |
| Section separator | 80px (`xl`) | Between major page sections |
| Modal width | `--modal-w-sm/md/lg/xl` = 520/640/800/900px | Every dialog's `max-width` — pick by content, never a per-component number |
| Modal overlay inset | `--modal-gutter` = 12px, 24px from 768px up | Overlay padding; the phone value buys back width the backdrop was eating |
| Modal height | `--modal-max-h` = 85svh, 90svh from 768px up | Every dialog's `max-height`. **`svh`, never `vh`** — on iOS Safari `vh` is the viewport with the toolbars *hidden*, so a sheet sized in it hangs its close button and footer off the visible screen whenever they're shown. The `90vh` line above it is only the fallback for browsers without the newer units |

**Modals are sized from the scale, not by hand.** `sm` = one short form (share a link), `md` = a compact single-column form (import, edit profile), `lg` = a two-column form (manage a book, edit a collection), `xl` = a cover-plus-detail reading surface (book/collection preview). From **768px** the two `lg` forms split into a cover column beside the fields (`.form__aside` / `.form__main` wrappers, plus `.picker-panes` for `CollectionEditModal`'s two book lists, which uses `auto-fit` so the selected pane spans full width in read-only mode); below it every modal is the original single stack. The `xl` covers grow at the same breakpoint so they stay in proportion with the wider sheet.

Category chips use a curated **10-tone muted palette** (see _Categories_). The footer year is rendered dynamically (`new Date().getFullYear()`).

**The one surface that can't read a token is mail.** An email client has no CSS variables, so
`templates/emails/` carries literal hexes and font stacks copied out of `tokens.css` — the only
duplication of the palette in the project, and it rots the way the palette/`categoryColors.js`
pair would if nothing watched it. Two guards, deliberately different in kind: `MailStyleTest`
fails on any colour that is not a value defined in `tokens.css` (and names the retired ones), and
`tests/e2e/mail-visual.spec.js` screenshots every mail against a committed baseline **and**
asserts the computed background of the brand bar and button. The visual half exists because a
mail in the wrong colour is not an error, a missing key or a broken link — nothing else in the
suite can see it.

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
php bin/console app:grant-admin <email>      # grant the operator dashboard (--revoke to remove)
php bin/console app:prune-analytics          # drop old visitor rows (--days=120, --dry-run)
php bin/console app:send-loan-reminders      # mail due-tomorrow/overdue borrowers (--dry-run)
php bin/console app:mail-preview --all-locales  # render every mail to var/mail-preview (design review)
php bin/console messenger:consume async      # drain the mail queue by hand (the worker does this)
php bin/console messenger:stats              # queued/failed counts
php bin/console mailer:test you@example.com  # prove the configured relay accepts us
php bin/console lint:yaml translations        # validate the message/validator catalogs
```

> The frontend catalogs have no linter. After touching `assets/src/i18n/locales/`, check key parity and per-locale plural-form counts against `en.json` (Ukrainian needs 3 forms where the others need 2) — a missing key silently falls back to English, and a wrong form count silently renders the wrong branch.

### Logs
```bash
tail -f var/log/dev.log            # application log (dev)
tail -f var/log/book_template.log  # one record per external template search (dev)
tail -f var/log/mail.log           # one record per mail sent or deliberately skipped (dev)
```

> `book_template.log` is the first place to look at any "Find a template" complaint — it distinguishes a genuinely empty upstream result from a degraded one, and shows the cache hit/miss and duration behind a search that felt slow. In prod both go to stderr (JSON), so it's the container log. See _Book templates_.

### Tests
```bash
php bin/phpunit            # full suite (config: phpunit.dist.xml)
npm run test:e2e           # Playwright — needs the docker stack up + `npm run build`
```

> `npm run test:e2e` is the **only** JS test suite, and it exists for one reason: mail
> crosses a queue, a worker and an SMTP hop, and only the catcher can tell "queued" from
> "delivered". It drives the running local stack and asserts against Mailpit's REST API
> (`tests/e2e/`, config in `playwright.config.js`). Sign-in is seeded from a JWT minted
> with `lexik:jwt:generate-token` — Google OAuth can't be driven from a test browser.

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
A book's language is an optional **ISO 639-1 code** validated against one source of truth: `App\Language\LanguageCatalog::LANGUAGES` (`code => English name`, enforced by `BookInput.language`'s `Assert\Choice`). The frontend never duplicates the list — `GET /api/languages` serves it (`[{ code, name }]`, sorted), memoized client-side by `utils/languages.js` and consumed by the searchable `ui/LanguageSelect.vue`. `ResponseMapper` emits both `language` (code) and `languageName` (resolved label) on every book. **`languageName` is always English** (it comes from this catalog, the validation source of truth) — so UI surfaces render the label through `utils/languages.js`'s `languageLabel(code, languageName)`, which re-derives it in the active UI locale and keeps the server's value only as a fallback (see _Internationalization_). Cards therefore key their `v-if` on **`book.language`**, not `languageName`. Discover filters via `?language={code}` (`BookRepository::findForDiscover`).

### Import / export (CSV)
`App\Service\BookCsvService` round-trips a user's collection — **both shelves**, since an export that silently dropped the wish list would lose data the file claims to carry. `GET /api/books/export` streams a CSV download; `POST /api/books/import` (multipart `file` + `mode` + `onError` fields) bulk-creates books. Columns: `title, author, description, isbn, cover, language, status, read, wished, priority, categories` (`wished` is the wish-list flag in the same truthy spelling as `read`, `priority` the `WishPriority` number — a file exported before they existed imports exactly as it used to, the header map being name-based; the dedupe key includes the shelf, so a wish row is never swallowed as a duplicate of a book already owned) (`cover` is an **outward-facing link, never our internal path**: `BookCsvService::coverLink()` exports `cover_source_url` — the URL the image was downloaded from — and falls back to `coverPath` run through `UrlHelper::getAbsoluteUrl()` for rows localized before that column existed, so a CSV leaving the site always carries a resolvable URL. Importing such a file simply stores that URL, remote, until the backfill localizes it; `read` is the `is_read` flag as `1`/`0` and parses truthy `1/true/yes/y` on import; categories semicolon-joined names). Import is header-based, so the column set can grow without breaking older files (a file missing `read` imports as unread). Import is parameterised on two axes — **`mode`**: `append` | `replace` (replace removes only **home** books, never active loans), **`onError`**: `skip` (import valid rows, report skips) | `abort` (any invalid row ⇒ import nothing, returns `422`). Each row is validated through `BookInput`; categories are **matched to existing names only** (`CategoryRepository::findByNames`, unknowns ignored); importable statuses are `own | unavailable | currently_reading` — `status=lent` is rejected (a loan needs a live borrower). Import is **idempotent on title+author** (case/whitespace-insensitive): a row matching a book the owner will still hold — or an earlier row in the same file — is skipped, not duplicated (in `replace` mode the dedup set is just the surviving loaned-out books). Duplicates are reported in `errors` and counted in `skipped` but, unlike invalid rows, **never trigger an abort**. Returns `{ imported, skipped, aborted, errors[] }`. Driven by `ImportBooksModal.vue`; export reuses the single-flush controller boundary.

### Book templates (fill-from-template)
The create-mode "Find a template" tab pre-fills a new book from existing metadata. `GET /api/books/templates?q=&source=&page=` searches by **title, author or ISBN** (what each source can actually match differs — see below) and returns an **infinite-scroll envelope** `{ items, hasMore }` (page size `BookRestController::TEMPLATE_PER_PAGE`, 24; `page` via the shared `Pagination` DTO). Each item carries copyable fields only: `{ title, author, description, isbn, coverPath, language, languageName }` — **never** owner/id/status, so it can span **every** library (private included) without leaking who holds a book. The envelope is deliberately **`hasMore`-only** (not the standard `{ total, totalPages }` shape): deduped/external results have no reliable total. Blank `q` ⇒ `{ items: [], hasMore: false }`; unknown `source` ⇒ 400. Providers return `App\Dto\BookTemplateResult` (`items` + `hasMore`) from `search($query, $limit, $offset)`.

Sources are a **strategy pattern**: `App\Service\BookTemplate\BookTemplateProvider` (interface, `key()` + `search()`), tagged `app.book_template_provider` via `_instanceof` in `services.yaml` and collected by `BookTemplateSearch` (`#[AutowireIterator]`, indexed by key). There are **three**: `site`, `external` (Open Library), `bookfinder` (bookfinder.com.ua). `SiteBookTemplateProvider` (`key='site'`) queries `BookRepository::searchTemplates()` then **collapses duplicates** — two `App\Dto\BookTemplate`s are the same only when title+author+language+ISBN+cover all match (`BookTemplate::dedupeKey()`). It is **single-page** (`hasMore=false`, no infinite scroll): dedup runs *after* the fetch, so a SQL OFFSET would slice before the collapse and drift page-to-page; it returns one bounded page (`offset > 0` ⇒ empty). `ExternalBookTemplateProvider` (`key='external'`) calls the **Open Library Search API** (`openlibrary.org/search.json`, **`isbn=` for an ISBN-shaped query, the general `q=` index for anything else**) through the scoped `openlibrary.client` (`framework.yaml`); **the `User-Agent` is sent per request by the provider, not configured on the client**: Open Library answers a *blank* one with **403**, which the best-effort catch turns into a silent permanent "no results", so `ExternalBookTemplateProvider::DEFAULT_USER_AGENT` covers a blank `OPENLIBRARY_USER_AGENT` (Symfony's `default:` processor can't — it only fires when a var is *unset*, not set-but-empty). A blank value costs only the lower 1 req/s limit, as intended. **`q=` is load-bearing, not a cosmetic choice**: `title=` matches the title field only, so an author name — which the panel invites — returned nothing or unrelated omnibus editions carrying the name in their title (`q=sapkowski` → 191 works, `title=sapkowski` → 12 mostly-junk ones). It maps docs to templates (cover URL from `cover_i`, **falling back to the cover-by-ISBN endpoint** `covers.openlibrary.org/b/isbn/{isbn}-M.jpg` when a doc has an ISBN but no `cover_i` — only for a well-formed ISBN, since that endpoint 404s on anything else; first author/ISBN, **MARC→ISO 639-1** language — missing/unmapped ⇒ **guessed from the title's script** (`App\Language\LanguageGuesser`), else null — and `first_sentence[0]` as a best-effort **description** — the Search API has no full description field) and is **best-effort** — any transport/HTTP/decode failure is logged and returns an empty page, so a slow/down upstream never breaks the search. It **pages** via OL's `page` param (`page = offset/limit + 1`), with `hasMore` driven by the response's **exact `numFound`** (`page * limit < numFound`) — the count is present even under `?fields=`. The old rule ("a full `limit` of raw docs came back") stalled the scroll on a page whose docs all failed mapping: it handed back nothing while promising more. It survives as the fallback for a response that omits the count. Setting `OPENLIBRARY_USER_AGENT` (`.env`) earns Open Library's higher 3 req/s identified rate limit. Responses are **cached** in a dedicated `cache.openlibrary` pool (backed by `cache.app`), **one entry per page**: only the **raw docs** are stored (mapping runs on read, so transformation fixes apply without waiting out the TTL) — **pruned** to the mapped fields with list fields capped at 3 (`MAX_ARRAY_VALUES`), because OL returns *every* edition ISBN for a work, often 100+, and we map one — and only **successful, non-empty** fetches (a transient outage never sticks as "no results"); hits live `OPENLIBRARY_CACHE_TTL` (default **30 days** — bibliographic data is static; scrolling back over a page is a cache hit). **Empty pages are never cached** — the callback clears the contract's `bool &$save`: an empty page is the same shape a degraded upstream produces, and an index that gains a title later would otherwise keep answering "no matches" for the whole TTL. The query is **normalized** (case/whitespace; ISBN hyphenation stripped) so equivalent inputs share one cache entry *and* one upstream request. The **site** source is deliberately **not** cached (local DB; must reflect a just-added book). `ResponseMapper::bookTemplate()` shapes each item. Both network scoped clients (`openlibrary.client`, `bookfinder.client` in `framework.yaml`) enable **`retry_failed`** so a *single* transient timeout/5xx is re-issued rather than surfacing as an empty page — the "best-effort ⇒ empty" degrade is the last resort, not the first hiccup. (This closed a bug where the first "Ukrainian stores" search reliably returned nothing under load: the outbound call tripped `max_duration` and the empty result read as "no matches"; the tight timeouts were only tripped because the dev container's Xdebug — `docker/local/php/xdebug.ini` — used `start_with_request=yes` and stalled every request trying to reach an absent debugger, now `trigger`.)

**Timeouts are per-client and measured, not uniform.** `openlibrary.client` stays tight (`timeout: 5`, `max_duration: 12`, 2 retries) — OL answers in ~1–2s. `bookfinder.client` is deliberately much longer (`timeout: 12`, `max_duration: 25`, **1** retry): that API computes a query server-side before sending *anything*, taking **~7s to first byte** on a query it hasn't seen and ~0.08s once its own cache is warm. Symfony's `timeout` is the **inactivity** timeout, which covers that wait — so at 5s *every* cold query was aborted client-side, and the source only worked because `retry_failed` eventually re-issued it against an upstream the aborted attempts had meanwhile warmed. **Measured, three cold queries per arm:** at 5s **all three** attempts (initial + 2 retries) aborted before one succeeded — **13–26s wall, mean 17.7s, three upstream requests each**; at 12s the first attempt succeeds — **7.3–9.3s, mean 8.0s, zero retries, one request**. Don't "tidy" these two back into one value. (The retry storm shows up as `Try #N` lines on monolog's own `http_client` channel, which is how this was measured — a second, unplanned dividend of installing the bundle.)

**Both network providers park the source after a failure** (`inCooldown()`/`startCooldown()`, a ~45s marker in the provider's own cache pool; `cooldownTtl: 0` disables it, which is how the unit tests isolate the caching rules from it). Continued typing against a dead or crawling upstream would otherwise keep dialling it, and each attempt holds one of the five PHP-FPM workers for the whole timeout — up to 12s for bookfinder. This is **not** the never-cache-an-empty-result rule bent: it's failure-only, lasts seconds rather than the 30-day TTL, is per-source (the failure modes seen — upstream down, timeouts under load, a 403 on identification — are properties of the source, not of one query), and is logged.

**Both also emit one structured record per search on the dedicated `book_template` monolog channel** (`monolog.yaml`; wired explicitly as `$logger: '@monolog.logger.book_template'` in `services.yaml`): source, index, normalized query, page, limit, `cacheHit`, `httpStatus`, `numFound`, raw/mapped/deduped counts, `hasMore`, `durationMs` — `info` on success, `warning` on a degrade (with exception class, upstream status and a 200-char body snippet). **The channel needs both halves of its config to be useful**: in dev it writes `var/log/book_template.log` and is excluded from `main` so the feed doesn't drown the app log; in prod it streams to stderr *outside* the `fingers_crossed` gate, which would otherwise buffer and discard exactly these records, since a slow-but-successful search raises no error. The degrade-to-empty path is silent by design, so without this feed an empty panel and a dead upstream are indistinguishable from the outside — which is how the two bugs above (the `title` index and the aborted cold queries) stayed invisible.

Both providers also **guard the payload shape** (non-array `docs`, a scalar among the docs/listings): before that, a shape surprise reached the mapper's `array` parameter as a `TypeError` — a 500, not the best-effort degrade the design promises.

`BookFinderBookTemplateProvider` (`key='bookfinder'`) calls the **bookfinder.com.ua** API (`/api/books?query=`) through the scoped `bookfinder.client`, mirroring the Open Library provider's best-effort + cache-raw-docs-on-read design (dedicated `cache.bookfinder` pool, `BOOKFINDER_CACHE_TTL` default **30 days**, `BOOKFINDER_USER_AGENT` — the API needs no identification, so the UA is optional/polite). It targets the **Ukrainian market** Open Library barely covers. Differences from Open Library: the API is a **single full-text `query`** index returning a bare array (no `docs` envelope) sorted by relevance, with **no server-side limit** — so the **whole set is fetched once and cached** (key ignores limit/offset), then **deduped over the entire set and windowed** `array_slice($offset, $limit)`, so every infinite-scroll page after the first is a **cache hit** (one upstream call per query, ever) and slicing stays stable across pages. It shares the **never cache an empty result** rule, and the stakes are higher here: one entry backs *every* page of a query, so a cached empty would freeze the whole scroll rather than one page. Listings are **pruned before caching** to the mapped fields (plus `year`/`publishing`, one scalar each and the obvious next things a template could carry): the raw response for a common query is **~127 KB** of shop offers — price, currency, stock, per-shop URL, format flags, relevance — wrapped around the handful of bibliographic fields we copy, and one entry backs every page for 30 days. Measured on a real 200-listing response: **51% smaller serialized** (159 KB → 77 KB) and **2.8× cheaper to read back** (214 µs → 76 µs); Open Library's docs prune **61% smaller** (16.1 KB → 6.3 KB, 2.5× cheaper). The read-back figures are microseconds — this buys disk footprint, not perceptible latency. It supplies **no ISBN and no language** — so the language is **guessed from the title's script** (`App\Language\LanguageGuesser`; Cyrillic ⇒ **Ukrainian by default**, this being the Ukrainian market it indexes) — and the same book recurs across shops with **different cover URLs**, so results are collapsed on **title+author only** (not `BookTemplate::dedupeKey()`, which keys on the cover too). The highest-relevance listing keeps its position and its own values, but **any field it lacks is filled from a lower-relevance twin**: shops list the same book with different coverage, so the top hit is frequently the one missing the cover or blurb that the listing right behind it has. (Merging must not reorder the set — the windowing depends on that order.)

`App\Language\LanguageGuesser::guess(?string $title)` is the shared fallback both network providers use when a source gives no language. Detection is **script-based**: it names non-Latin scripts (Cyrillic, Greek, Hebrew, Arabic, CJK — kana⇒`ja`/Hangul⇒`ko`/Han⇒`zh` — Thai, Devanagari, Georgian, Armenian) but leaves **Latin-script titles null** (the alphabet is shared by too many languages). Cyrillic resolves to **Ukrainian by default**: only letters unique to Russian (`ыэъё`) tip it to `ru`; letters unique to Ukrainian (`іїєґ`) confirm `uk`. Every code it returns is a `LanguageCatalog` member, so a guessed value always passes `BookInput` validation when the template pre-fills the manual create form. The **site** source never guesses (its DB language is authoritative).

Frontend `BookTemplateSearch.vue`: a **source dropdown** (the shared `ui/BaseSelect.vue`, three options, default `site`) with the active source's hint beneath it, a **per-source debounce** (site 250ms, external & bookfinder 800ms — network sources wait long enough that letter-by-letter typing rarely fires an intermediate, soon-aborted upstream call), a **minimum query length** for the network sources (`MIN_QUERY_LEN`: external & bookfinder require 3+ chars before any request goes out — the broad `h`/`ha` calls are never sent; the site source has no minimum and shows a "Type at least N characters…" hint until met), and **infinite scroll** — an `IntersectionObserver` on a bottom sentinel (root = the scroll list) fetches the next `page` while `hasMore`, accumulating results and dropping exact cross-page repeats via a client-side seen-key set (title+author+isbn+lang+cover, matching the backend dedupe fields). Concurrency is a **search-generation guard**: `searchSeq` is advanced in the `watch` on **every** query/source change (not only inside `runSearch`), at the same moment the in-flight `AbortController` is aborted. Every request (initial *and* load-more) captures its generation and gates **all** shared-state writes — results, `hasMore`, and crucially the `searching`/`loadingMore` flags — on `seq === searchSeq`, so a superseded request is fully inert whether it rejects (aborted) or resolves after losing the abort race. Bumping the generation only inside `runSearch` (the earlier design) left an aborted request able to clear the loading flag of the newer pending search, which could strand the panel in a blank/false-empty state until a single clean request (e.g. switching source) reset it.

### Book collections
An owner groups their own books into named **collections** (`BookCollection`), shown as a **Collections** tab on both the Library (editable) and Profile (read-only) pages, and borrowable **as a whole or a partial selection**. A collection groups **≥2 of the owner's books of any status** (a lent/unavailable/reading book can be a member — it just isn't borrowable until it's `own` again); **borrowing** a collection requires **≥2 available books** (`CollectionService::MIN_BOOKS` / `CollectionRequestService::MIN_BOOKS`, mirrored in the DTOs' `Count(min:2)` and the modals' gates).

- **CRUD**: `GET /api/collections?owner=&page=` (paginated; private-profile 403 guard, viewer-relative `requested` flag on each book — the list eagerly hydrates each collection's books + categories in one follow-up query, `CollectionRepository::hydrateBooks`, so mapping the page never N+1s), `GET /api/collections/{id}`, `POST/PATCH/DELETE /api/collections/{id}`. `App\Service\CollectionService` (persist-never-flush) validates ownership + the ≥2 rule on the member books, of any status (books are resolved through `BookRepository::findByIdsForOwner`, owner-scoped, so a borrowed-in book — held but not owned — can never be added). The **active-loan guard lives solely in `App\Security\Voter\CollectionVoter`** (`COLLECTION_EDIT`/`COLLECTION_DELETE`: owner **and** not actively borrowed — which also drives the emitted `canEdit`), mirroring how `BookVoter` guards books; the controller runs it before edit/delete. Deleting a collection **cascades its whole borrow-request history away** (`collection_request.collection_id ON DELETE CASCADE`, matching `library_request.book_id`), so a collection that was ever borrowed-and-returned is still deletable; the `_audit` tables retain the trail.
- **Borrow lifecycle (whole-group)**: `App\Service\CollectionRequestService` creates a parent `CollectionRequest` plus one child `LibraryRequest` per selected book — **reusing `LibraryRequestService` per child** (so book-status changes, per-book audit events and the `requested` lock are all reused, zero duplication). The owner approves/declines/confirms-return once (one due date for the whole set); the borrower requests return once. Endpoints under `POST /api/collection-requests` (borrow, body `{collectionId, bookIds}`), `GET /api/collection-requests/{incoming,outgoing}?status=`, and `POST .../{id}/{approve,decline,return,confirm-return}` + `DELETE .../{id}` — same single-flush + status-keyword conventions as `/requests`.
- **Individual vs grouped lists**: `LibraryRequestRepository`'s individual incoming/outgoing lists (and the owner pending count) exclude collection children (`parentRequest IS NULL`) so a borrow surfaces **grouped**, not duplicated; the pending-book lookups (`findPendingBookIdsForRequester`, `findPendingForBookAndRequester`) deliberately **still see** children so collection-borrowed books stay locked and duplicate individual requests are blocked.
- **Real-time**: `LoanEventPublisher` emits exactly **one** `collection.*` signal per action (never one per book) — `LibraryRequestService` never publishes, so looping children through it stays silent; only the collection controller publishes. `useMercure.js` handles the six `collection.*` reasons.
- **Shapes**: `ResponseMapper::collection()` / `collectionRequest()` (the latter with a **synthesized** milestone timeline built from the parent's timestamps — there is no `CollectionRequestEvent` entity; children carry their own events). Both `BookCollection` and `CollectionRequest` are on the audit whitelist.
- **Frontend**: `stores/collections.js`; components under `components/collections/` — `CollectionCard` (portrait 2/3 cover to match book cards; **no on-card buttons** — the whole card opens the editor on the Library tab / the preview on a Profile, mirroring `BookCard`; an "On loan" badge marks a frozen one), `CollectionEditModal` (cover preview + URL at the top, then name/description + a **two-pane book picker** — the selected books above a **searchable** add-list — plus a Delete action and a read-only on-loan mode; mirrors `ManageBookModal`), `CollectionBorrowModal` (the collection parallel of `BookDetailModal`: a read-only **preview** — cover, owner, description, member books — that doubles as the borrow dialog for non-owners, locking non-`own`/already-`requested` rows and requiring ≥2; `isSelf` ⇒ pure preview), and — for the borrow lifecycle itself — no card of its own: a collection loan is rendered by the shared `library/LoanCard` alongside the per-book ones (see _Sharing tab_). Every collection card/request/loan is badged **"Collection"** so it's never mistaken for a single-book item.

### Wish list
Books a member **wants** rather than holds, kept on `Book` behind **`is_wished`** + **`wish_priority`** rather than in an entity of their own — so cataloguing a wanted book reuses the whole existing flow (template search, categories, cover localization, the Manage Book modal) verbatim, which is the point.

- **The two shelves never mix, and that is enforced query by query.** `BookRepository::onShelf()` is the single predicate every query in the class goes through, so "which shelf?" is a decision each one is forced to make instead of a default it can silently inherit. Excluded from: the owner's list, Discover, `searchTemplates` (the source is "templates on the site"; a wanted book's metadata is whatever someone typed off a shop listing), the subscription feed, `findByIdsForOwner` (so a collection can never contain one), every profile stat counter, and every analytics aggregate — `countAll`, `countByStatus`, `countByCategory`, `countByLanguage` and the growth series, the last via the `CountsCreatedByDay` trait's `scopeCreatedByDay()` hook (a no-op for `User`/`BookCollection`). A wish-list book also **can't be borrowed**: `LibraryRequestService::create()` rejects it explicitly, because no list surfaces one but the shelves must not be bridgeable by a hand-made request.
- **`WishPriority` is backed by an integer** (`1` can wait · `2` very interested · `3` urgent), so `ORDER BY wish_priority DESC` *is* the ranking. A string-backed enum would sort alphabetically and need a `CASE` in every wish-list query. The API emits the number; the SPA owns the traffic-light presentation (`assets/src/utils/wishPriority.js` — green/amber/red drawn from `--color-primary`/`--color-tertiary`/`--color-error`, no new hex), the same front/back split as `status`.
- **The flag and the level are one invariant**, kept by `Book::setWish($wished, $priority)`: null priority exactly when not wished, and a wished book always gets a level (`WishPriority::DEFAULT`). No query has to cope with a third combination. `BookInput.wishPriority` is therefore **normalised, not rejected** — a stale priority on an un-wished book is dropped rather than 422-ing about a field nobody was thinking about.
- **API**: `GET /books?wished=1` (with `&priority=` and `&sort=priority|newest`; an unknown sort falls back rather than 422s, matching `Pagination`'s clamp-don't-reject rule) serves the shelf through the standard paginated envelope. **`POST /books/{id}/acquire`** moves a book onto the owned shelf — its own endpoint rather than a `PATCH { isWished: false }` because it is the one thing a wish-list entry exists to become, it takes no other input, and the audit trail should show it as itself. It is idempotent (acquiring a shelved book is a no-op, not a 409).
- **Activity is deferred, not skipped.** `BookService::create()` withholds the `AddedBook` event for a wish-list book — a book nobody can borrow has no business in every follower's feed — and `acquire()` records it then, which is the moment the community actually gained it.
- **Published on the share page**, via `ResponseMapper::publicBook()`'s whitelist (`?wished=1` on `/api/public/users/{id}/books`): "books I'd like" is a normal thing to share a link to, and neither field is viewer-relative or names a third party.
- **CSV carries both shelves** in one file (`wished`, `priority` columns) — see _Import / export_. The dedupe key includes the shelf, so wanting a book is not a duplicate of owning one.
- **Frontend**: the `wishlist*` slice of `stores/library.js`, the Wish List tab in `LibraryView` (cards *and* table, via `BookTable`'s `wish` prop — the priority replaces the status column and the holder column is dropped), the wish checkbox + priority picker in `ManageBookModal` (create mode opens pre-ticked from the Wish List tab and from the mobile FAB while that tab is active), and read-only shelves on `ProfileView` / `PublicLibraryView`. `utils/bookPayload.js`'s `toBookInput` **must** carry both fields — the endpoint maps the whole DTO, so omitting them would move a wanted book onto the shelf on any inline edit.

### Pagination
List endpoints that can grow unbounded are **offset-paginated** behind one shared shape. `App\Dto\Pagination::fromRequest($request, $defaultPerPage)` parses `?page=&perPage=` — input is **clamped, never rejected** (`page ≥ 1`, `perPage ∈ [1, 100]`; garbage ⇒ default), so a browse UI never 422s on a stray param. Repositories return `App\Dto\PaginatedResult` (`items` + `total`), and `ResponseMapper::paginated($items, $total, $pagination, $mapFn)` emits the **one envelope** every paginated endpoint uses:

```json
{ "items": [ … ], "pagination": { "page": 1, "perPage": 24, "total": 137, "totalPages": 6, "hasMore": true } }
```

Per-list page sizes are **controller constants** (the "reasonable preset" per list): collection & Discover books **24**, Discover accounts **18**, loan History & Following **20**. A page of reader cards resolves its stat counters through `UserStatsProvider::forUsers()` — four grouped counts for the whole page (`BookRepository::countByOwners`/`countShareableByOwners`/`countByOwnersAndStatus`, `CollectionRepository::countByOwners`) instead of `forUser()`'s four per row; single-profile endpoints keep `forUser()`. Repos page via Doctrine `Paginator` when the query fetch-joins to-one associations; the History queries page on **root fields only** then hydrate the to-many `events` in a **second query** (`LibraryRequestRepository::paginateWithEvents`) — the Paginator can't page a fetch-joined collection, and lazy events would N+1.

**What paginates (browse/growing):** Library collection & profile shelf (`GET /books`), Discover books (`/books/discover`) and accounts (`/users/discover`), the settled loans behind Sharing's Finished filter (`/requests/{incoming,outgoing}?status=resolved`), and the **Following** list (`/subscriptions`). **What deliberately stays a bare array** (the documented "real excuse" — naturally bounded and, for loans, refetched wholesale on Mercure signals): the in-flight request slices (`/requests/*` for `active`/`pending`), the **subscription feed**, and the **categories** vocabulary (consumed whole by pickers/pills). The Sharing panel reuses the `/requests` endpoints and returns the envelope **only** for the unbounded slices — `status=all` and `status=resolved` (`isPaginatedSlice()`); the in-flight keywords keep the bare array.

Frontend: the numbered control is the shared `ui/Pagination.vue` (prev/next + page numbers with ellipsis; renders nothing for a single page) — never hand-roll list paging. **Changing page scrolls back to the top of the list**, via `utils/scroll.js`'s `scrollToTopOf()`: paging is something you do from the bottom of a list, and landing on row one of the next page is what was meant. Three properties worth keeping: the region is the pager's own **`parentElement`** (in all eleven call sites the pager is the last thing inside the element holding the list, so no caller has to wire a target and none can wire a wrong one); the scroll is **one-way** — it never pushes the reader *down*, so a short list fully on screen doesn't jump; and it fires **immediately**, not after the fetch, since the region's top doesn't move while the page loads. The sticky header's height is measured at call time rather than tokenised, because it differs between the phone and desktop layouts, and `prefers-reduced-motion` drops the smooth behaviour. Paginated stores hold `{ items, page, perPage, total, totalPages }` and expose `fetchX(page)` that **replaces** the page. Refetches triggered by Mercure default their `page` arg to the current page so a signal never yanks the user back to page 1.

### Image localization & caching
Remote images (Google avatars, Open Library / bookfinder / pasted book covers) are **downloaded once, server-side, to our own origin** so the browser never hotlinks — and gets 429'd by — a third-party CDN, and so nginx can hard-cache them. `App\Service\ImageLocalizer::localize(?string $url, string $category)` fetches an `http(s)` image (5 MiB cap, content-type allow-list), names it by **xxh128 content hash** (identical bytes ⇒ same file = dedup + implicit change-detection; the extension encodes the type) and persists it via `App\Service\Storage\ImageStorage`. It is **best-effort**: a null/empty/already-owned/non-http input is returned unchanged, and any fetch/validation failure logs and returns the original URL (worst case: the old hotlink). `owns()` delegates to the store.

`ImageStorage` is a **swappable backend** (only the persist step): `LocalImageStorage` (the default, aliased in `services.yaml`) writes to `public/uploads/{category}/` and returns `/uploads/{category}/<hash>.<ext>`; nginx (`docker/local` + `docker/production`) already serves `/uploads/` `expires 1y, immutable`. To move to DO Spaces / S3 later, implement `ImageStorage` and repoint the alias — `ImageLocalizer` and callers don't change. Categories are `ImageLocalizer::AVATARS` / `COVERS`.

**Where localization runs (on write):** `AuthRestController` localizes the Google avatar (only avatars we own — null / already-localized / a `googleusercontent.com` URL — never a URL pasted in Settings); `BookRestController` create/update localizes `BookInput.coverPath` before persisting. **CSV import deliberately does not** localize (a 1000-row import would fire 1000 synchronous downloads) — imported covers stay remote and are picked up by the backfill.

**The original URL is never lost.** Localization replaces the stored value, and the filename is a hash of the *bytes*, so the source can't be recovered from it — every call site therefore records it in a companion column: `book.cover_source_url` and `user.avatar_source_url`. The rule is uniform: the source is the input URL **only when `localize()` actually replaced it** (it returns its argument for a failed fetch or a URL we already host ⇒ the stored value *is* the remote one ⇒ source stays null). It is re-pointed **only when the image itself moves** — `BookService::applyInput` compares the new cover path against the old one, so an unrelated edit through the Manage Book modal (which echoes the localized `/uploads` path back) keeps the link, and `MeRestController` clears the avatar source when a pasted URL replaces a localized one. Consumer today: **CSV export** (see _Import / export_); the API deliberately does **not** emit either field.

**Backfill:** `php bin/console app:localize-images` (manual — makes outbound HTTP) scans every `Book` cover and `User` avatar still pointing at a remote CDN and localizes it, flushing in batches of 50; idempotent (owned URLs skipped) and best-effort per row. `--dry-run` lists candidates without downloading.

### Authorization (voters)
`App\Security\Voter\BookVoter` decides `BOOK_EDIT` / `BOOK_DELETE`: the actor must be the **owner** *and* the book must be **home** (`isHome()`) — a book that's out on loan is frozen. Controllers call `denyAccessUnlessGranted(...)`; `ResponseMapper` emits a **`canEdit`** boolean on every book so the SPA disables the Manage Book modal without re-deriving the rule client-side. Private profiles: `UserRestController::show` returns 403 to non-owners (mirrors the private-library book listing) — through `App\Api\MemberVisibility`, which also collapses a suspended or deleted member into a **404** (see _Admin panel_).

The **reason** a voter gives (`denyAccessUnlessGranted`'s third argument, e.g. `BookRestController::lockedMessage()`) is user-facing: `ApiExceptionSubscriber` turns it into a translated `{ error }` 403 rather than letting the kernel emit an untranslated problem-details `detail`. Write those messages as plain English sentences — they double as translation ids (see _Internationalization_).

**A denial raised by `access_control` has no such reason**, and the subscriber detects that rather than echoing it. Symfony composes the message from the access decision — `"Access Denied. The user doesn't have ROLE_ADMIN."` — which matches no catalog key (so it reaches the reader in English whatever they asked for) and names internal role vocabulary. The check is **structural, not string-sniffing**: every path that supplies a message of ours leaves it differing from `AccessDecision::getMessage()`, so `message === decision message` means nothing was authored and the subscriber substitutes a sentence we own, chosen by path prefix (`App\Security\AdminAccess`, else `'Access denied.'`). This is why an `#[IsGranted]` attribute's `message` on a path the rule already guards never renders — the rule fires first. The attribute is the second layer, not the message source; both read the same constants so they cannot disagree.

**Roles.** `User.roles` is a JSON grant list holding *extra* grants only — today just `ROLE_ADMIN`. `ROLE_USER` is merged in by `getRoles()` and never stored, so an ordinary member's column is `[]` and "no grants" and "ordinary" can't drift apart. Plain `JSON`, **not JSONB**: it's never queried into, and Doctrine's `json` type maps to `JSON`, so JSONB would make every `migrations:diff` emit a phantom `ALTER … TYPE`. Grant with **`php bin/console app:grant-admin <email> [--revoke]`** — there is deliberately no endpoint. It goes through the ORM so `user_audit` records the change; raw SQL would bypass that and one malformed JSON literal would break `getRoles()` for that user. **The JWT carries nothing**: the firewall reloads the user from the DB each request, so a grant *or revoke* takes effect on the next request with no re-login. The API emits a `isAdmin` **boolean** (not the role array) on the login payload and `ResponseMapper::me()` only — never on `profile()`/`userCard()`/`userSummary()`/`public*()`, since who the operator is isn't community-visible. `me()` is load-bearing: the SPA persists the login payload in `localStorage`, so it's the only path by which a grant made *after* sign-in reaches a live session.

### Rate limiting
`config/packages/rate_limiter.yaml` defines five limiters — `auth_ip` (per-IP, guards `/api/auth/*`), `api_user` (per authenticated user), `api_ip_user` (IP+user), `public_ip` (the share pages), and `pageview_ip_user` (the traffic beacon: token bucket, burst 30, sustained 6/min — far tighter than `api_user`'s 300/min, because a valid token could otherwise burn 300 counter increments a minute and nobody navigates a SPA six times a second). `App\EventSubscriber\RateLimitSubscriber` applies them on `kernel.request` at **priority 6** (after the firewall at 8, so the user is resolved). Over-limit → **429 + Retry-After**. The `when@test` block raises limits so tests aren't throttled. **Branch order in the subscriber is load-bearing**: `/api/public` must return before the token storage is read (on a lazy firewall, reading it forces the deferred authentication), so the `/api/pageviews` branch sits *after* it.

### Audit trail
`damienharper/auditor-bundle` (`config/packages/dh_auditor.yaml`) writes an `<table>_audit` companion (insert/update/delete diffs + acting user) for a **whitelist**: `Book`, `User`, `Category`, `LibraryRequest`. Append-only logs (`ActivityItem`, `LibraryRequestEvent`) are intentionally excluded — and so are `PageViewDaily`/`PageViewVisitor`, for the same reason: auditing a hit counter would double every write and produce a diff log longer than the data. Because `User` *is* on the list, every admin grant and revocation is recorded for free — and so is every **suspension, reinstatement and account deletion** (`user_audit` stores a generic `diffs JSON`, so neither `roles` nor the moderation columns needed an audit migration). That trail is the reason `UserPurger` anonymizes rather than deleting: it is the only place an operator can afterwards answer "what happened to this account". The bundle's web **viewer is disabled** (this is a JSON API); its Twig/asset/translation deps come along only to satisfy the bundle and are unused. Pinned to `6.3.*` because 7.x requires Symfony 8.

### Real-time (Mercure / SSE)
Loan-lifecycle changes are pushed to clients over **Server-Sent Events** through a **standalone Mercure hub** (the `mercure` Docker service, `dunglas/mercure`) — long-lived connections live on the Go hub, never on the 5-worker PHP-FPM pool. Config: `config/packages/mercure.yaml` + `MERCURE_URL` / `MERCURE_PUBLIC_URL` (kept **relative** so the subscribe-cookie follows the serving host) / `MERCURE_JWT_SECRET` in `.env`. **`MERCURE_JWT_SECRET` must be non-empty**: blank makes Symfony throw *"Key cannot be empty"* and `GET /api/mercure/token` **500** on every call, which the SPA retries forever — real-time looks broken with no other clue. The hub hides it, because `compose.yaml` falls back to `${MERCURE_JWT_SECRET:-!ChangeThisMercureSecret!}` while Symfony has no such fallback. In dev remember Symfony reads **`.env.local.php`**, so the value has to be set there *and* in `.env` (which feeds compose) — and the two must **match**, or the hub rejects every signed token. Nginx proxies `/.well-known/mercure` to the hub with **buffering and gzip off** and request-time DNS resolution.

Design is **signal-and-refetch, not state-push**: after a transition commits, `App\Service\LoanEventPublisher` publishes a **private** `{ reason, requestId }` signal to the affected user's `user/{id}` topic. Publishing happens **after `flush()`** (the controller boundary) so any client refetch reads committed truth, and it is **best-effort** — a hub outage is logged, never fails the transition. The SPA (`assets/src/composables/useMercure.js`) shows a toast and refetches the affected lists via the **existing authenticated store actions**, so authorization stays in the REST layer and the channel is reconnect/race-safe.

- **Recipients:** `request.received` / `return.requested` / `request.cancelled` → book **owner**; `request.approved` / `request.declined` / `return.confirmed` → **requester**. (`request.cancelled` fires when a borrower withdraws a pending request; since the row is deleted, the controller captures the owner id + request id before flush and calls `LoanEventPublisher::publishToUser(...)` after.)
- **Subscriber auth:** EventSource can't send the JWT header, so `GET /api/mercure/token` (`MercureRestController`) mints a signed, HttpOnly subscribe-cookie scoped to the caller's **own** `user/{id}` topic; the `private` flag enforces per-user isolation at the hub.
- **Reconnect:** the composable refreshes the cookie and reconnects with backoff, and on reconnect refetches every loan list to catch signals missed during the gap (the cookie's JWT expires ~hourly).

### Frontend imports & UX patterns
- The `@` alias resolves to `assets/src/` — `import Foo from '@/components/Foo.vue'`.
- **Errors → toasts, not error pages.** `AppErrorBoundary` only catches truly unexpected render errors (→ `ErrorView`); expected API failures must be caught locally and surfaced via the `toast` store (`toast.error(apiErrorMessage(e, fallback))`). `utils/apiError.js` reads RFC7807 `detail`, then `error`, then `message`. Server text arrives **already translated** (the axios interceptor sends `Accept-Language`), so only the local `fallback` needs `t(…)`; omit it and `apiErrorMessage` resolves `errors.generic` itself. `<ToastHost>` lives at the App root.
- **Loading states** use shimmer skeletons (`ui/BaseSkeleton`, `BookCardSkeleton`, `BookGridSkeleton`, `UserCardSkeleton` for reader cards) and `BaseSpinner` (also for in-button loading), never bare "Loading…" text. `ui/StatusScreen` renders empty/error states.
- **Cover images.** Every surface renders `<img v-if="…coverPath">` with a `menu_book` placeholder as the `v-else`. That only covers a *missing* cover — a URL that 404s leaves the browser drawing the alt text inside the frame — so the `v-if` goes through **`composables/useCoverFallback.js`** (`hasCover(bookOrUrl)` + `@error="onCoverError(key)"`), which routes a dead link to the same placeholder. State is per-consumer, so an unmount forgets and a flaky URL gets another chance. Any new cover surface must use it.
- **State** lives in Pinia stores (`auth`, `library`, `discover`, `profile`, `toast`); use `storeToRefs` to keep reactivity when destructuring. A "previous value" snapshot that a `computed` compares against must itself be **reactive** — `SettingsView`'s `original`/`originalPrefs` were plain `let` objects, so re-hydrating them after a save never invalidated `dirty` and the Save button never re-disabled.
- **A chunk 404 is a blank page, not an error screen.** Routes are dynamic imports, so a browser on a superseded `index.html` requests hashes that no longer exist; `AppErrorBoundary` never sees it because the navigation itself fails. `router.onError` reloads once (guarded by a `sessionStorage` flag so a genuinely broken chunk surfaces instead of looping). It can only help browsers that already have this code — a browser stuck on an older entry still needs one hard reload.
- **Book detail modal.** Clicking a book you can't edit opens the read-only `ui/BookDetailModal.vue` — a large cover, full metadata (status pill, owner link, language, ISBN, category chips) and the **complete `description` in normal top-to-bottom flow** (`white-space: pre-line`; the info column scrolls if it overflows). It carries a footer "Request to Borrow" action mirroring the card button states, emitting `request` (parents reuse their existing `onRequest`/`requesting` set) and `close` (also Escape / overlay-click); an **`isSelf`** prop suppresses that footer button (you can't borrow your own book — the footer shows only Close). It opens from `DiscoverBookCard` (Discover + Following feed) and from **every** `BorrowBookCard` on a profile via `@open` — **including your own profile** (the profile book section is read-only: own cards show no action button and open this preview, not the Manage Book editor; that editor lives only in `/library`). There is no hover/tap blurb overlay — the modal replaced it (the old `ui/BookBlurb.vue` clipped the start of long text and was removed).
- **Card ↔ table view.** Every card-only book list (Library **Books** tab, Profile shelves, Discover **books** mode) can switch between the cover grid and a compact `ui/BookTable.vue`. Two `localStorage`-persisted, app-wide preferences live in the `useBookView()` composable (singleton `ref`s): **`bookView`** (`cards | table`, key `bookView`) and **`tableDetailed`** (key `bookTableDetailed`). Both are driven by the shared `ui/ViewToggle.vue` segmented control, whose third "all columns" segment appears only in table mode (`v-model:detailed`).
  - **Columns are context-driven props**, not one fixed set: the essential columns are read · cover · title+author · language · status; `detailed` adds categories · description (one line, ellipsis) · ISBN · holder · added; `showOwner` adds the owner (Discover only — elsewhere every row shares one owner, and only `ResponseMapper::discoverBook()` emits `owner`). "Added" comes from the book's **`createdAt`** (the entity always had `created_at`; the mapper emits it as ATOM), rendered through `utils/time.js`'s `relativeTime()`.
  - **Narrow screens scroll, never truncate.** The table sits in a `.book-table__scroll` (`overflow-x: auto`) with a `min-width` per density, so a phone pans sideways instead of losing columns. Loading uses `ui/BookTableSkeleton.vue` (not `BookGridSkeleton`) whenever the table is the active view, so the layout doesn't jump on load.
  - **The read cell** is an interactive toggle only where **`readEditable`** is set — the owner's own Library — *and* the server's `canEdit` is true; everywhere else it's a static filled/outlined icon, never a dead disabled checkbox. The toggle emits `@toggle-read` → `library` store's `setBookRead(id, isRead)`: an optimistic flip that PATCHes the whole `BookInput` via `utils/bookPayload.js`'s `toBookInput` (the endpoint maps the full DTO) and reverts on failure. `profile`/`discover` deliberately have no such action.
  - A row click opens the same modal the card does (`@open`). Because the grid's leading "Catalog a New Book" cell has no table equivalent, the Library toolbar grows an **Add Book** button in table mode. Scope is deliberate: Library = Books tab only (not the Sharing panel's loan grids), Discover = books mode only, Collections untouched.
- **Consistency by default.** Styles and interaction patterns must stay consistent across the app — reuse the existing shared component/token rather than hand-rolling a one-off. Diverge only for a real reason (a genuinely different affordance or requirement), not convenience. Dropdowns are the shared combobox look: `ui/LanguageSelect.vue` (searchable) and `ui/BaseSelect.vue` (plain option list) — never a bare native `<select>`. Text-search boxes are `ui/SearchInput.vue` (search icon + native `type="search"`, self-owned debounce, emits `search`; a right-side `BaseSpinner` while a search is pending/`loading` — matching `BookTemplateSearch` — else a clear button once there's text); it drives the `?q=` filter (title/author/ISBN) on the library collection and profile shelves, taking the list's loading flag via the `loading` prop. It's **uncontrolled** (owns its own text) — reset it by remounting via `:key` (ProfileView keys on profile id + shelf so a filter never leaks across them). **An uncontrolled input inside a `v-if`-ed branch desyncs from any filter that outlives it**: `LibraryView`'s Books panel unmounts on a tab switch while the store keeps both `collectionQuery` and the filtered page, so the list came back filtered behind an empty box. Pass **`:initial`** (seeded on mount only, so it never fights typing) wherever the parent's filter survives the unmount.

### Internationalization
The UI ships in **five languages** — `en · de · es · fr · uk` — and the API's error text follows the same choice.

**Where the reader changes it:** `ui/LocaleSwitcher.vue`, a small non-blocking popover (icon + active code, endonym list) hosted by `AppHeader`, `PublicHeader` and the login card — never a modal, and deliberately **not** a Settings section (it used to be one; a control whose point is "I can't read this screen" can't sit three navigations deep). The component only applies the language (`setLocale`) and emits — **persistence is the host's job**, because it differs: `AppHeader` fire-and-forget `PATCH`es `/me/settings` (a failed save must not toast, the UI already switched), while the signed-out hosts call `markPendingLocale()`. `SettingsView` therefore no longer carries `locale` in its `prefs` at all, and its `hydratePrefs` copies **key by key** rather than `Object.assign`-ing the response — spreading it back in would let a Save re-commit whatever language was current when the page loaded, undoing a switch made in the header since.

**The locale allow-list is duplicated front+back and must stay in sync** (same convention as _Categories_' palette): backend `App\I18n\LocaleCatalog::LOCALES` (code ⇒ endonym, `DEFAULT = 'en'`, plus `negotiate()` for regional tags like `uk-UA`), frontend `SUPPORTED` in `assets/src/i18n/index.js` alongside one JSON catalog per locale under `i18n/locales/`. There is deliberately **no `/api/locales` endpoint** — unlike book languages, the SPA has to bundle a message catalog per locale anyway, so the list is inherently frontend-owned. Adding a language means touching both halves plus a new catalog file.

**Frontend** — `vue-i18n` v11 in Composition mode (`legacy: false`), `fallbackLocale: 'en'`, catalogs **bundled** (a language switch must be instant). Keys are namespaced by area (`common.*`, `nav.*`, `library.*`, `discover.*`, `profile.*`, `settings.*`, `collections.*`, `requests.*`, `book.*`, `table.*`, `ui.*`, `auth.*`, `errors.*`) — never sentence-as-key. **`setLocale(code)` in `i18n/index.js` is the single entry point**: it moves the i18n locale, sets `<html lang>`, and persists to `localStorage.locale`. Conventions worth knowing:
- **Ukrainian needs three plural forms** (one/few/many) — a custom `pluralRules.uk` provides them; every counted message carries 3 forms in `uk.json` and 2 elsewhere.
- **Sentences that wrap markup** (a book title in `<em>`, a name in `<strong>`, the CSV column list in `<code>`) use `<i18n-t>` with named slots, so word order round the inserted value stays the translator's choice instead of being spliced from fragments.
- **A prop default can't be resolved against the active locale**, so components whose defaults were English literals (`SearchInput`/`BaseSelect`/`LanguageSelect` placeholders, `ErrorView`'s message) default to `null` and fall back through the catalog in the component.
- **Never name a `v-for` variable `t`** — it shadows the translation function in the same template (this bit `BookTemplateSearch` and `ToastHost`; they use `tpl`/`item`).
- Dates/relative times go through `utils/time.js`'s `relativeTime()` and `currentLocale()`, never `undefined`-locale `toLocale*String`.
- **Book language names** are re-derived from the ISO code per locale by `utils/languages.js`'s `languageLabel(code, fallback)` (`Intl.DisplayNames`), falling back to the server's English `languageName`. `LanguageSelect` also **re-sorts** the vocabulary, since alphabetical order changes with the names.
- The **Release Notes prose** in `assets/src/data/changelog.js` stays English by design (historical technical record); only the page chrome is translated.

**Backend** — the request locale comes from **`Accept-Language`**, negotiated against `LocaleCatalog` by `App\EventSubscriber\LocaleSubscriber` (`kernel.request`, priority 20). The SPA's axios interceptor sends the locale it renders in (read from `localStorage`), so this costs no DB query and never fights an unsaved switch. `UserSettings.locale` exists **only** so the choice follows a user to another device. It is **nullable, and null is meaningful** — "never picked a language", as distinct from "picked English". The SPA adopts the stored locale only when there is one; a `NOT NULL DEFAULT 'en'` column made the two indistinguishable and let the SPA silently overrule the locale the browser had negotiated.

**Sign-in is where the browser's language and the account's are reconciled** (`GoogleCallbackView.reconcileLocale`, best-effort — a preference never fails a sign-in): a language picked on the login page wins and is `PATCH`ed onto the account (that's the point of offering the picker before signing in — it's parked in `sessionStorage` by `markPendingLocale`, and consumed by `takePendingLocale`; deliberately *session*, so a days-old flag can't rewrite the account's language on the next login); otherwise the stored locale is fetched and adopted, which is what carries the choice onto a new device. `PATCH /api/me/settings` is partial, so `{ locale }` alone is a valid body.

**The English sentence is its own translation id.** `App\Api\ApiError` (injected into every controller that emits a message) builds the `{ error: … }` body through the translator, so an untranslated locale renders the English text and `en` needs no catalog — only `translations/messages.{de,es,fr,uk}.yaml` and `validators.{de,es,fr,uk}.yaml` (the 14 custom `Assert` messages; Symfony's own constraint messages translate for free). `ApiError::translate()` is the single translation entry point, so the messages that *aren't* response bodies go through it too — the 429 the SPA renders from `detail` (`RateLimitSubscriber`) and the CSV importer's own row errors (`BookCsvService`), which the Import modal prints verbatim. Ids carrying values use `%name%` placeholders rather than `sprintf`'s `%s`/`%d`, since a format string can't be a catalog key. **`tests/I18n/TranslationCoverageTest`** walks `src/` with PHP's lexer and fails when a user-facing English sentence has no entry in every non-default locale, plus pins key parity and flags dead keys — CLI output (`src/Command/`) and internal `InvalidArgumentException` text are explicitly exempt. Business-rule violations throw **`App\Exception\DomainRuleException`** — a `\DomainException` (so the existing `catch` blocks are untouched) that keeps its *parameterised* id and params next to the rendered English `getMessage()`; the two "at least N books" rules could never match a catalog key as concatenated strings. `App\EventSubscriber\ApiExceptionSubscriber` gives voter/ownership denials the same translated `{ error }` 403 shape (previously an untranslated problem-details `detail`), matching both `AccessDeniedException` and the firewall's `AccessDeniedHttpException` wrapper and running late enough (-64) to leave 401 challenges alone.

### Public library access
A member can share their library with people who have no account: **Share** in the Library Books toolbar (`SharePublicLinkModal.vue`) hands them `/public/library/{id}` plus a QR. Visibility is governed **entirely by the existing `User.isPrivate` toggle** — there is no share-token entity and nothing to revoke, so a private profile simply has no working link (the modal says so and links to Settings instead).

- **`/api/public` sits behind its own `security: false` firewall**, declared *before* `main` in `config/packages/security.yaml`. This is load-bearing, not a shortcut: with the authenticating firewall active, the lexik authenticator runs whenever an `Authorization` header is present and **throws on an expired token — a 401 regardless of `PUBLIC_ACCESS`**. Any member whose session had gone stale would be bounced off a shared link and have their credentials wiped by the SPA's 401 interceptor. Patterns are anchored `^/api/public(/|$)` so they can't spill onto a future `/api/publications`.
- **`PublicRestController` must never call `getUser()`** (or touch token storage). There is no token to read, so a stray call returns null and silently reintroduces viewer-relative output rather than failing. `PublicAccessConfigTest` lexes the file and forbids the identifier.
- **Shapes are separate whitelists**, not the member shapes minus a few keys: `ResponseMapper::publicBook()` / `publicCollection()` / `publicProfile()`. They drop `currentHolder` (while a book is lent that names the **borrower** — a third party who never agreed to appear on someone else's public page), `owner`, `canEdit`, `isHome`, `requested`, and every profile field except name/avatar/bio. **The nested case is the trap**: `collection()` maps members through `book()`, so a public collection built on it would republish the borrower one level down while the top level looked clean. `PublicShapeTest` asserts exact `array_keys()` — a whitelist, so a field added to `book()` later fails there instead of quietly shipping.
- **A private member and a non-existent one return the same 404.** Ids are sequential, so a distinguishable 403 would make the id space a membership oracle. (The authenticated endpoints keep their 403 `'This library is private.'` — there the caller is already a member.) Public `perPage` is fixed at 24 rather than honouring the shared 100 ceiling, and `page` is capped.
- **Rate limiting is two-layer.** `RateLimitSubscriber` returns on the public prefix *before* reading token storage — on a `lazy` firewall that read forces the deferred authentication — and consumes a dedicated `public_ip` limiter (60/min). But it runs *inside* PHP, so rejecting still costs one of the five FPM workers; the limit that protects availability is nginx's `limit_req`/`limit_conn` on `location /api/public/`, in **both** `docker/production` and `docker/local`.
- **QR** is server-rendered by `endroid/qr-code` via `GET /api/public/users/{id}/qr.svg` — **`SvgWriter`, deliberately**: `PngWriter` needs `ext-gd`, which this project doesn't enable. The endpoint is public so an `<img>` (which can't send a Bearer header) can load it. Its URL comes from the **request host**, and the modal's copyable link from `window.location.origin` — never `DEFAULT_URI`, which is `http://localhost` in both `.env` and `.env.local.php`.
- **Frontend**: `stores/public.js`, `PublicLibraryView.vue`, and `AppLayout`'s `variant="public"` (brand + Sign in via `PublicHeader.vue`, no bottom nav) — `AppHeader`/`MobileBottomNav` link only to gated routes. `/changelog` and the 404 catch-all are **also public**, since the footer's only link points at the former and a mistyped share link should 404 rather than redirect to `/login`. `assets/src/api/index.js` skips both the `Authorization` header and the 401 redirect for `/public` URLs. Read-only affordances reuse existing props (`is-self` on the cards, `readonly` on `CollectionBorrowModal`, `show-holder="false"` on `BookTable`) rather than new rendering.
- **Known consequence of the id-based model** (accepted deliberately): non-private libraries are enumerable by walking ids, which effectively un-gates the member directory. If that ever needs closing, a deterministic HMAC suffix on the URL (`/public/library/42-9f3a1c7e`, verified with `hash_equals`) does it with no entity and no migration. Indexing is currently *allowed* — there is no `noindex` — but the SPA is client-rendered with no SSR or sitemap, so link previews and search results are unreliable until OG tags are server-injected.

### Admin panel

The operator's tools, at `/admin`. `AdminView` is a shell owning the layout, the page header and a `ui/SubTabNav.vue` strip; each section renders into its `RouterView` as a bare panel. The analytics dashboard used to *be* `/admin` and carried all three itself — it keeps its path **and its route name**, so bookmarks and the `meta.admin` guard are untouched (children inherit parent meta). The account dropdown links to `/admin`, never to a section: a dropdown growing an item per admin screen would be the nav-shape problem the entry was moved out of the nav strip to avoid. `AnalyticsRoutes` excludes the whole **`admin-*`** family, so an operator flipping between their own tools can't top the traffic list they are reading.

Every controller under `/api/admin` is gated **twice** — the `access_control` rule is the coarse net covering any controller added later, the `#[IsGranted]` attribute is the second layer that still refuses if the rule is ever narrowed. The rule denies **first**, so the attribute's `message` is not what a refused caller reads: `ApiExceptionSubscriber` supplies that (see _Authorization_). `App\Security\AdminAccess` holds the role, the path prefix and the sentence as constants so the attribute, the subscriber and the YAML rule cannot drift; `AdminAccessConfigTest` sweeps `src/Controller/Admin*RestController.php` with a data provider — through **reflection on the resolved attribute**, not by lexing source, so a constant reference counts — and pins the prefix against the shipped rule.

**Members** (`AdminUserRestController` at `/admin/users`, `stores/adminUsers.js`, `AdminMembersView`). `GET ?q=&status=all|active|banned|deleted&page=` is the standard paginated envelope over `UserRepository::findForAdminPaginated` — the one query that deliberately does *not* apply `VisibleUsers` (showing those rows is its job) and the one search allowed to match on **email**, since a community-facing email lookup would turn Discover into an address-book oracle. `ResponseMapper::adminUser()` is a standalone whitelist in the `publicBook()` spirit, pinned by `AdminShapeTest`: it is the only shape in the API that may publish an email address or an `isAdmin` flag, and making it standalone means the next field has to be added here on purpose. Page counts come from `UserStatsProvider::forUsers()` — four grouped queries, never four per row. A table rather than the Discover reader-card grid: a card is built to make somebody look worth following, which is the opposite of what an operator scanning for one row needs.

**Suspending** is `banned_at` + `ban_reason` on `User`, and it is **two mechanisms, deliberately separate**:

- **Sign-in stops**, enforced by `App\Security\UserChecker` on the `main` firewall (`user_checker` in `security.yaml`). The firewall reloads the user from the database on every request — the same property that lets `app:grant-admin` apply without a re-login — so **an already-issued JWT dies on the suspended member's next call**, which is why this needs no token-revocation path. `checkPreAuth`, not `checkPostAuth`: a stateless JWT firewall performs no fresh credential check per request, so the rule would simply not run there. `AuthRestController` carries its **own** branch, because the Google callback mints by hand and never passes through the checker; without it a suspended member could sign in freshly and hold a valid token until their next request bounced them.
- **Their content leaves the community**, enforced by `App\Repository\VisibleUsers::scope()` applied **query by query** — the same decision `BookRepository::onShelf()` makes, and for the same reason: a Doctrine filter is a default, and the admin list, the analytics aggregates and every owner-scoped guard all need to see exactly the rows it hides. Applied to Discover books and readers, the template search (which spans *every* library, private included, and is therefore the easiest one to forget), and all three subscription list queries. `VisibleUsersTest` is DB-backed and asks each surface the question directly, because forgetting produces no error — just a suspended member quietly back in Discover.

`App\Api\MemberVisibility` is the controller-side half: **suspended or deleted ⇒ 404**, the same one a member who never existed gets (ids are sequential, so a distinguishable status would let anyone walk the id space and read off who the operator has suspended); **private ⇒** the endpoint's existing 403 with its own wording, since there the caller is already a member and the distinction is useful. The viewer themself is never checked — the `UserChecker` refuses them before a controller runs. `PublicRestController` folds the same check into `findShared()`, and `LibraryRequestService::create()` rejects borrowing from an inactive owner so the two shelves can't be bridged by a hand-made request.

**Deleting is a soft delete**, `App\Service\Admin\UserPurger` (persist-never-flush). `book.owner_id`, `book_collection.owner_id` and both `requester_id` columns reference `user` with **no ON DELETE rule**, so a hard delete either fails or — had we added cascades — would take the *other* party's loan history with it: a member who lent thirty books would erase thirty borrowers' records of having returned them. So the row survives, anonymized: `deleted_at` stamped, name/bio/location/avatar blanked, `is_private` forced and roles dropped (belt and braces behind the visibility predicate), and **`email`/`google_id` rewritten** to `deleted-{id}` forms — both are UNIQUE lookup keys, and leaving them would let `findOrCreateFromGoogle()` resurrect the anonymized row on the next sign-in. Everything hanging off the account goes: both shelves (`wished: null`), every collection, their own requests, follows in both directions, activity and settings. Two subtleties: **books they were borrowing go home** (`current_holder = owner`, `status = own`), or the lender is left with a book stuck in `lent` held by an account that can no longer confirm a return; and somebody else's activity row pointing at them is **unlinked by hand**, because `ActivityItem.target_user`'s `ON DELETE SET NULL` never fires when the row survives. Idempotent. `UserPurgerTest` is DB-backed — what can go wrong here is the FK ordering, not the PHP.

`App\Service\Admin\AdminUserService` holds the rules, as `DomainRuleException` → 409: you cannot act on **yourself** (an operator who suspends themself is locked out of the panel that would undo it), nor on **another administrator** (the same lockout one step removed, and with two operators a mutual-destruction button — demotion stays console-only), nor delete anyone with a **live loan** (`LibraryRequestRepository::hasActiveLoanInvolving`), whose counterpart would otherwise lose the record of a book physically in someone's hands; the message names suspending as the alternative. The SPA mirrors all three so a disabled control explains itself rather than producing a 409 on click, and asks for the member's **email typed out** before deleting — the only irreversible action in the product, where the mistake worth preventing is acting on the wrong row in a table of near-identical rows.

**Not applied to the dashboard.** `StatsProvider` counts suspended and deleted members like any other: an operator reading their own numbers wants the truth, including the accounts they removed.

> **Manual dumps are stage 2** and not built yet — both a `pg_dump` download and a JSON export, behind their own admin section and their own rate limiter.

### Analytics (operator dashboard)
A self-hosted, admin-only view of how the site is doing — growth, engagement, traffic and library health — at `/admin/stats` (SPA, now one section of the _Admin panel_) over `GET /api/admin/stats?window=7|30|90`. There is **no third-party analytics** and no tracking script. (Surveyed before building: Matomo is [MySQL/MariaDB only](https://matomo.org/faq/how-to-install/faq_55/) and would mean a second DB engine; the Symfony analytics bundles are Google Analytics wrappers or single-star Twig packages; and three of the four sections are queries against *this* schema, which nothing off the shelf can supply.)

- **One endpoint, one paint.** All four sections come back together: it is one dashboard, opened by one person, that always loads whole. Splitting it would buy a partial-refresh nothing wants in exchange for four round-trips and four ways to half-render. `App\Service\Analytics\StatsProvider` shapes the payload **directly** — no `StatsMapper`, because these are aggregates with no entities and a mapper would just re-key arrays. It injects `ResponseMapper` only for the two entity-shaped bits (`activity()`, `userSummary()`).
- **Series are parallel arrays on one shared `days` axis**, gap-filled server-side to exactly `window.days` entries (`StatsWindow::fill()`). **The density is a contract**: a category axis treats the array as ordinal, so a sparse series silently closes the gaps and draws a *different, wrong shape* rather than failing. `days`/`window` dates are `Y-m-d`, **not ATOM** — a day bucket is a calendar day, and an ATOM instant invites a client to timezone-shift it into the previous day. Nothing is pre-translated or pre-formatted: raw route names, raw status enums, raw ISO 639-1 codes.
- **Day bucketing happens in SQL.** Doctrine ships no `DATE()`/`DATE_TRUNC`, so `beberlei/doctrineextensions` is registered in `doctrine.yaml` (`dql.datetime_functions.date_trunc`) — the app's **first custom DQL function**, and PostgreSQL-specific in keeping with the raw SQL in `migrations/`. Shared by `User`/`Book`/`BookCollection` through the `CountsCreatedByDay` trait. **`Category` has no `createdAt`**, so it gets a total but no series; don't backfill one, it would fabricate history.
- **DAU is `page_view_visitor` filtered to `authenticated = true`** — "signed-in visitors that loaded a counted page". A *presence* metric, deliberately not derived from domain events; those measure engagement, which is what the loan series is for.
- **The loan series reads `LibraryRequestEvent`, not `LibraryRequest`** — the request row's `resolvedAt` is overwritten by each later transition, so after a return there is no record of when it was approved. It **counts collection children** (a five-book collection borrow really is five loans), which is the deliberate *opposite* of the inbox list queries' `parentRequest IS NULL` rule. Same for `mostBorrowedBookIds`/`topLenderIds`, which also filter to `Approved|ReturnPending|Returned` — an unapproved request is an intention, not a loan.
- **Wish lists sit under "library health"**, not growth: they say what the shelves are *missing*, which is the same question the status and category breakdowns ask from the other side. `library.wishlist` carries the community total, the split by priority, and **`mostWanted`** — titles grouped on title+author across every wish list, i.e. "this many members want this book", the one thing the wish list tells an operator that nothing else on the page does. Every *other* number on the dashboard excludes wish-list rows (see _Wish list_).
- **No caching**, deliberately: ~15 indexed queries for one viewer. A `cache.analytics` pool keyed by window follows the `cache.openlibrary` convention if that stops being true.

**Traffic counting.** Two aggregate tables, never per-visit rows:
- **`page_view_daily`** — one row per (route, day) + a counter. `route` is an SPA **route name** from `App\Analytics\AnalyticsRoutes`, never a URL path: paths carry ids, which would give the table a row per profile per day and turn "top pages" into a list of individuals. `day` is a real `DATE`, which is what lets the dashboard `GROUP BY` it in plain DQL. Grows a few thousand rows a year and is **never pruned**.
- **`page_view_visitor`** — one row per (day, distinct visitor). This is the one place the "aggregate only" rule bends, and it's unavoidable: exact daily-distinct counting has to remember whom it already counted today. The hash is `hash_hmac('sha256', seed|day, %kernel.secret%)` truncated to 32 hex chars, seeded from the **user id** when signed in (so a member's IP never enters it) and IP+UA otherwise. **The day is inside the hashed material**, so one browser is unlinkable across days. Pruned by `app:prune-analytics` (120 days, comfortably past the 90-day window).
- **The increment is an atomic DQL `UPDATE … views + 1`**, falling back to an insert on the day's first hit and catching the `UniqueConstraintViolationException` a concurrent first hit raises — **that catch is mandatory, not garnish**: an uncaught one closes the EntityManager. A read-modify-write would lose increments under concurrency (a DB-backed test pins this). `INSERT … ON CONFLICT` would be one statement but the only native SQL in `src/`; the DQL version costs one extra round-trip on the first hit of each (route, day), ~10 times a day. **The UPDATE is invisible to the ORM** — an already-loaded `PageViewDaily` keeps its stale `$views`; nothing reads a counter back in the request that writes one.
- This write executes immediately, **outside the controller flush boundary** — a documented exception to persist-never-flush (the ingest request has no other writes, and a hit counter is telemetry, not domain state). It is also why ingest is an explicit endpoint and **not** a `kernel.request` subscriber: a write that can close the EM must never sit inside someone else's request.
- **Two endpoints, and neither contains the word `analytics`** — blocklists match `/analytics` in a path, which would systematically drop the most technical slice of the audience while looking like it worked (the PHP namespaces keep the word, where no blocklist sees it). `POST /api/pageviews` for members; `POST /api/public/pageviews` for everyone else, inheriting the `security: false` firewall so a share-page visitor — or a member holding an expired token — is counted rather than 401'd, which the SPA's interceptor would turn into a forced sign-out.
- **The route allow-list is the endpoint's whole security model.** The route is a *grouping key*, so free text would be an unbounded-cardinality write primitive: one caller could insert a million distinct values, one row each, and destroy the table, its index and the top-pages list — while putting attacker-chosen strings on the one page the operator reads attentively. Bounded, the worst a liar achieves is one slightly-wrong number. `AnalyticsRoutesTest` **reads `assets/src/router/index.js`** and fails if the two vocabularies drift, the same job `CategoryPaletteTest` does for the palette.
- `PageViewRecorder` skips obvious crawler user agents (the public share pages are the one bot-reachable surface). Not a security control — a bot can lie, and the cost is one inflated counter.

**Frontend**: `stores/admin.js` (`windowDays`, **never `window`** — it would shadow the global in a module that calls `window.matchMedia`; plus the `reqToken` out-of-order guard, which is *not* optional here because the picker invites rapid 7→90→30 clicking and the 90-day query is slowest), `views/AdminStatsView.vue`, `components/admin/{RankTable,AdminStatsSkeleton}.vue`, and the beacon in `utils/analytics.js` fired from a **second** `router.afterEach` (the existing one is entirely about chunk-reload semantics). The route carries `meta: { admin: true }`; the guard renders the **404 in place with the URL preserved** rather than redirecting, and sits *after* the authentication check so a bookmark on an expired session goes to `/login` instead of looking like the page vanished — it is a client-side hint only. The entry point is a conditional item in `AppHeader`'s **account dropdown**, not the nav strip (that strip is the product nav and must not change shape per viewer) and **not** `MobileBottomNav` (5 items in a fixed 64px bar).

**Charts**: `components/ui/BaseChart.vue` is the app's only Chart.js surface. Controllers are **registered explicitly** rather than via `chart.js/auto` (roughly halves the import and fails loudly on an unregistered type); there is deliberately **no `TimeScale`**, which would need a date adapter plus a date library. **Only the admin view may import it** — `AdminStatsView` is a dynamic import, so Rollup confines chart.js to the admin chunk and the entry bundle pays nothing (measured: entry unchanged, admin chunk ~200 kB / 69 kB gz). Verify that on every build. The instance is a plain `let`, **never a `ref`** — a Chart.js object inside Vue's reactive proxy breaks its identity comparisons and makes every `update()` pay for deep tracking. Data changes mutate and `update()`; only a type *or locale* change rebuilds — the `Intl` formatters live in the frozen `options` object, so without `watch(locale, rebuild)` the ticks and tooltips would keep the old language while every heading around them switched. Colours come only from design tokens and `CATEGORY_PALETTE`; no new hex. A section whose series is all zeroes renders an **empty state, not a chart** — a flat line on a 0-to-1 axis reads as a rendering fault.

### Mail

Transactional mail for the things that happen while nobody is looking at the tab. It is the
slow-channel twin of _Real-time_: same events, same recipient routing, different medium — so
`LoanMailer` reads `LoanEventPublisher`'s own reason constants rather than inventing a
vocabulary that could drift from it.

**Delivery is asynchronous and that is not optional.** Every mail is routed to Messenger's
`async` transport (`doctrine://default`, i.e. PostgreSQL — the droplet has no memory for a
broker) and sent by a **`messenger-worker` container** in both stacks. An SMTP round-trip is
0.3–2s and there are five PHP-FPM workers; a loan transition must never hold one. The queue
table is a **checked-in migration with `auto_setup=0`**, not DDL on first send. `MailConfigTest`
pins the routing, because `when@test: in-memory://` means no test can observe the real
transport and the symptom of a regression is latency, not an error.

**One send path.** `App\Mail\Mailer::send(User, MailType, context)` decides everything a caller
would otherwise have to remember:
- **the opt-in gate** — each `MailType` names the `UserSettings` predicate that must be true
  (`notifiesBorrowRequests` / `notifiesRequestUpdates` / `notifiesActivity`, or none for the
  welcome mail). A member with no settings row behaves as if every setting is at its default,
  which is what makes reading the accessor off a fresh `UserSettings` correct. **These four
  toggles existed in the UI long before anything read them; this is what made them real.**
- **the locale is the recipient's, never the request's** (`UserSettings.locale ?? 'en'`). The
  actor's `Accept-Language` — which drives every API response — says nothing about who is being
  notified. It travels as `TemplatedEmail::locale()` and is applied by the `LocaleSwitcher`
  inside Twig's `BodyRenderer`, in the worker.
- **best-effort, and never silent.** A transport failure is caught and logged exactly like
  `LoanEventPublisher`'s Mercure publish. Sends *and deliberate skips* both leave a record on the
  dedicated **`mail` monolog channel** (dev: `var/log/mail.log`; prod: stderr **outside** the
  `fingers_crossed` gate, same reasoning as `book_template` — an unsent mail raises no error, so a
  buffered handler would discard precisely these records). It is the only way to tell "nobody was
  notified" from "nobody needed to be".

**Eight mails, from sixteen candidates** — `MailType` is the single source of truth (template
stem, subject, gate) and nothing else branches on mail kind:
`loan.requested` · `loan.approved` · `loan.declined` · `loan.return_requested` ·
`loan.return_confirmed` · `loan.reminder` · `account.welcome` · `social.new_follower`.
The consolidation is deliberate and is what keeps the provider footprint and the template count
down:
- **A collection borrow reuses the five per-book loan mails** with `isCollection` + `bookCount`
  set. The two differ only in "a book by an author" vs "a collection of N books" — the same
  collapse `ResponseMapper::request()`/`::collectionRequest()` and the SPA's single `LoanCard`
  already make. Six parallel templates would have been six files kept in sync by hand.
- **A withdrawn request mails nobody.** It would notify an owner about a pending request that no
  longer exists, and it is the transition that fires most on impulse browsing — the worst
  volume-to-value ratio in the set. The Mercure signal already clears their badge. Mapped
  explicitly to `null` in `LoanMailer::TYPE_BY_REASON` and pinned by `MailTypeTest`, so
  "we decided not to" stays distinguishable from "we forgot".
- **Due-soon and overdue are one type with a `state`**, not two mails.
- `notify_newsletter` is deliberately **unimplemented** (no content pipeline); it keeps its toggle.

**Templates** (`templates/emails/`) are table-based with **inlined styles**, copied from
`assets/src/styles/tokens.css` (see _Design System_ for why that file and not the design study) —
so no CSS-inliner dependency, since there is nothing left to inline. Playfair/Work Sans
are named inside real fallback stacks (webfonts don't load in most clients), the column is capped
at 600px, and every mail ships a **text/plain alternative** (spam filters penalise HTML-only mail,
and it is what a text-mode client reads). The six loan mails share `_loan_summary`, so each is a
headline plus a button. Two traps worth knowing:
- **Twig eats the first newline after a `%}`**, which shipped plain-text mails with sentences run
  together. `base.txt.twig` therefore carries its spacing explicitly and children leave it alone.
- **Every link is absolute, built from `DEFAULT_URI`** via the `appUrl` context value. The worker
  has no request context, and SPA paths are Vue routes, so `url()` cannot be used and a relative
  href would be a broken link in the inbox.

**Looking at a mail is one command**: `app:mail-preview` renders every mail — including the
variants a `MailType` alone doesn't capture (collection, decline with and without a note, both
reminder states) — to `var/mail-preview/`, with an `index.html` contact sheet. Its fixtures are
**literals, and nothing in it reads the clock**: that is what lets the Playwright visual baselines
compare the same bytes next month, and it is also the only practical way to review eight mails in
five languages.

**Ids are English sentences** in a `mails` domain (`translations/mails.{de,es,fr,uk}.yaml`), the
`ApiError` convention, so `en` needs no catalog. `tests/I18n/MailTranslationCoverageTest` does for
this domain what `TranslationCoverageTest` does for `messages` — coverage, parity, dead keys — plus
a **placeholder check**, since a translation that drops `%date%` renders a sentence missing the
thing it was about. These ids live in Twig, where no PHP lexer sweeps them up. (The one `src/Mail`
sentence that is *not* user-facing, `LoanMailer`'s "unknown reason" guard, is exempted in
`TranslationCoverageTest::NOT_USER_FACING` alongside the publisher's twins.)

**Call sites** sit next to the Mercure publish they belong to, always after `flush()`:
`LibraryRequestRestController` / `CollectionRequestRestController` (the same three lines that
publish), `AuthRestController` (welcome), `SubscriptionRestController` (new follower). Both
"is this new?" checks read an **id before flush**, where a freshly persisted row is still id-less:
that is what keeps the welcome mail to genuine first sign-ins and stops an unfollow/refollow cycle
from mailing repeatedly (`subscribe()` is idempotent and returns the existing edge).

**Reminders** are the one mail no user action triggers: `app:send-loan-reminders` (cron, `--dry-run`)
mails the borrower about a loan due tomorrow or already overdue. **Idempotent by construction** —
`due_reminder_sent_at` / `overdue_reminder_sent_at` on both request tables, and the repository query
filters on the column being null, so a cron firing twice mails once. The stamp is written **only
when the mail was actually queued**, so an opt-out doesn't burn the single reminder a loan gets and
a queue outage is retried tomorrow. Only `Approved` loans qualify (a borrower in `ReturnPending` has
already acted), and the per-book query keeps `parentRequest IS NULL` so a five-book collection
borrow sends **one** reminder through its parent rather than six. `LibraryRequest` is on the audit
whitelist, so a reminder write appends an actor-less `library_request_audit` row — accepted.

**Local**: everything lands in **Mailpit** (`docker compose up -d`, UI + REST API on
`http://localhost:8025`); `MAILER_DSN=smtp://mailpit:1025` in `docker/local/php/app.env`. A dev
database full of plausible addresses is the last thing that should reach a real relay.
**Production**: **Brevo over its HTTP API** — `brevo+api://<key>@default`, the
`symfony/brevo-mailer` bridge (9,000/month, 300/day free — the daily cap is the binding one at
~60 loans/day). **Not SMTP, and that is forced rather than preferred**: DigitalOcean drops
outbound 25/465/587 (and 2525) at the account level, silently, so an SMTP DSN hangs for 60s and
dies with a connect timeout that reads exactly like a dead relay while never sending the
credentials at all. Port 443 is not blockable. The transport sits below `MailerInterface`, so the
switch is one DSN line — `Mailer`, the templates, the queue, the worker and the gates are unaware.
The key is an **API v3 key, not the SMTP key** (the two live on neighbouring Brevo tabs and the
wrong one 401s). Provider setup, the DKIM/SPF requirement, the post-deploy smoke check and the
cron line are in the _Mail_ section of `DEPLOY.md`.

> **`MAILER_FROM` takes `Name <addr>`; `MAILER_SENDER` takes a bare `addr`.** Dropping the angle
> brackets fails in the worst place available: the framework applies the value as the default
> `From:` header, so the mail throws while being *built* — before the bus, before the transport.
> The queue stays at 0 and the failure transport stays empty, so both look like nothing was ever
> attempted; the `mail` channel's warning is the only witness. `MailConfigTest` now parses every
> shipped value as an `Address`.

> **The `.env.local.php` trap applies to all three new vars.** `MAILER_DSN`, `MAILER_FROM`/
> `MAILER_SENDER` and `MESSENGER_TRANSPORT_DSN` each resolve through the `default:` processor to a
> parameter fallback (`mailer.yaml`, `messenger.yaml`), so a dev machine whose hand-maintained
> `.env.local.php` doesn't list them still boots — it just sends nowhere (`null://null`). Add them
> to `.env.local.php` by hand to see mail in Mailpit outside docker; never run `composer dump-env`.

### CORS
`nelmio/cors-bundle`. `CORS_ALLOW_ORIGIN` in `.env` defaults to a regex matching any `localhost` port (covers the Vite dev server). Adjust for production in `.env.local` / deployment config.

### Testing
PHPUnit suite under `tests/`, run with `php bin/phpunit`. It is **mostly unit-level** (mirrors `src/`: `Entity/`, `Service/`, `Dto/`, `Api/`, `Security/Voter/`, `EventSubscriber/`, `Category/`, `Language/`, `I18n/`, `Exception/`) — no kernel boot or DB, so it runs fast and doesn't need the audit tables. `phpunit.dist.xml` sets `failOnDeprecation` / `failOnNotice` / `failOnWarning` = **true**, so under PHPUnit 13: use `createStub()` (not `createMock()`) when you only need a return value, and pair `->with(...)` with an explicit `->expects(...)`. There is no HTTP/`WebTestCase` layer (the test env disables the firewall: `when@test: security: ~`).

The exceptions are **`tests/Repository/`**, **`tests/Service/Analytics/StatsProviderTest`** and **`tests/Service/Admin/UserPurgerTest`** — DB-backed integration tests (extend `RepositoryTestCase`, a `KernelTestCase`) that exercise the actual DQL, since repository query bugs (e.g. an unbound `:statuses` param, the `parentRequest IS NULL` child-exclusion, the `DATE_TRUNC` custom function, or an enum column hydrating as a raw string through `getScalarResult()`) can't surface in unit tests. `StatsProviderTest` is DB-backed on purpose: stubbing its ten repositories would only assert that the assembly returns what the stubs were told to return.

**Two gaps the suite cannot close, both because `when@test: security: ~` disables the firewall**: no test can observe a real 401/403, and none can catch a stale compiled container. `PublicAccessConfigTest` and `AdminAccessConfigTest` assert the shipped YAML and controller source instead — the runtime behaviour is covered by the curl smoke checks in `DEPLOY.md`, which is not optional ceremony: a stale prod cache once turned `/api/admin/stats` into a 500 that a fully green suite had no way to see. Each test runs inside a transaction that's rolled back, so no schema re-creation; if the test DB isn't reachable the test **skips** (not fails), keeping the default run green on machines that never provisioned it. Auditing is **off under test** (`when@test: dh_auditor: enabled: false`) so its flush listeners don't fight the rollback isolation. Provision the test DB once:
```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test -n
```

## Environment Setup Notes (Windows-specific)

- **PHP ini extensions** to enable in `D:\code\Software\php-8.4.5\php.ini`:
  - `ext-sodium` — `lexik/jwt-authentication-bundle` (via `lcobucci/jwt`)
  - `pdo_pgsql` + `pgsql` — PostgreSQL; without them `doctrine:*` fails with "could not find driver"
  - `intl` — required by `auditor-bundle` (`php_intl.dll` + ICU DLLs already ship with this PHP)
- **Dumped-env gotcha:** a local `.env.local.php` exists (gitignored, **hand-maintained**), and Symfony reads **only** it in dev — `.env` is ignored. So a new variable added to `.env` produces `Environment variable not found: "…"` until it's also in `.env.local.php`. **Never run `composer dump-env`** (or otherwise regenerate/overwrite that file): it is not a build artefact here, and regenerating it wipes the secrets that were filled in by hand. Add the missing key to `.env.local.php` manually, or ask the owner to.
- JWT keypair was generated via the system **OpenSSL CLI**, not `lexik:jwt:generate-keypair` (PHP's `openssl_pkey_new()` misbehaves on this Windows install).
- Dev DB password is `changeme` (matches `POSTGRES_PASSWORD`); `DATABASE_URL` uses it in `.env`.
- The analytics visitor hash is salted with **`%kernel.secret%`** (`APP_SECRET`) rather than a dedicated variable, precisely to avoid one more key that has to reach `.env.local.php`. A blank `APP_SECRET` doesn't break it — the counts stay correct — it just makes the hash guessable from IP + user agent, so set it in production.
- `lexik:jwt:generate-token <email>` mints a JWT for manual API testing — pass `--no-ansi` and strip whitespace before putting it in an `Authorization: Bearer` header (colour codes corrupt the header → nginx 400).
