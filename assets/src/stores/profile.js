import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the profile page (`/profile/:id`): a user's identity, derived stats and
 * full book collection, the "Request to Borrow" action (other people's books),
 * and — when viewing your own profile — inline profile editing and book CRUD.
 */
export const useProfileStore = defineStore('profile', () => {
  const emptyMeta = () => ({ page: 1, perPage: 24, total: 0, totalPages: 1 })

  const profile = ref(null)
  const books = ref([])                 // the current shelf's page of books
  const booksMeta = ref(emptyMeta())    // pagination for that page
  const booksLoading = ref(false)       // page-of-books loading (shelf switch / paging)
  const availableCount = ref(0)         // total of the 'available' shelf, for its tab chip
  const shelf = ref('available')        // 'available' (status=own) | 'full'
  const loading = ref(false)
  const error = ref(null) // 'not-found' | 'private' | 'error' | null
  const currentId = ref(null)

  // `quiet` refreshes data in place (after an edit) without blanking the page.
  // Profile (the user) and their book page are distinct resources from distinct
  // endpoints, fetched in parallel. A fresh (non-quiet) load resets to page 1 of
  // the "available" shelf; a quiet refresh keeps the current shelf and page.
  async function fetchProfile(id, { quiet = false } = {}) {
    currentId.value = id
    error.value = null
    if (!quiet) {
      loading.value = true
      profile.value = null
      books.value = []
      shelf.value = 'available'
      booksMeta.value = emptyMeta()
    }
    try {
      const [profileRes] = await Promise.all([
        api.get(`/users/${id}`),
        fetchBooksPage(quiet ? booksMeta.value.page : 1, { silent: true }),
      ])
      profile.value = profileRes.data
    } catch (e) {
      const status = e.response?.status
      // A 403 from /books?owner means the profile is private (hidden).
      error.value = status === 404 ? 'not-found' : status === 403 ? 'private' : 'error'
    } finally {
      if (!quiet) loading.value = false
    }
  }

  // Load one page of the active shelf. "available" maps to the server's
  // status=own filter (books free to borrow); "full" is the whole collection.
  async function fetchBooksPage(page = 1, { silent = false } = {}) {
    if (!silent) booksLoading.value = true
    try {
      const params = { owner: currentId.value, page }
      if (shelf.value === 'available') params.status = 'own'
      const { data } = await api.get('/books', { params })
      books.value = data.items
      booksMeta.value = data.pagination
      if (shelf.value === 'available') availableCount.value = data.pagination.total
    } finally {
      if (!silent) booksLoading.value = false
    }
  }

  // Switch shelves, resetting to that shelf's first page.
  function setShelf(next) {
    if (next === shelf.value) return
    shelf.value = next
    return fetchBooksPage(1)
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
    profile, books, booksMeta, booksLoading, availableCount, shelf, loading, error,
    fetchProfile, fetchBooksPage, setShelf, requestBorrow,
    updateProfile, createBook, updateBook, deleteBook,
  }
})
