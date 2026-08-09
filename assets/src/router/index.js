import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { loadLanguages } from '@/utils/languages'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/auth/google/callback',
      name: 'google-callback',
      component: () => import('@/views/GoogleCallbackView.vue'),
      meta: { public: true },
    },
    {
      // The share link. Public by design — the API behind it is too.
      path: '/public/library/:id',
      name: 'public-library',
      component: () => import('@/views/PublicLibraryView.vue'),
      meta: { public: true },
    },
    // Protected routes — add pages here as they are built
    {
      path: '/library',
      name: 'library',
      component: () => import('@/views/LibraryView.vue'),
    },
    {
      path: '/discover',
      name: 'discover',
      component: () => import('@/views/DiscoverView.vue'),
    },
    {
      path: '/subscriptions',
      name: 'subscriptions',
      component: () => import('@/views/SubscriptionsView.vue'),
    },
    {
      path: '/profile/:id',
      name: 'profile',
      component: () => import('@/views/ProfileView.vue'),
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
    },
    {
      // Public: it renders a static file, needs no API, and it is the footer's
      // only link — so on the share page it would otherwise dead-end an
      // anonymous visitor at /login.
      path: '/changelog',
      name: 'changelog',
      component: () => import('@/views/ChangelogView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      redirect: '/library',
    },
    // Catch-all → 404. Keep last so real routes match first. Public so a
    // mistyped share link 404s honestly instead of redirecting to /login.
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { public: true },
    },
  ],
})

router.beforeEach(to => {
  const auth = useAuthStore()
  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'library' }
  }
  // Warm the language vocabulary the moment the viewer is known to be
  // authenticated — on first load if already logged in, or right after the
  // OAuth callback redirects in. Fire-and-forget; memoized, so repeat
  // navigations are no-ops and the picker/filter never wait on the fetch.
  if (auth.isAuthenticated) {
    loadLanguages().catch(() => {})
  }
})

/*
 * Every route is a dynamic import, so a browser holding a stale index.html asks
 * for chunk hashes that no longer exist after a release. The import rejects, the
 * navigation dies, and the user is left on a blank page with only a console
 * error — the worst failure mode we have, because it looks like the app is gone.
 *
 * Reload once to pick up the current entry. The sessionStorage flag is what makes
 * it safe: if the fresh bundle still can't load the chunk, the cause isn't
 * staleness and a second reload would loop forever, so we let the error surface.
 */
const RELOADED_KEY = 'chunkReloadAttempted'

router.onError(error => {
  const isChunkLoadFailure = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i
    .test(error?.message ?? '')

  if (!isChunkLoadFailure) return

  if (sessionStorage.getItem(RELOADED_KEY)) return
  sessionStorage.setItem(RELOADED_KEY, '1')
  window.location.reload()
})

// A navigation that completes proves the current bundle is intact, so the next
// genuine staleness gets its own single retry.
router.afterEach(() => sessionStorage.removeItem(RELOADED_KEY))

export default router
