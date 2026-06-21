<script setup>
import { ref, watch, onErrorCaptured } from 'vue'
import { useRoute } from 'vue-router'
import ErrorView from '@/views/ErrorView.vue'

/**
 * App-wide error boundary. Catches errors thrown while rendering the routed view
 * and shows a friendly error page instead of a blank screen. Resets on a retry
 * or when the route changes, so navigating away recovers cleanly.
 */
const route = useRoute()
const failed = ref(false)

onErrorCaptured(error => {
  // eslint-disable-next-line no-console
  console.error('[AppErrorBoundary] captured:', error)
  failed.value = true
  return false // stop the error from propagating further
})

// A route change is a fresh start — clear the failed state.
watch(() => route.fullPath, () => { failed.value = false })
</script>

<template>
  <ErrorView v-if="failed" @retry="failed = false" />
  <slot v-else />
</template>
