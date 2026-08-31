import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end config. Unlike the PHPUnit suite, this drives the *running local
 * docker stack* — nginx + PHP-FPM + PostgreSQL + Mailpit + the messenger worker
 * — because the thing under test is the whole delivery path: queue a mail in a
 * request, consume it in a worker, render it there, and receive it over SMTP.
 * No unit test can see that path, and the failures it hides are silent ones (a
 * mail that renders in one process and blows up in another).
 *
 *   docker compose up -d && npm run build && npm run test:e2e
 *
 * Everything is overridable by env var so the suite can point at any stack:
 *   E2E_BASE_URL      default https://localhost   (the local stack's nginx)
 *   E2E_MAILPIT_URL   default http://localhost:8025
 *   E2E_OWNER_EMAIL / E2E_BORROWER_EMAIL — any two accounts in the local DB
 *   E2E_CONSOLE       how to reach bin/console (used to mint JWTs)
 */
export default defineConfig({
  testDir: './tests/e2e',
  // One worker: the specs share one database, one Mailpit inbox and one queue.
  // Parallelism here would only buy flakiness.
  workers: 1,
  fullyParallel: false,
  // A mail crosses a queue and a worker, so "not there yet" is normal for a
  // moment. Every wait is an explicit poll (see helpers/mailpit.js) rather than
  // a sleep, but the ceiling has to allow for the hop.
  timeout: 60_000,
  expect: { timeout: 15_000 },
  retries: 0,
  reporter: [['list']],
  outputDir: './var/e2e',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost',
    // The local stack ships a self-signed certificate by design
    // (docker/local/nginx/docker-entrypoint.d/init-certs.sh).
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
