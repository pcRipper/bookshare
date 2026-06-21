/*
 * FolioShare service worker — runtime caching for the built SPA.
 *
 * Served from the site root (`/sw.js`, because Symfony's docroot is `public/`),
 * so its scope is `/` and it can control every page, not just `/build/` assets.
 *
 * Strategies:
 *   - Navigations (the app shell)      → network-first, fall back to cache offline.
 *   - Built static assets (`/build/…`) → stale-while-revalidate (instant + fresh).
 *   - API (`/api/…`)                   → never touched; always hits the network.
 *
 * Bump CACHE_VERSION on a breaking change to evict every client's old cache.
 */
const CACHE_VERSION = 'v1'
const CACHE_NAME = `folioshare-${CACHE_VERSION}`
const APP_SHELL = '/'

// Activate a new worker immediately rather than waiting for all tabs to close.
self.addEventListener('install', () => {
  self.skipWaiting()
})

// Drop caches left behind by previous versions, then take control of open pages.
self.addEventListener('activate', event => {
  event.waitUntil(
    caches
      .keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', event => {
  const { request } = event

  // Only same-origin GETs are cacheable; let the rest pass straight through.
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return

  // Never cache the API — borrow requests, profiles and stats must stay fresh.
  if (url.pathname.startsWith('/api')) return

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request))
    return
  }

  if (url.pathname.startsWith('/build/')) {
    event.respondWith(staleWhileRevalidate(request))
  }
})

// Prefer the network so deploys are picked up immediately; cache is the offline
// safety net. The fetched shell is cached so any later offline visit can boot.
async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME)
  try {
    const response = await fetch(request)
    cache.put(request, response.clone())
    return response
  } catch {
    return (await cache.match(request)) || (await cache.match(APP_SHELL)) || Response.error()
  }
}

// Serve cached assets instantly while refreshing them in the background. Hashed
// filenames mean a cached asset is never stale for the build that requested it.
async function staleWhileRevalidate(request) {
  const cache = await caches.open(CACHE_NAME)
  const cached = await cache.match(request)
  const network = fetch(request)
    .then(response => {
      cache.put(request, response.clone())
      return response
    })
    .catch(() => cached)
  return cached || network
}
