<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppLayout from '@/components/layout/AppLayout.vue'
import StatusScreen from '@/components/ui/StatusScreen.vue'

const { t } = useI18n()

// Null rather than an English literal default: the fallback has to be resolved
// against the active locale, which a prop default can't do.
const props = defineProps({
  message: { type: String, default: null },
})

const shownMessage = computed(() => props.message ?? t('errors.unexpected'))

const emit = defineEmits(['retry'])

function reload() {
  window.location.reload()
}
</script>

<template>
  <AppLayout>
    <StatusScreen icon="sentiment_stressed" :title="t('errors.title')" :message="shownMessage">
      <button class="btn-primary" type="button" @click="emit('retry')">
        <span class="material-symbols-outlined">refresh</span>
        {{ t('common.retry') }}
      </button>
      <button class="btn-outline" type="button" @click="reload">{{ t('errors.reload') }}</button>
      <RouterLink to="/library" class="btn-outline">{{ t('errors.backToLibrary') }}</RouterLink>
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
