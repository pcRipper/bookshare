import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the signed-out share page (`/public/library/:id`).
 *
 * Mirrors the profile store's shape so the view can be modelled on ProfileView,
 * but talks only to `/public/*` and carries no viewer-relative state — there is
 * no viewer, so no borrow action, no follow, no `isSelf`.
 *
 * The server answers a private *and* an unknown member with the same 404, so
 * there is deliberately no 'private' error branch to distinguish here.
 */
export const usePublicLibraryStore = defineStore('publicLibrary', () => {
  const emptyMeta = () => ({ page: 1, perPage: 24, total: 0, totalPages: 1 })

  const owner = ref(null)
  const books = ref([])
  const booksMeta = ref(emptyMeta())
  const booksLoading = ref(false)
  const availableCount = ref(0)
  const shelf = ref('available')        // 'available' (status=own) | 'full'
  const booksQuery = ref('')
  const loading = ref(false)
  const error = ref(null)               // 'not-found' | 'error' | null
  const currentId = ref(null)

  const collections = ref([])
  const collectionsMeta = ref(emptyMeta())
  const collectionsLoading = ref(false)

  async function fetchLibrary(id) {
    currentId.value = id
    error.value = null
    loading.value = true
    owner.value = null
    books.value = []
    collections.value = []
    shelf.value = 'available'
    booksQuery.value = ''
    booksMeta.value = emptyMeta()
    collectionsMeta.value = emptyMeta()

    try {
      const [ownerRes] = await Promise.all([
        api.get(`/public/users/${id}`),
        fetchBooksPage(1, { silent: true }),
      ])
      owner.value = ownerRes.data
    } catch (e) {
      error.value = e.response?.status === 404 ? 'not-found' : 'error'
    } finally {
      loading.value = false
    }
  }

  async function fetchBooksPage(page = 1, { silent = false } = {}) {
    if (!silent) booksLoading.value = true
    try {
      const params = { page }
      if (shelf.value === 'available') params.status = 'own'
      if (booksQuery.value) params.q = booksQuery.value
      const { data } = await api.get(`/public/users/${currentId.value}/books`, { params })
      books.value = data.items
      booksMeta.value = data.pagination
      // Keep the tab chip on the shelf's true size — don't let a search shrink it.
      if (shelf.value === 'available' && !booksQuery.value) availableCount.value = data.pagination.total
    } finally {
      if (!silent) booksLoading.value = false
    }
  }

  async function fetchCollectionsPage(page = 1) {
    collectionsLoading.value = true
    try {
      const { data } = await api.get(`/public/users/${currentId.value}/collections`, { params: { page } })
      collections.value = data.items
      collectionsMeta.value = data.pagination
    } finally {
      collectionsLoading.value = false
    }
  }

  function setShelf(next) {
    if (next === shelf.value) return
    shelf.value = next
    booksQuery.value = ''
    return fetchBooksPage(1)
  }

  function setBooksSearch(q) {
    booksQuery.value = q
    return fetchBooksPage(1)
  }

  return {
    owner, books, booksMeta, booksLoading, availableCount, shelf, booksQuery, loading, error,
    collections, collectionsMeta, collectionsLoading,
    fetchLibrary, fetchBooksPage, fetchCollectionsPage, setShelf, setBooksSearch,
  }
})
