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
  const requests = ref([])   // open incoming requests (pending + return-pending)
  const history = ref([])    // all incoming requests, any state — lending history (full timeline)
  const borrowing = ref([])  // active outgoing loans (books I'm borrowing)
  const borrowingHistory = ref([]) // all outgoing requests, any state — borrowing history (full timeline)
  const categories = ref([])

  const loading = ref({ collection: false, lending: false, requests: false, history: false, borrowing: false, borrowingHistory: false })
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
      // "open" = pending borrow requests + return confirmations awaiting the owner.
      const { data } = await api.get('/requests/incoming', { params: { status: 'open' } })
      requests.value = data.map(toCardRequest)
    } finally {
      loading.value.requests = false
    }
  }

  async function fetchHistory() {
    loading.value.history = true
    try {
      // Every incoming request, in-progress or finished, so History shows each step.
      const { data } = await api.get('/requests/incoming', { params: { status: 'all' } })
      history.value = data.map(toCardRequest)
    } finally {
      loading.value.history = false
    }
  }

  // Books the current user is currently borrowing (active outgoing loans).
  async function fetchBorrowing() {
    loading.value.borrowing = true
    try {
      const { data } = await api.get('/requests/outgoing', { params: { status: 'active' } })
      borrowing.value = data.map(toCardRequest)
    } finally {
      loading.value.borrowing = false
    }
  }

  // The current user's borrows — every outgoing request, in-progress or finished.
  async function fetchBorrowingHistory() {
    loading.value.borrowingHistory = true
    try {
      const { data } = await api.get('/requests/outgoing', { params: { status: 'all' } })
      borrowingHistory.value = data.map(toCardRequest)
    } finally {
      loading.value.borrowingHistory = false
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

  // Download the collection as a CSV file (triggers a browser download).
  async function exportBooks() {
    const { data } = await api.get('/books/export', { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = url
    a.download = 'folioshare-books.csv'
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }

  // Upload a CSV to bulk-import books. `mode` = append|replace, `onError` =
  // skip|abort. Returns the server summary { imported, skipped, aborted, errors }.
  async function importBooks(file, { mode = 'append', onError = 'skip' } = {}) {
    const form = new FormData()
    form.append('file', file)
    form.append('mode', mode)
    form.append('onError', onError)
    const { data } = await api.post('/books/import', form)
    await Promise.all([fetchCollection(), fetchMe()])
    return data
  }

  async function approveRequest(id, dueDate = null) {
    await api.post(`/requests/${id}/approve`, { dueDate })
    requests.value = requests.value.filter(r => r.id !== id)
    // The book is now an active loan (lent) and stats changed — refresh Lending
    // and the profile so the other tabs reflect it without a reload.
    await Promise.all([fetchMe(), fetchLending()])
  }

  async function declineRequest(id) {
    await api.post(`/requests/${id}/decline`)
    requests.value = requests.value.filter(r => r.id !== id)
    // The declined request moves into History — refresh it so it shows up there.
    await fetchHistory()
  }

  // Owner confirms a returned book was received: it leaves the open inbox, the
  // book returns to the collection (own), and the loan closes into History.
  async function confirmReturn(id) {
    await api.post(`/requests/${id}/confirm-return`)
    requests.value = requests.value.filter(r => r.id !== id)
    await Promise.all([fetchMe(), fetchCollection(), fetchLending(), fetchHistory()])
  }

  // Borrower marks a borrowed book as returned (awaits the owner's confirmation).
  async function returnBook(id) {
    await api.post(`/requests/${id}/return`)
    await fetchBorrowing()
  }

  return {
    profile, stats, collection, lending, requests, history, borrowing, borrowingHistory, categories, loading, error,
    fetchMe, fetchCollection, fetchLending, fetchRequests, fetchHistory, fetchBorrowing, fetchBorrowingHistory, fetchCategories,
    searchCategories, createCategory,
    createBook, updateBook, deleteBook, exportBooks, importBooks,
    approveRequest, declineRequest, confirmReturn, returnBook,
  }
})
