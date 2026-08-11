import api from '@/api'

/**
 * The traffic beacon: one fire-and-forget call per navigation, feeding the
 * operator dashboard's traffic section.
 *
 * It sends the *route name* (`profile`), never the path (`/profile/42`), so no
 * id ever leaves the browser and the server's counter table stays bounded. There
 * is also no client-generated visitor id: the server derives a salted hash that
 * rotates daily, so nothing durable is stored here to identify anyone.
 */

/**
 * Routes we never report, and why:
 *   admin-stats     — the dashboard itself. An operator flipping between windows
 *                     would otherwise make it the top page and inflate their own
 *                     visitor counts. Note this skips the *page*, not "all admin
 *                     traffic": the operator is a real member, and excluding
 *                     them everywhere would understate active users.
 *   google-callback — a machine redirect hop, not a page anyone reads.
 *
 * Mirrored by App\Analytics\AnalyticsRoutes on the server, which is the
 * authority — anything not on its allow-list is rejected with a 422. A test
 * there reads this router's names and fails if the two drift.
 */
const UNTRACKED = new Set(['admin-stats', 'google-callback'])

/**
 * Routes whose visitors may have no session — share links, the changelog, the
 * login screen, a 404. These post to the public endpoint, which sits behind a
 * `security: false` firewall: the authenticated one would 401 an anonymous
 * caller outright, and would reject a member holding an expired token, which
 * the axios interceptor turns into a forced sign-out. Losing a page view is
 * fine; signing somebody out for opening a page is not.
 */
const PUBLIC_ROUTES = new Set(['public-library', 'changelog', 'login', 'not-found'])

/** Absorbs a programmatic replace-after-push landing on the same route twice. */
const REPEAT_WINDOW_MS = 1000
let lastRoute = null
let lastSentAt = 0

export function trackPageview(routeName) {
  const route = String(routeName ?? '')
  if (!route || UNTRACKED.has(route)) return

  const now = Date.now()
  if (route === lastRoute && now - lastSentAt < REPEAT_WINDOW_MS) return
  lastRoute = route
  lastSentAt = now

  const url = PUBLIC_ROUTES.has(route) ? '/public/pageviews' : '/pageviews'

  // Never awaited (navigation must not wait on telemetry), never toasted (an
  // analytics failure is not the reader's problem and a toast would be the most
  // visible thing on screen), never logged.
  api.post(url, { route }).catch(() => {})
}

/** Test seam: clears the repeat-throttle between assertions. */
export function resetPageviewThrottle() {
  lastRoute = null
  lastSentAt = 0
}
