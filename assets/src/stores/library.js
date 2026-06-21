import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'
import { relativeTime } from '@/utils/time'

/**
 * Backs the Library page: profile + stats, the four tab datasets, and the
 * category list, plus all mutating actions (book CRUD, approve/decline).
 */
export const useLibraryStore = defineStore('library', () => {
  const profile = ref(null)
  const stats = ref({ totalBooks: 0, shared: 0, loaned: 0 })
  const collection = ref([])
  const lending = ref([])
  const requests = ref([])   // pending incoming requests
  const history = ref([])    // resolved incoming requests
  const categories = ref([])

  const loading = ref({ collection: false, lending: false, requests: false, history: false })
  const error = ref(null)

  // Map an API request payload to RequestCard's expected shape (relative date).
  function toCardRequest(r) {
    return { ...r, requestedAt: relativeTime(r.requestedAt) }
  }

  async function fetchMe() {
    const { data } = await api.get('/me')
    stats.value = data.stats
    profile.value = data
  }

  async function fetchCollection() {
    loading.value.collection = true
    try {
      const { data } = await api.get('/books')
      collection.value = data
    } finally {
      loading.value.collection = false
    }
  }

  async function fetchLending() {
    loading.value.lending = true
    try {
      const { data } = await api.get('/books', { params: { status: 'lent' } })
      lending.value = data
    } finally {
      loading.value.lending = false
    }
  }

  async function fetchRequests() {
    loading.value.requests = true
    try {
      const { data } = await api.get('/requests/incoming', { params: { status: 'pending' } })
      requests.value = data.map(toCardRequest)
    } finally {
      loading.value.requests = false
    }
  }

  async function fetchHistory() {
    loading.value.history = true
    try {
      const { data } = await api.get('/requests/incoming', { params: { status: 'resolved' } })
      history.value = data.map(toCardRequest)
    } finally {
      loading.value.history = false
    }
  }

  async function fetchCategories() {
    const { data } = await api.get('/categories')
    categories.value = data
  }

  // Search existing categories by name. Returns the matches (not stored) so the
  // Manage Book picker can decide between "pick a match" and "create new".
  async function searchCategories(q) {
    const { data } = await api.get('/categories', { params: q ? { q } : {} })
    return data
  }

  // Create a brand-new category, then keep the cached global list fresh.
  async function createCategory(payload) {
    const { data } = await api.post('/categories', payload)
    if (!categories.value.some(c => c.id === data.id)) categories.value.push(data)
    return data
  }

  async function createBook(payload) {
    await api.post('/books', payload)
    await Promise.all([fetchCollection(), fetchMe()])
  }

  async function updateBook(id, payload) {
    await api.patch(`/books/${id}`, payload)
    await Promise.all([fetchCollection(), fetchMe()])
    // a status change may add/remove from lending — refresh if already loaded
    if (lending.value.length) await fetchLending()
  }

  async function deleteBook(id) {
    await api.delete(`/books/${id}`)
    await Promise.all([fetchCollection(), fetchMe()])
  }

  async function approveRequest(id) {
    await api.post(`/requests/${id}/approve`)
    requests.value = requests.value.filter(r => r.id !== id)
    await fetchMe()
  }

  async function declineRequest(id) {
    await api.post(`/requests/${id}/decline`)
    requests.value = requests.value.filter(r => r.id !== id)
  }

  return {
    profile, stats, collection, lending, requests, history, categories, loading, error,
    fetchMe, fetchCollection, fetchLending, fetchRequests, fetchHistory, fetchCategories,
    searchCategories, createCategory,
    createBook, updateBook, deleteBook, approveRequest, declineRequest,
  }
})
