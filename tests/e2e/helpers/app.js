import { execSync } from 'node:child_process'

/**
 * Talking to the app as a signed-in member.
 *
 * Sign-in is Google OAuth only, so a browser cannot log in here at all: there is
 * no password form to fill and no consent screen we may drive. A JWT is
 * therefore minted with the console command that exists for exactly this
 * (`lexik:jwt:generate-token`, documented in CLAUDE.md for manual API testing)
 * and either sent as a Bearer header or planted in localStorage, which is where
 * the SPA's auth store keeps it.
 */
const CONSOLE = process.env.E2E_CONSOLE ?? 'docker compose exec -T phpfpm php bin/console'

export const OWNER = process.env.E2E_OWNER_EMAIL ?? 'olena@test.local'
export const BORROWER = process.env.E2E_BORROWER_EMAIL ?? 'maximuspro100@gmail.com'

const tokens = new Map()

/** Mints (and caches) a 24h JWT for a member. */
export function tokenFor(email) {
  if (!tokens.has(email)) {
    // --no-ansi and a hard strip: colour codes in an Authorization header make
    // nginx answer 400 (the same trap CLAUDE.md records for manual testing).
    const raw = execSync(`${CONSOLE} lexik:jwt:generate-token ${email} --no-ansi`, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    })

    const token = raw.replace(/\s+/g, '').replace(/^.*Token/, '')
    if (!token.startsWith('ey')) {
      throw new Error(`Could not mint a token for ${email}. Is the stack up? Got: ${raw.slice(0, 200)}`)
    }
    tokens.set(email, token)
  }

  return tokens.get(email)
}

/** Authorization header for an authenticated API call. */
export function authHeaders(email, extra = {}) {
  return { Authorization: `Bearer ${tokenFor(email)}`, 'Content-Type': 'application/json', ...extra }
}

/**
 * Puts a member's credentials where the SPA looks for them, before any app code
 * runs. The shape must match assets/src/stores/auth.js.
 */
export async function signIn(page, email) {
  const token = tokenFor(email)
  await page.addInitScript(
    ({ token, email }) => {
      localStorage.setItem('token', token)
      localStorage.setItem('user', JSON.stringify({ id: 0, email, fullName: email, avatarUrl: null, isAdmin: false }))
    },
    { token, email },
  )
}

/** A fresh book owned by the owner account, so specs never fight over fixtures. */
export async function createBook(request, { owner = OWNER, title, author = 'E2E Author' } = {}) {
  const response = await request.post('/api/books', {
    headers: authHeaders(owner),
    data: { title, author, status: 'own', isRead: false, isWished: false, categoryIds: [] },
  })
  if (!response.ok()) {
    throw new Error(`Could not create the book (${response.status()}): ${await response.text()}`)
  }

  return response.json()
}

/** Borrow request from the borrower account. */
export async function requestBook(request, bookId, { borrower = BORROWER } = {}) {
  const response = await request.post('/api/requests', {
    headers: authHeaders(borrower),
    data: { bookId },
  })
  if (!response.ok()) {
    throw new Error(`Could not request the book (${response.status()}): ${await response.text()}`)
  }

  return response.json()
}

/** PATCHes /me/settings for a member (partial body, as the endpoint allows). */
export async function updateSettings(request, email, settings) {
  const response = await request.patch('/api/me/settings', { headers: authHeaders(email), data: settings })
  if (!response.ok()) {
    throw new Error(`Could not update settings for ${email} (${response.status()}): ${await response.text()}`)
  }

  return response.json()
}

/**
 * Reads a member's current settings, so a spec can put back exactly what it
 * found. Restoring to a *default* instead would quietly rewrite the account:
 * `locale` in particular treats null ("never chose a language") as distinct from
 * an explicit 'en'.
 */
export async function readSettings(request, email) {
  const response = await request.get('/api/me/settings', { headers: authHeaders(email) })
  if (!response.ok()) {
    throw new Error(`Could not read settings for ${email} (${response.status()}).`)
  }

  return response.json()
}

/**
 * Applies settings for the body of a callback and restores the previous values
 * afterwards, whatever happens — the specs share one database.
 */
export async function withSettings(request, email, overrides, body) {
  const before = await readSettings(request, email)
  const previous = Object.fromEntries(Object.keys(overrides).map((key) => [key, before[key] ?? null]))

  await updateSettings(request, email, overrides)
  try {
    await body()
  } finally {
    await updateSettings(request, email, previous)
  }
}

/** A title unique per run, so a rerun never matches the previous run's mail. */
export function uniqueTitle(prefix) {
  return `${prefix} ${Date.now().toString(36)}`
}
