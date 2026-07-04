<template>
  <div class="login-page">
    <div class="login-card">
      <h1 class="brand">FolioShare</h1>
      <p class="subtitle">A quiet corner for your thoughts.</p>

      <div class="divider" />

      <button class="btn-google" :disabled="loading" @click="loginWithGoogle">
        <BaseSpinner v-if="loading" size="sm" />
        <svg v-else class="google-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        {{ loading ? 'Redirecting…' : 'Continue with Google' }}
      </button>

      <p v-if="error" class="error">{{ error }}</p>

      <p class="footer-note">
        By continuing you agree to our
        <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const route = useRoute()
const loading = ref(false)
// Surface a failure passed back from the Google callback (?error=…).
const error = ref(typeof route.query.error === 'string' ? route.query.error : null)

async function loginWithGoogle() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/auth/google')
    window.location.href = data.url
  } catch {
    error.value = 'Could not reach the server. Please try again.'
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: var(--color-background);
  padding: 16px;
}

.login-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-variant);
  border-radius: 8px;
  padding: 48px 40px;
  width: 100%;
  max-width: 400px;
  text-align: center;
}

.brand {
  font-family: var(--font-display);
  font-size: 32px;
  font-weight: 700;
  color: var(--color-on-background);
  margin: 0 0 8px;
}

.subtitle {
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--color-outline);
  margin: 0 0 32px;
}

.divider {
  height: 1px;
  background: var(--color-surface-variant);
  margin-bottom: 32px;
}

.btn-google {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  width: 100%;
  padding: 12px 24px;
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-family: var(--font-body);
  font-size: 15px;
  font-weight: 500;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.15s;
}

.btn-google:hover:not(:disabled) {
  background: var(--color-primary-container);
}

.btn-google:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.google-icon {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}

.error {
  margin-top: 16px;
  font-size: 13px;
  color: var(--color-error);
}

.footer-note {
  margin-top: 24px;
  font-size: 12px;
  color: var(--color-outline);
  line-height: 1.5;
}

.footer-note a {
  color: var(--color-primary);
  text-decoration: underline;
}
</style>
