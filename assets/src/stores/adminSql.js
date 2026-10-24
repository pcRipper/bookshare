import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * Backs the SQL sandbox and the shared query log on the admin Database tab.
 *
 * A write is never run in one step. `run()` sends it with `commit: false`, the
 * server executes it and rolls it back, and the row count it would touch comes
 * back as `preview`. Only `commit()` sends it again for real, carrying that
 * count as `expectedRows`, so a change that would now touch a different number
 * of rows is refused rather than applied blind.
 *
 * Every run refetches the log's first page: the attempt just made is always its
 * newest row, refused and failed ones included.
 */
export const useAdminSqlStore = defineStore('adminSql', () => {
  const capabilities = ref({ sandbox: false })
  const limits = ref({ maxRows: 500, maxAffected: 1000, timeoutMs: 5000, maxLength: 10000 })
  const statusLoaded = ref(false)

  const running = ref(false)
  // A read's result, or a committed write's `{ kind, rowCount, committed }`.
  const result = ref(null)
  // `{ sql, rowCount }` while a write waits to be confirmed.
  const preview = ref(null)
  // `{ message, sqlState }` — a refusal or the database's own error, shown
  // inline next to the statement rather than as a toast that disappears.
  const failure = ref(null)

  const log = ref({ items: [], page: 1, perPage: 20, total: 0, totalPages: 0 })
  const logLoading = ref(false)
  // null | 'forbidden' | 'error' — a code, not a message, like the other stores.
  const error = ref(null)

  // Out-of-order guard: a slow statement must not overwrite a newer one's
  // result. Same idea as the dashboard's reqToken.
  let runToken = 0
  let logToken = 0

  async function fetchStatus() {
    error.value = null
    try {
      const { data } = await api.get('/admin/sql')
      capabilities.value = data.capabilities
      limits.value = data.limits
      statusLoaded.value = true
    } catch (e) {
      error.value = e.response?.status === 403 ? 'forbidden' : 'error'
      throw e
    }
  }

  async function fetchLog(page = log.value.page) {
    const token = ++logToken
    logLoading.value = true
    try {
      const { data } = await api.get('/admin/sql/log', { params: { page } })
      if (token !== logToken) return
      log.value = { items: data.items, ...data.pagination }
    } finally {
      if (token === logToken) logLoading.value = false
    }
  }

  /**
   * The statuses the sandbox answers with a reason worth showing in place:
   * 400 (PostgreSQL refused it), 422 (the guard or a rule did), 503 (the
   * sandbox is off). Anything else — 403, 429, a network failure — throws for
   * the caller to toast.
   */
  function inlineFailure(e) {
    const status = e.response?.status
    if (![400, 422, 503].includes(status)) return false

    failure.value = {
      message: apiErrorMessage(e),
      sqlState: e.response?.data?.sqlState ?? null,
    }
    return true
  }

  async function send(payload) {
    const token = ++runToken
    running.value = true
    failure.value = null
    result.value = null

    try {
      const { data } = await api.post('/admin/sql', payload)
      return token === runToken ? data : null
    } catch (e) {
      if (token !== runToken) return null
      if (!inlineFailure(e)) throw e
      return null
    } finally {
      if (token === runToken) running.value = false
      // Best-effort: the log refreshing must never mask the statement's own
      // outcome.
      fetchLog(1).catch(() => {})
    }
  }

  async function run(sql) {
    preview.value = null
    const data = await send({ sql })
    if (!data) return null

    if (data.kind === 'write') {
      preview.value = { sql, rowCount: data.rowCount }
    } else {
      result.value = data
    }
    return data
  }

  async function commit() {
    if (!preview.value) return null
    const { sql, rowCount } = preview.value
    preview.value = null

    const data = await send({ sql, commit: true, expectedRows: rowCount })
    if (data) result.value = data
    return data
  }

  function cancelPreview() {
    preview.value = null
  }

  return {
    capabilities,
    limits,
    statusLoaded,
    running,
    result,
    preview,
    failure,
    log,
    logLoading,
    error,
    fetchStatus,
    fetchLog,
    run,
    commit,
    cancelPreview,
  }
})
