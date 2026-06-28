import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the Discover page (`/discover`). The search surface has two modes:
 *  - 'books'    — shareable books from other public members (filter by query/category),
 *                 with a "request to borrow" action.
 *  - 'accounts' — other public members by name, with an inline follow/unfollow action.
 * The category pills are books-only. The free-text query is shared across modes.
 */
export const useDiscoverStore = defineStore('discover', () => {
  const mode = ref('books') // 'books' | 'accounts'

  const books = ref([])
  const accounts = ref([])
  const categories = ref([])
  const loading = ref(false)
  const error = ref(null)

  // Active filters — the view binds these and calls the active fetch to apply.
  const query = ref('')
  const activeCategory = ref(null) // category id | null (= all) — books mode only
  const activeLanguage = ref(null) // ISO code | null (= any) — books mode only

  let reqToken = 0

  async function fetchCategories() {
    try {
      const { data } = await api.get('/categories')
      categories.value = data
    } catch {
      /* Non-fatal: the page still works without filter pills. */
    }
  }

  async function fetchBooks() {
    // Guard against out-of-order responses when the user types/clicks quickly.
    const token = ++reqToken
    loading.value = true
    error.value = null
    try {
      const params = {}
      if (query.value.trim()) params.q = query.value.trim()
      if (activeCategory.value != null) params.category = activeCategory.value
      if (activeLanguage.value != null) params.language = activeLanguage.value
      const { data } = await api.get('/books/discover', { params })
      if (token === reqToken) books.value = data
    } catch {
      if (token === reqToken) error.value = 'error'
    } finally {
      if (token === reqToken) loading.value = false
    }
  }

  async function fetchAccounts() {
    const token = ++reqToken
    // Prompt-to-search: with an empty box we don't list every public reader.
    if (!query.value.trim()) {
      accounts.value = []
      loading.value = false
      error.value = null
      return
    }
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/users/discover', { params: { q: query.value.trim() } })
      if (token === reqToken) accounts.value = data
    } catch {
      if (token === reqToken) error.value = 'error'
    } finally {
      if (token === reqToken) loading.value = false
    }
  }

  // Run whichever fetch matches the current mode.
  function fetchActive() {
    return mode.value === 'accounts' ? fetchAccounts() : fetchBooks()
  }

  async function init() {
    await Promise.all([fetchCategories(), fetchBooks()])
  }

  function setMode(next) {
    if (next === mode.value) return
    mode.value = next
    // The category/language filters are books-only; drop them leaving books mode.
    if (next === 'accounts') {
      activeCategory.value = null
      activeLanguage.value = null
    }
    return fetchActive()
  }

  function setQuery(q) {
    query.value = q
    return fetchActive()
  }

  function setCategory(id) {
    // Toggle: clicking the active pill clears the filter.
    activeCategory.value = activeCategory.value === id ? null : id
    return fetchBooks()
  }

  function setLanguage(code) {
    activeLanguage.value = code
    return fetchBooks()
  }

  function clearFilters() {
    query.value = ''
    activeCategory.value = null
    activeLanguage.value = null
    return fetchActive()
  }

  // Request to borrow. Optimistically flag the book; a 409 (already pending) is
  // benign and means it's effectively requested already.
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

  // Follow / unfollow a reader from an account card. Optimistic; the same 409
  // tolerance as requestBorrow (a duplicate follow is effectively a no-op).
  async function follow(userId) {
    const account = accounts.value.find(a => a.id === userId)
    try {
      await api.post(`/subscriptions/${userId}`)
      if (account) account.isSubscribed = true
    } catch (e) {
      if (e.response?.status === 409 && account) account.isSubscribed = true
      else throw e
    }
  }

  async function unfollow(userId) {
    const account = accounts.value.find(a => a.id === userId)
    await api.delete(`/subscriptions/${userId}`)
    if (account) account.isSubscribed = false
  }

  return {
    mode, books, accounts, categories, loading, error, query, activeCategory, activeLanguage,
    init, fetchBooks, fetchAccounts, fetchActive, setMode, setQuery, setCategory, setLanguage,
    clearFilters, requestBorrow, follow, unfollow,
  }
})
