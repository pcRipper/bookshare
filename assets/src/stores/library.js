import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'
import { relativeTime } from '@/utils/time'

/**
 * Backs the Library page: profile + stats, the four tab datasets, and the
 * category list, plus all mutating actions (book CRUD, approve/decline).
 */
export const useLibraryStore = defineStore('library', () => {
  // Default pagination metadata before the first response lands.
  const emptyMeta = () => ({ page: 1, perPage: 24, total: 0, totalPages: 1 })

  const profile = ref(null)
  const stats = ref({ totalBooks: 0, shared: 0, loaned: 0 })
  const collection = ref([])
  const collectionMeta = ref(emptyMeta())  // pagination for the collection grid
  const collectionQuery = ref('')          // free-text filter (title/author/ISBN)
  const lending = ref([])
  const requests = ref([])   // open incoming requests (pending + return-pending)
  const history = ref([])    // all incoming requests, any state — lending history (full timeline)
  const historyMeta = ref(emptyMeta())     // pagination for the lending-history list
  const borrowing = ref([])  // active outgoing loans (books I'm borrowing)
  const pendingBorrowing = ref([]) // my outgoing requests still awaiting the owner's decision
  const borrowingHistory = ref([]) // all outgoing requests, any state — borrowing history (full timeline)
  const borrowingHistoryMeta = ref(emptyMeta()) // pagination for the borrowing-history list
  const categories = ref([])

  const loading = ref({ collection: false, lending: false, requests: false, history: false, borrowing: false, pendingBorrowing: false, borrowingHistory: false })
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

  // Collection is a numbered-page grid; defaults to the currently-viewed page so
  // refetches after a mutation keep the user where they were. The active search
  // query (if any) narrows it server-side.
  async function fetchCollection(page = collectionMeta.value.page) {
    loading.value.collection = true
    try {
      const params = { page }
      if (collectionQuery.value) params.q = collectionQuery.value
      const { data } = await api.get('/books', { params })
      collection.value = data.items
      collectionMeta.value = data.pagination
    } finally {
      loading.value.collection = false
    }
  }

  // Set the collection search term and reload from its first page.
  function setCollectionSearch(q) {
    collectionQuery.value = q
    return fetchCollection(1)
  }

  // Lending is a naturally-small in-flight list (books currently out on loan);
  // it shares the paginated /books endpoint but isn't page-controlled in the UI,
  // so fetch a single generous page and read the envelope's items.
  async function fetchLending() {
    loading.value.lending = true
    try {
      // excludeCollectionLoans: books out via a collection are shown grouped in
      // the collection card, so keep them out of the individual Lending grid.
      const { data } = await api.get('/books', { params: { status: 'lent', perPage: 100, excludeCollectionLoans: 1 } })
      lending.value = data.items
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

  async function fetchHistory(page = historyMeta.value.page) {
    loading.value.history = true
    try {
      // Every incoming request, in-progress or finished, so History shows each step.
      const { data } = await api.get('/requests/incoming', { params: { status: 'all', page } })
      history.value = data.items.map(toCardRequest)
      historyMeta.value = data.pagination
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

  // The current user's outgoing requests still awaiting the owner's decision.
  async function fetchPendingBorrowing() {
    loading.value.pendingBorrowing = true
    try {
      const { data } = await api.get('/requests/outgoing', { params: { status: 'pending' } })
      pendingBorrowing.value = data.map(toCardRequest)
    } finally {
      loading.value.pendingBorrowing = false
    }
  }

  // The current user's borrows — every outgoing request, in-progress or finished.
  async function fetchBorrowingHistory(page = borrowingHistoryMeta.value.page) {
    loading.value.borrowingHistory = true
    try {
      const { data } = await api.get('/requests/outgoing', { params: { status: 'all', page } })
      borrowingHistory.value = data.items.map(toCardRequest)
      borrowingHistoryMeta.value = data.pagination
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

  // Search book templates to pre-fill the create form. `source` picks the
  // strategy ('site' searches the catalogue, 'external' hits Open Library).
  // Pass an AbortSignal so a superseded search can cancel its in-flight request
  // (external calls are rate-limited upstream). Returns the matches — not stored.
  async function searchBookTemplates(q, source = 'site', page = 1, signal = undefined) {
    const { data } = await api.get('/books/templates', { params: { q, source, page }, signal })
    return data // { items, hasMore }
  }

  async function createBook(payload) {
    await api.post('/books', payload)
    // A new book is newest-first → jump to page 1 so it's visible.
    await Promise.all([fetchCollection(1), fetchMe()])
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
    await Promise.all([fetchCollection(1), fetchMe()])
    return data
  }

  async function approveRequest(id, dueDate = null) {
    await api.post(`/requests/${id}/approve`, { dueDate })
    requests.value = requests.value.filter(r => r.id !== id)
    // The book is now an active loan (lent) and stats changed — refresh Lending
    // and the profile so the other tabs reflect it without a reload.
    await Promise.all([fetchMe(), fetchLending()])
  }

  async function declineRequest(id, message = null) {
    await api.post(`/requests/${id}/decline`, { message })
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

  // Borrower withdraws their own still-pending request (deletes it server-side).
  async function cancelRequest(id) {
    await api.delete(`/requests/${id}`)
    pendingBorrowing.value = pendingBorrowing.value.filter(r => r.id !== id)
  }

  return {
    profile, stats, collection, collectionMeta, collectionQuery, lending, requests, history, historyMeta, borrowing, pendingBorrowing, borrowingHistory, borrowingHistoryMeta, categories, loading, error,
    fetchMe, fetchCollection, setCollectionSearch, fetchLending, fetchRequests, fetchHistory, fetchBorrowing, fetchPendingBorrowing, fetchBorrowingHistory, fetchCategories,
    searchCategories, createCategory, searchBookTemplates,
    createBook, updateBook, deleteBook, exportBooks, importBooks,
    approveRequest, declineRequest, confirmReturn, returnBook, cancelRequest,
  }
})
