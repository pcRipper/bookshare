import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { loadLanguages } from '@/utils/languages'
import { trackPageview } from '@/utils/analytics'

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
      // The operator panel. `meta.admin` is a client-side hint only — the API
      // gates /api/admin on ROLE_ADMIN independently, and the stores handle the
      // 403 if the two ever disagree. Children inherit the meta, so the guard
      // below covers every section without repeating it.
      //
      // Nested rather than flat because AdminView is a real shell: it owns the
      // layout, the page header and the section strip, and each child renders
      // into its RouterView as a bare panel.
      path: '/admin',
      component: () => import('@/views/AdminView.vue'),
      meta: { admin: true },
      children: [
        { path: '', redirect: { name: 'admin-members' } },
        {
          path: 'members',
          name: 'admin-members',
          component: () => import('@/views/AdminMembersView.vue'),
        },
        {
          // Keeps the path and the name it has always had: the dashboard was
          // the whole of /admin before the panel existed, and a bookmark on it
          // must not break just because it gained siblings.
          path: 'stats',
          name: 'admin-stats',
          component: () => import('@/views/AdminStatsView.vue'),
        },
        {
          path: 'dumps',
          name: 'admin-dumps',
          component: () => import('@/views/AdminDumpsView.vue'),
        },
      ],
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
  // After the authentication check above, so a bookmarked admin URL opened with
  // an expired session goes to /login rather than looking like the page has
  // vanished. Renders the 404 in place, keeping the URL the reader typed:
  // redirecting to /library would read as "this exists but you can't have it",
  // which tells a non-admin more than they need to know.
  if (to.meta.admin && !auth.isAdmin) {
    return {
      name: 'not-found',
      params: { pathMatch: to.path.slice(1).split('/') },
      query: to.query,
      hash: to.hash,
    }
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

/*
 * Traffic counting. A second hook rather than an extension of the one above:
 * that one is entirely about chunk-reload semantics, and folding an unrelated
 * network call into it would make both harder to read. vue-router runs multiple
 * afterEach hooks in registration order at no cost.
 */
router.afterEach((to, from, failure) => {
  // vue-router reports aborted and redirected navigations here too, and neither
  // is a page anyone saw.
  if (failure) return
  // A query- or hash-only change is the same page.
  if (to.name === from.name && to.path === from.path) return

  trackPageview(to.name)
})

export default router
