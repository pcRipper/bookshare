<template>
  <div class="callback-page">
    <p v-if="error" class="error">{{ error }}<br><a href="/login">{{ t('auth.backToLogin') }}</a></p>
    <p v-else class="status">{{ t('auth.signingIn') }}</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api'
import { setLocale, takePendingLocale } from '@/i18n'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { t } = useI18n()
const error = ref(null)

/*
 * Sign-in is the one moment the UI language and the account can be reconciled,
 * so it's where the two directions are settled:
 *
 *  - a language picked on the login page wins and becomes the account's, which
 *    is the whole point of offering the picker before signing in;
 *  - otherwise the account's stored language is adopted, so the choice follows
 *    a reader onto a new device. A null one is meaningful — "never picked a
 *    language" — and must leave the browser-negotiated locale alone.
 *
 * Best-effort throughout: a preference is never worth failing a sign-in over.
 */
async function reconcileLocale() {
  try {
    const pending = takePendingLocale()
    if (pending) {
      await api.patch('/me/settings', { locale: pending })
      return
    }
    const { data } = await api.get('/me/settings')
    if (data.locale) setLocale(data.locale)
  } catch {
    // Keep whatever the browser is already rendering in.
  }
}

onMounted(async () => {
  const code = route.query.code
  if (!code) {
    error.value = t('auth.noCode')
    return
  }

  try {
    const { data } = await api.post('/auth/google/callback', { code })
    authStore.setAuth(data.token, data.user)
    await reconcileLocale()
    router.replace('/library')
  } catch (e) {
    // Clear any stale credentials so the login guard doesn't bounce us back
    // into the app, then return to the login page with the failure reason.
    authStore.logout()
    const message = e.response?.data?.error ?? t('auth.failed')
    error.value = message
    router.replace({ name: 'login', query: { error: message } })
  }
})
</script>

<style scoped>
.callback-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: var(--color-background);
  font-family: var(--font-body);
}

.status {
  color: var(--color-on-surface-variant);
  font-size: 16px;
}

.error {
  color: var(--color-error);
  font-size: 15px;
  text-align: center;
  line-height: 1.8;
}

.error a {
  color: var(--color-primary);
}
</style>
