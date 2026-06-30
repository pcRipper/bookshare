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
      path: '/changelog',
      name: 'changelog',
      component: () => import('@/views/ChangelogView.vue'),
    },
    {
      path: '/',
      redirect: '/library',
    },
    // Catch-all → 404. Keep last so real routes match first.
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
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

export default router
