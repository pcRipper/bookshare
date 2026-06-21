<script setup>
import AppLayout from '@/components/layout/AppLayout.vue'
import StatusScreen from '@/components/ui/StatusScreen.vue'

defineProps({
  message: {
    type: String,
    default: 'An unexpected error occurred while loading this page.',
  },
})

const emit = defineEmits(['retry'])

function reload() {
  window.location.reload()
}
</script>

<template>
  <AppLayout>
    <StatusScreen icon="sentiment_stressed" title="Something went wrong" :message="message">
      <button class="btn-primary" type="button" @click="emit('retry')">
        <span class="material-symbols-outlined">refresh</span>
        Try again
      </button>
      <button class="btn-outline" type="button" @click="reload">Reload page</button>
      <RouterLink to="/library" class="btn-outline">Back to My Library</RouterLink>
    </StatusScreen>
  </AppLayout>
</template>

<style scoped>
.btn-primary,
.btn-outline {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 12px 24px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s;
}
.btn-primary .material-symbols-outlined { font-size: 18px; }
.btn-primary {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
.btn-primary:hover { background: var(--color-primary-container); }
.btn-outline {
  border: 1px solid var(--color-outline);
  color: var(--color-on-surface-variant);
}
.btn-outline:hover { background: var(--color-surface-container-low); }
</style>
