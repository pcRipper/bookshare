<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/components/layout/AppLayout.vue'
import StatusScreen from '@/components/ui/StatusScreen.vue'

const { t } = useI18n()
const auth = useAuthStore()

// This route is public so a mistyped share link 404s honestly — which means it
// also renders for visitors with no account. Both the chrome and the way out
// have to match: the usual Library/Discover buttons would just bounce them to
// the login screen they were never asking for.
const signedIn = computed(() => auth.isAuthenticated)
</script>

<template>
  <AppLayout :variant="signedIn ? 'app' : 'public'">
    <StatusScreen
      icon="travel_explore"
      code="404"
      :title="t('errors.notFoundTitle')"
      :message="t('errors.notFoundMessage')"
    >
      <template v-if="signedIn">
        <RouterLink to="/library" class="btn-primary">
          <span class="material-symbols-outlined">book_2</span>
          {{ t('errors.backToLibrary') }}
        </RouterLink>
        <RouterLink to="/discover" class="btn-outline">{{ t('errors.exploreDiscover') }}</RouterLink>
      </template>
      <RouterLink v-else to="/login" class="btn-primary">
        {{ t('public.signIn') }}
      </RouterLink>
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
