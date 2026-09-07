import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the admin panel's Intercom tab.
 *
 * The composed letter itself is *not* kept here: it is form state that belongs
 * to the view for as long as the operator is writing it, and a store would only
 * make an abandoned draft survive a tab switch, which is the opposite of what
 * you want from a screen that sends mail. What lives here is the server's word
 * on things — who would receive it, and what has already gone out.
 *
 * Not paginated, like adminDumps: the history answers "did we already send
 * that?", a question about the last handful of letters, and the endpoint caps
 * itself at twenty.
 */
export const useAdminIntercomStore = defineStore('adminIntercom', () => {
  // How many members would receive a letter sent right now. Null until known —
  // "we haven't asked yet" must not render as a confident zero on the one
  // screen where the number decides whether sending is worth doing at all.
  const recipients = ref(null)
  const letters = ref([])

  const loading = ref(false)
  const sending = ref(false)
  const testing = ref(false)
  // null | 'forbidden' | 'error' — a code, not a message, as the other stores do.
  const error = ref(null)

  async function fetchAudience() {
    try {
      const { data } = await api.get('/admin/intercom/audience')
      recipients.value = data.recipients
    } catch {
      // Non-fatal: the composer still works without the count, and the Send
      // button's own confirmation says how many it reached afterwards.
      recipients.value = null
    }
  }

  async function fetchLetters() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/admin/intercom/letters')
      letters.value = data.items
    } catch (e) {
      error.value = e.response?.status === 403 ? 'forbidden' : 'error'
    } finally {
      loading.value = false
    }
  }

  function init() {
    return Promise.all([fetchAudience(), fetchLetters()])
  }

  /** Send the composed letter to the operator alone. Throws for the caller to toast. */
  async function sendTest(letter) {
    testing.value = true
    try {
      const { data } = await api.post('/admin/intercom/test', letter)
      return data.queued
    } finally {
      testing.value = false
    }
  }

  /** Send it for real, then refresh the history so the new row appears. */
  async function send(letter) {
    sending.value = true
    try {
      const { data } = await api.post('/admin/intercom/send', letter)
      await fetchLetters()
      return data
    } finally {
      sending.value = false
    }
  }

  return {
    recipients, letters, loading, sending, testing, error,
    init, fetchAudience, fetchLetters, sendTest, send,
  }
})
