import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'
import { relativeTime } from '@/utils/time'

/**
 * Backs the book-collections feature: the owner's collections (Library tab) with
 * CRUD, another reader's collections (Profile tab), collection-borrow creation,
 * and the grouped collection-request lists/transitions that live alongside the
 * per-book ones in the Requests / Borrowing / Lending / History tabs.
 *
 * Mirrors the shapes and conventions of the `library` store (paginated
 * `fetchX(page)`, bare in-flight arrays refetched wholesale on Mercure signals).
 */
export const useCollectionsStore = defineStore('collections', () => {
  const emptyMeta = () => ({ page: 1, perPage: 24, total: 0, totalPages: 1 })

  // Owner's own collections (Library → Collections tab).
  const mine = ref([])
  const mineMeta = ref(emptyMeta())

  // A viewed reader's collections (Profile → Collections tab).
  const profileCollections = ref([])
  const profileMeta = ref(emptyMeta())
  const profileOwnerId = ref(null)

  // Grouped collection requests, split like the per-book request lists.
  const incoming = ref([])          // open incoming (owner side): pending + return-pending
  const pendingBorrowing = ref([])  // outgoing still awaiting the owner's decision
  const borrowing = ref([])         // active outgoing collection loans
  const lending = ref([])           // active incoming collection loans (owner side)
  const history = ref([])           // all incoming, any state — lending history
  const historyMeta = ref(emptyMeta())
  const borrowingHistory = ref([])  // all outgoing, any state — borrowing history
  const borrowingHistoryMeta = ref(emptyMeta())

  const loading = ref({
    mine: false, profile: false, incoming: false, pendingBorrowing: false,
    borrowing: false, lending: false, history: false, borrowingHistory: false,
  })

  function toCard(r) {
    return { ...r, requestedAt: relativeTime(r.requestedAt) }
  }

  /* ── Collection CRUD (owner) ─────────────────────────────────────────── */

  async function fetchMine(page = mineMeta.value.page) {
    loading.value.mine = true
    try {
      const { data } = await api.get('/collections', { params: { page } })
      mine.value = data.items
      mineMeta.value = data.pagination
    } finally {
      loading.value.mine = false
    }
  }

  async function createCollection(payload) {
    const { data } = await api.post('/collections', payload)
    await fetchMine(1)
    return data
  }

  async function updateCollection(id, payload) {
    const { data } = await api.patch(`/collections/${id}`, payload)
    await fetchMine()
    return data
  }

  async function deleteCollection(id) {
    await api.delete(`/collections/${id}`)
    mine.value = mine.value.filter(c => c.id !== id)
  }

  /* ── Viewing a reader's collections (Profile) ────────────────────────── */

  async function fetchProfileCollections(ownerId, page = 1) {
    profileOwnerId.value = ownerId
    loading.value.profile = true
    try {
      const { data } = await api.get('/collections', { params: { owner: ownerId, page } })
      profileCollections.value = data.items
      profileMeta.value = data.pagination
    } finally {
      loading.value.profile = false
    }
  }

  /* ── Borrow + grouped-request lists ──────────────────────────────────── */

  async function borrowCollection(collectionId, bookIds) {
    const { data } = await api.post('/collection-requests', { collectionId, bookIds })
    return data
  }

  async function fetchIncoming() {
    loading.value.incoming = true
    try {
      const { data } = await api.get('/collection-requests/incoming', { params: { status: 'open' } })
      incoming.value = data.map(toCard)
    } finally {
      loading.value.incoming = false
    }
  }

  async function fetchPendingBorrowing() {
    loading.value.pendingBorrowing = true
    try {
      const { data } = await api.get('/collection-requests/outgoing', { params: { status: 'pending' } })
      pendingBorrowing.value = data.map(toCard)
    } finally {
      loading.value.pendingBorrowing = false
    }
  }

  async function fetchBorrowing() {
    loading.value.borrowing = true
    try {
      const { data } = await api.get('/collection-requests/outgoing', { params: { status: 'active' } })
      borrowing.value = data.map(toCard)
    } finally {
      loading.value.borrowing = false
    }
  }

  async function fetchLending() {
    loading.value.lending = true
    try {
      const { data } = await api.get('/collection-requests/incoming', { params: { status: 'active' } })
      lending.value = data.map(toCard)
    } finally {
      loading.value.lending = false
    }
  }

  async function fetchHistory(page = historyMeta.value.page) {
    loading.value.history = true
    try {
      const { data } = await api.get('/collection-requests/incoming', { params: { status: 'all', page } })
      history.value = data.items.map(toCard)
      historyMeta.value = data.pagination
    } finally {
      loading.value.history = false
    }
  }

  async function fetchBorrowingHistory(page = borrowingHistoryMeta.value.page) {
    loading.value.borrowingHistory = true
    try {
      const { data } = await api.get('/collection-requests/outgoing', { params: { status: 'all', page } })
      borrowingHistory.value = data.items.map(toCard)
      borrowingHistoryMeta.value = data.pagination
    } finally {
      loading.value.borrowingHistory = false
    }
  }

  /* ── Grouped-request transitions ─────────────────────────────────────── */

  async function approve(id, dueDate = null) {
    await api.post(`/collection-requests/${id}/approve`, { dueDate })
    incoming.value = incoming.value.filter(r => r.id !== id)
    await Promise.all([fetchLending(), fetchHistory()])
  }

  async function decline(id, message = null) {
    await api.post(`/collection-requests/${id}/decline`, { message })
    incoming.value = incoming.value.filter(r => r.id !== id)
    await fetchHistory()
  }

  async function confirmReturn(id) {
    await api.post(`/collection-requests/${id}/confirm-return`)
    incoming.value = incoming.value.filter(r => r.id !== id)
    await Promise.all([fetchLending(), fetchHistory()])
  }

  async function returnCollection(id) {
    await api.post(`/collection-requests/${id}/return`)
    await fetchBorrowing()
  }

  async function cancel(id) {
    await api.delete(`/collection-requests/${id}`)
    pendingBorrowing.value = pendingBorrowing.value.filter(r => r.id !== id)
  }

  return {
    mine, mineMeta, profileCollections, profileMeta, profileOwnerId,
    incoming, pendingBorrowing, borrowing, lending, history, historyMeta,
    borrowingHistory, borrowingHistoryMeta, loading,
    fetchMine, createCollection, updateCollection, deleteCollection,
    fetchProfileCollections, borrowCollection,
    fetchIncoming, fetchPendingBorrowing, fetchBorrowing, fetchLending,
    fetchHistory, fetchBorrowingHistory,
    approve, decline, confirmReturn, returnCollection, cancel,
  }
})
