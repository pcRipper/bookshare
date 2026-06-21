import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the profile page (`/profile/:id`): a user's identity, derived stats and
 * full book collection, the "Request to Borrow" action (other people's books),
 * and — when viewing your own profile — inline profile editing and book CRUD.
 */
export const useProfileStore = defineStore('profile', () => {
  const profile = ref(null)
  const books = ref([])
  const loading = ref(false)
  const error = ref(null) // 'not-found' | 'error' | null
  const currentId = ref(null)

  // `quiet` refreshes data in place (after an edit) without blanking the page.
  // Profile (the user) and books (their collection) are distinct resources, so
  // they come from distinct endpoints — fetched in parallel.
  async function fetchProfile(id, { quiet = false } = {}) {
    currentId.value = id
    error.value = null
    if (!quiet) {
      loading.value = true
      profile.value = null
      books.value = []
    }
    try {
      const [profileRes, booksRes] = await Promise.all([
        api.get(`/users/${id}`),
        api.get('/books', { params: { owner: id } }),
      ])
      profile.value = profileRes.data
      books.value = booksRes.data
    } catch (e) {
      error.value = e.response?.status === 404 ? 'not-found' : 'error'
    } finally {
      if (!quiet) loading.value = false
    }
  }

  function refresh() {
    if (currentId.value != null) return fetchProfile(currentId.value, { quiet: true })
  }

  // Request to borrow a book. Optimistically flag it; a 409 (already pending)
  // is benign and means it's effectively requested already.
  async function requestBorrow(bookId) {
    const book = books.value.find(b => b.id === bookId)
    try {
      await api.post('/requests', { bookId })
      if (book) book.requested = true
    } catch (e) {
      if (e.response?.status === 409 && book) book.requested = true
      else throw e
    }
  }

  /* ── Own-profile mutations (only meaningful when profile.isSelf) ──────── */

  // Update the current user's editable profile fields (bio, location).
  async function updateProfile(payload) {
    const { data } = await api.patch('/me', payload)
    if (profile.value) {
      profile.value.bio = data.bio
      profile.value.location = data.location
      profile.value.stats = data.stats
    }
  }

  async function createBook(payload) {
    await api.post('/books', payload)
    await refresh()
  }

  async function updateBook(id, payload) {
    await api.patch(`/books/${id}`, payload)
    await refresh()
  }

  async function deleteBook(id) {
    await api.delete(`/books/${id}`)
    await refresh()
  }

  return {
    profile, books, loading, error,
    fetchProfile, requestBorrow,
    updateProfile, createBook, updateBook, deleteBook,
  }
})
