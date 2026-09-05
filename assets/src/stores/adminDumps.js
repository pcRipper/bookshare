import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'

/**
 * Backs the admin panel's Dumps tab.
 *
 * Not paginated and deliberately not shaped like the other list stores: the
 * server keeps ten of each kind, so the list is bounded by construction and a
 * pager would render nothing forever.
 */
export const useAdminDumpsStore = defineStore('adminDumps', () => {
  const items = ref([])
  // Which kinds this server can actually produce. `pg_dump` is absent outside
  // the container, and a button that always fails is worse than a disabled one.
  const capabilities = ref({ sql: false, json: false })

  const loading = ref(false)
  // The kind currently being produced, so the two buttons can show their own
  // spinner rather than sharing one flag.
  const creating = ref(null)
  // null | 'forbidden' | 'error' — a code, not a message, like the other stores.
  const error = ref(null)

  async function fetchDumps() {
    loading.value = true
    error.value = null

    try {
      const { data } = await api.get('/admin/dumps')
      items.value = data.items
      capabilities.value = data.capabilities
    } catch (e) {
      error.value = e.response?.status === 403 ? 'forbidden' : 'error'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function create(kind) {
    creating.value = kind
    try {
      const { data } = await api.post('/admin/dumps', { kind })
      // Newest first, matching the server's own order — no refetch needed for
      // the row, but the list may have just pruned its oldest sibling, so ask.
      items.value = [data, ...items.value]
      await fetchDumps()

      return data
    } finally {
      creating.value = null
    }
  }

  async function remove(name) {
    await api.delete(`/admin/dumps/${encodeURIComponent(name)}`)
    items.value = items.value.filter(d => d.name !== name)
  }

  /**
   * Downloads a dump.
   *
   * Through axios as a blob rather than an `<a href>`: the endpoint sits behind
   * the admin firewall and a plain link cannot send the Bearer header — the same
   * constraint that made the public QR endpoint public, inverted.
   *
   * The object URL is revoked on the next tick; revoking it synchronously races
   * the browser's own fetch of it in some engines.
   */
  async function download(name) {
    const { data } = await api.get(`/admin/dumps/${encodeURIComponent(name)}`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 0)
  }

  return { items, capabilities, loading, creating, error, fetchDumps, create, remove, download }
})
