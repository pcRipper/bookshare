import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the Discover page (`/discover`): browsing shareable books from other
 * public members, filtering by free-text query / category, and requesting to
 * borrow. The category list (filter pills) is the shared global vocabulary.
 */
export const useDiscoverStore = defineStore('discover', () => {
  const books = ref([])
  const categories = ref([])
  const loading = ref(false)
  const error = ref(null)

  // Active filters — the view binds these and calls fetchBooks() to apply.
  const query = ref('')
  const activeCategory = ref(null) // category id | null (= all)

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
      const { data } = await api.get('/books/discover', { params })
      if (token === reqToken) books.value = data
    } catch {
      if (token === reqToken) error.value = 'error'
    } finally {
      if (token === reqToken) loading.value = false
    }
  }

  async function init() {
    await Promise.all([fetchCategories(), fetchBooks()])
  }

  function setQuery(q) {
    query.value = q
    return fetchBooks()
  }

  function setCategory(id) {
    // Toggle: clicking the active pill clears the filter.
    activeCategory.value = activeCategory.value === id ? null : id
    return fetchBooks()
  }

  function clearFilters() {
    query.value = ''
    activeCategory.value = null
    return fetchBooks()
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

  return {
    books, categories, loading, error, query, activeCategory,
    init, fetchBooks, setQuery, setCategory, clearFilters, requestBorrow,
  }
})
