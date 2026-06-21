<script setup>
import AppErrorBoundary from '@/components/AppErrorBoundary.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
</script>

<template>
  <AppErrorBoundary>
    <RouterView />
  </AppErrorBoundary>
  <ToastHost />
</template>
