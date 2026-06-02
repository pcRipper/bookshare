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
npm run lint       # ESLint over assets/src/
```

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

### Frontend imports
The `@` alias resolves to `assets/src/`. Use `import Foo from '@/components/Foo.vue'` everywhere.

### Authentication
JWT via `lexik/jwt-authentication-bundle`. Keys are in `config/jwt/` (gitignored). The passphrase is in `.env` (`JWT_PASSPHRASE`). Security firewall and `access_control` rules are configured in `config/packages/security.yaml`.

### CORS
Handled by `nelmio/cors-bundle`. `CORS_ALLOW_ORIGIN` in `.env` defaults to a regex matching any `localhost` port, which covers the Vite dev server. Adjust for production in `.env.local` or deployment config.

## Environment Setup Notes (Windows-specific)

- `ext-sodium` must be enabled in `php.ini` — required by `lexik/jwt-authentication-bundle` (dependency on `lcobucci/jwt`)
- JWT keypair was generated via the system OpenSSL CLI, not `php bin/console lexik:jwt:generate-keypair`, because PHP's `openssl_pkey_new()` has issues on this Windows install
- PHP binary: `D:\code\Software\php-8.4.5\php.ini`
