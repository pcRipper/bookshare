import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the admin panel's Members tab.
 *
 * Separate from `admin`, which holds the analytics dashboard: the two share a
 * page and nothing else — different endpoints, different lifecycles, and this
 * one writes. Folding them together would put a paginated, mutable list beside a
 * single immutable snapshot behind one `loading` flag.
 *
 * Paginated-store shape, as documented: `{ items, page, perPage, total,
 * totalPages }` with a `fetch(page)` that *replaces* the page rather than
 * appending.
 */
export const useAdminUsersStore = defineStore('adminUsers', () => {
  const items = ref([])
  const page = ref(1)
  const perPage = ref(25)
  const total = ref(0)
  const totalPages = ref(0)

  const query = ref('')
  const status = ref('all')

  const loading = ref(false)
  // null | 'forbidden' | 'error' — a code, not a message, like the other stores.
  const error = ref(null)

  // The filter row invites rapid clicking and a search box fires on a debounce,
  // so a superseded response can easily land last. Same guard the analytics
  // store documents, for the same reason.
  let reqToken = 0

  async function fetchUsers(nextPage = page.value) {
    const token = ++reqToken
    loading.value = true
    error.value = null

    try {
      const { data } = await api.get('/admin/users', {
        params: {
          page: nextPage,
          q: query.value || undefined,
          status: status.value === 'all' ? undefined : status.value,
        },
      })

      if (token !== reqToken) return

      items.value = data.items
      page.value = data.pagination.page
      perPage.value = data.pagination.perPage
      total.value = data.pagination.total
      totalPages.value = data.pagination.totalPages
    } catch (e) {
      if (token === reqToken) {
        error.value = e.response?.status === 403 ? 'forbidden' : 'error'
      }
      throw e
    } finally {
      if (token === reqToken) loading.value = false
    }
  }

  /** A filter change is always a new first page — page 4 of the old result set
   *  is meaningless against the new one, and often past its end. */
  function setQuery(value) {
    query.value = value
    return fetchUsers(1)
  }

  function setStatus(value) {
    if (value === status.value) return undefined
    status.value = value
    return fetchUsers(1)
  }

  /**
   * The three write actions all return the member in their new state, so the row
   * is replaced in place rather than refetching the page. A refetch would be
   * correct but visibly worse: under the `active` filter, banning someone makes
   * their row vanish mid-click, which reads as the wrong row having been hit.
   */
  function replace(user) {
    const i = items.value.findIndex(u => u.id === user.id)
    if (i !== -1) items.value[i] = user
  }

  async function ban(id, reason) {
    const { data } = await api.post(`/admin/users/${id}/ban`, { reason: reason || null })
    replace(data)
    return data
  }

  async function unban(id) {
    const { data } = await api.post(`/admin/users/${id}/unban`)
    replace(data)
    return data
  }

  async function remove(id) {
    const { data } = await api.delete(`/admin/users/${id}`)
    replace(data)
    return data
  }

  return {
    items, page, perPage, total, totalPages,
    query, status, loading, error,
    fetchUsers, setQuery, setStatus, ban, unban, remove,
  }
})
