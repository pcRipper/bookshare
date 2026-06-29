<script setup>
import { watch, onUnmounted } from 'vue'
import { storeToRefs } from 'pinia'
import AppErrorBoundary from '@/components/AppErrorBoundary.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useMercure } from '@/composables/useMercure'

// Open the real-time loan notification stream while signed in; tear it down on
// logout. Reuses the existing auth store as the source of truth for the session.
const { isAuthenticated } = storeToRefs(useAuthStore())
const mercure = useMercure()

watch(
  isAuthenticated,
  authed => (authed ? mercure.start() : mercure.stop()),
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
