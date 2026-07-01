import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the subscriptions feature: following other readers, the Library
 * "Following" management tab (`following`), and the subscription feed
 * (`feed` — rows of each followed reader's recent books). Replaces the
 * retired activity feed.
 */
export const useSubscriptionsStore = defineStore('subscriptions', () => {
  const feed = ref([])        // [{ user, books: [...] }]
  const following = ref([])   // [{ id, createdAt, user }] — current page
  const followingMeta = ref({ page: 1, perPage: 20, total: 0, totalPages: 1 })
  const loadingFeed = ref(false)
  const loadingFollowing = ref(false)
  const error = ref(null)

  async function fetchFeed() {
    loadingFeed.value = true
    error.value = null
    try {
      const { data } = await api.get('/subscriptions/feed')
      feed.value = data
    } catch {
      error.value = 'error'
    } finally {
      loadingFeed.value = false
    }
  }

  async function fetchFollowing(page = followingMeta.value.page) {
    loadingFollowing.value = true
    try {
      const { data } = await api.get('/subscriptions', { params: { page } })
      following.value = data.items
      followingMeta.value = data.pagination
    } finally {
      loadingFollowing.value = false
    }
  }

  // Follow a reader. A 409 (already following / can't follow) is surfaced to the
  // caller so it can toast a message; otherwise resolve quietly.
  async function subscribe(userId) {
    await api.post(`/subscriptions/${userId}`)
  }

  // Unfollow; drop the reader from the cached following list and feed locally so
  // the UI reflects it without a refetch.
  async function unsubscribe(userId) {
    await api.delete(`/subscriptions/${userId}`)
    const before = following.value.length
    following.value = following.value.filter(s => s.user.id !== userId)
    // Keep the total (drives the Following badge) in step with the local removal.
    if (following.value.length < before) followingMeta.value.total -= 1
    feed.value = feed.value.filter(g => g.user.id !== userId)
  }

  // Request to borrow a book shown in the feed. Mirrors the Discover flow: a 409
  // (already pending) is benign and just flags the book as requested.
  async function requestBorrow(bookId) {
    const book = feed.value.flatMap(g => g.books).find(b => b.id === bookId)
    try {
      await api.post('/requests', { bookId })
      if (book) book.requested = true
    } catch (e) {
      if (e.response?.status === 409 && book) book.requested = true
      else throw e
    }
  }

  return {
    feed, following, followingMeta, loadingFeed, loadingFollowing, error,
    fetchFeed, fetchFollowing, subscribe, unsubscribe, requestBorrow,
  }
})
