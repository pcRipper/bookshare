<template>
  <div class="callback-page">
    <p v-if="error" class="error">{{ error }}<br><a href="/login">Back to login</a></p>
    <p v-else class="status">Signing you in…</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const error = ref(null)

onMounted(async () => {
  const code = route.query.code
  if (!code) {
    error.value = 'No authorization code received from Google.'
    return
  }

  try {
    const { data } = await api.post('/auth/google/callback', { code })
    authStore.setAuth(data.token, data.user)
    router.replace('/library')
  } catch (e) {
    error.value = e.response?.data?.error ?? 'Authentication failed. Please try again.'
  }
})
</script>

<style scoped>
.callback-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #fbf9f5;
  font-family: 'Work Sans', system-ui, sans-serif;
}

.status {
  color: #414844;
  font-size: 16px;
}

.error {
  color: #ba1a1a;
  font-size: 15px;
  text-align: center;
  line-height: 1.8;
}

.error a {
  color: #274738;
}
</style>
