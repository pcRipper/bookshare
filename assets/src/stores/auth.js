import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token'))
  const user = ref(JSON.parse(localStorage.getItem('user') || 'null'))

  const isAuthenticated = computed(() => !!token.value)

  /**
   * Whether to offer the operator dashboard. A UI hint only — /api/admin is
   * gated on ROLE_ADMIN server-side regardless of what this says.
   *
   * Strict equality on purpose: the flag is absent from every session created
   * before this shipped, and === true reads those as false rather than
   * coercing. Those sessions regain the link when their 24h token expires and
   * they sign in again, or as soon as anything refetches /me.
   */
  const isAdmin = computed(() => user.value?.isAdmin === true)

  function setAuth(newToken, newUser) {
    token.value = newToken
    user.value = newUser
    localStorage.setItem('token', newToken)
    localStorage.setItem('user', JSON.stringify(newUser))
  }

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')
  }

  return { token, user, isAuthenticated, isAdmin, setAuth, logout }
})
