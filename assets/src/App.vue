<script setup>
import { computed, ref, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppErrorBoundary from '@/components/AppErrorBoundary.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useMercure } from '@/composables/useMercure'

// Open the real-time loan notification stream while signed in; tear it down on
// logout. Reuses the existing auth store as the source of truth for the session.
const { isAuthenticated } = storeToRefs(useAuthStore())
const route = useRoute()
const mercure = useMercure()

// The initial route isn't resolved yet at setup, so `route.meta` is empty and an
// immediate watch would read every first load — a share link included — as
// non-public and connect anyway. Waiting for the router costs a few
// milliseconds on a stream nothing is waiting for.
const routerReady = ref(false)
useRouter().isReady().then(() => { routerReady.value = true })

// Never on a public route, even while signed in. /api/mercure/token is not under
// /api/public, so a stale token 401s there and the axios interceptor bounces the
// reader to /login — off a share link that needs no account at all (see
// CLAUDE.md, Public library access). Nothing on those pages is live anyway: they
// are somebody else's shelf, read-only, with no loan of yours to signal.
const streaming = computed(() => routerReady.value && isAuthenticated.value && !route.meta.public)

watch(
  streaming,
  on => (on ? mercure.start() : mercure.stop()),
  { immediate: true },
)

onUnmounted(mercure.stop)
</script>

<template>
  <AppErrorBoundary>
    <RouterView />
  </AppErrorBoundary>
  <ToastHost />
</template>
