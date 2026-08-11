import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the operator dashboard (`/admin/stats`).
 *
 * Named `admin` rather than `analytics` so it can't be confused with
 * `utils/analytics.js`, which is the outbound page-view beacon and an unrelated
 * concern — and so a second admin screen has somewhere obvious to live.
 *
 * One request, not four: the server returns all four sections together, because
 * this is one dashboard that always loads whole. That keeps a single loading and
 * a single error state here rather than four ways to end up half-rendered.
 */
export const useAdminStore = defineStore('admin', () => {
  // Never `window` — this module also reaches for window.matchMedia, and a ref
  // shadowing the global is the same family of bug as naming a v-for variable
  // `t` and shadowing the translator.
  const windowDays = ref(30)

  const stats = ref(null)
  const loading = ref(false)
  // null | 'forbidden' | 'error' — a code, not a message, like the other stores.
  const error = ref(null)

  let reqToken = 0

  async function fetchStats(days = windowDays.value) {
    // Not optional here: the window picker invites rapid 7→90→30 clicking and
    // the 90-day query is the slowest, so without this guard the stale response
    // lands last and the charts contradict the picker.
    const token = ++reqToken
    loading.value = true
    error.value = null

    try {
      const { data } = await api.get('/admin/stats', { params: { window: days } })
      if (token === reqToken) stats.value = data
    } catch (e) {
      if (token === reqToken) {
        // A 403 gets its own code so the view can say "you don't have access"
        // rather than "something went wrong". Note it does not trip the axios
        // 401 interceptor, so the session is left alone.
        error.value = e.response?.status === 403 ? 'forbidden' : 'error'
      }
      throw e
    } finally {
      if (token === reqToken) loading.value = false
    }
  }

  function setWindow(days) {
    if (days === windowDays.value) return undefined
    windowDays.value = days

    return fetchStats(days)
  }

  return { windowDays, stats, loading, error, fetchStats, setWindow }
})
