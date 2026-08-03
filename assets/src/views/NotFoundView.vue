<script setup>
import { useI18n } from 'vue-i18n'
import AppLayout from '@/components/layout/AppLayout.vue'
import StatusScreen from '@/components/ui/StatusScreen.vue'

const { t } = useI18n()
</script>

<template>
  <AppLayout>
    <StatusScreen
      icon="travel_explore"
      code="404"
      :title="t('errors.notFoundTitle')"
      :message="t('errors.notFoundMessage')"
    >
      <RouterLink to="/library" class="btn-primary">
        <span class="material-symbols-outlined">book_2</span>
        {{ t('errors.backToLibrary') }}
      </RouterLink>
      <RouterLink to="/discover" class="btn-outline">{{ t('errors.exploreDiscover') }}</RouterLink>
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
