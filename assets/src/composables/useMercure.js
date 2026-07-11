import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useLibraryStore } from '@/stores/library'
import { useCollectionsStore } from '@/stores/collections'
import { useToastStore } from '@/stores/toast'

// Same-origin path to the Mercure hub (Vite proxies it in dev, nginx in prod).
const HUB_PATH = '/.well-known/mercure'
const RECONNECT_MIN = 1000
const RECONNECT_MAX = 30000
const REFETCH_DEBOUNCE = 700

/**
 * Real-time loan notifications over Mercure (SSE).
 *
 * Design is *signal-and-refetch*: the server pushes only a `{ reason, requestId }`
 * hint to this user's private `user/{id}` topic. We surface a toast and refetch the
 * affected lists through the existing authenticated store actions — never trusting
 * the payload as data. That keeps authorization in the REST layer and makes the
 * channel race/reconnect-safe (a refetch always reads committed truth).
 *
 * EventSource can't send the JWT Bearer header, so we first hit /api/mercure/token
 * to receive a signed subscribe-cookie scoped to our own topic.
 */
export function useMercure() {
  let source = null
  let backoff = RECONNECT_MIN
  let reconnectTimer = null
  let stopped = true
  // True once we've had an open connection this session. Used to distinguish the
  // initial connect (views load their own data) from a reconnect (must catch up).
  let everConnected = false

  // Coalesce a burst of signals into a single round of refetches.
  let refetchTimer = null
  const pendingFetches = new Set()

  function scheduleRefetch(fns) {
    fns.forEach(fn => pendingFetches.add(fn))
    if (refetchTimer) return
    refetchTimer = setTimeout(() => {
      refetchTimer = null
      const fns = [...pendingFetches]
      pendingFetches.clear()
      // Best-effort: a failed refetch shouldn't break the stream.
      fns.forEach(fn => fn().catch(() => {}))
    }, REFETCH_DEBOUNCE)
  }

  function handle({ reason }) {
    const lib = useLibraryStore()
    const col = useCollectionsStore()
    const toast = useToastStore()

    switch (reason) {
      case 'request.received':
        toast.info('New borrow request for one of your books.')
        scheduleRefetch([lib.fetchRequests])
        break
      case 'request.cancelled':
        toast.info('A borrow request was withdrawn.')
        scheduleRefetch([lib.fetchRequests])
        break
      case 'request.approved':
        toast.success('Your borrow request was approved.')
        scheduleRefetch([lib.fetchPendingBorrowing, lib.fetchBorrowing, lib.fetchBorrowingHistory])
        break
      case 'request.declined':
        toast.info('Your borrow request was declined.')
        scheduleRefetch([lib.fetchPendingBorrowing, lib.fetchBorrowingHistory])
        break
      case 'return.requested':
        toast.info('A borrower marked a book as returned.')
        scheduleRefetch([lib.fetchRequests, lib.fetchLending])
        break
      case 'return.confirmed':
        toast.success('Your book return was confirmed.')
        scheduleRefetch([lib.fetchBorrowing, lib.fetchBorrowingHistory, lib.fetchMe])
        break

      /* ── Collection borrows (one signal per collection, never per book) ── */
      case 'collection.request.received':
        toast.info('New request to borrow one of your collections.')
        scheduleRefetch([col.fetchIncoming])
        break
      case 'collection.request.cancelled':
        toast.info('A collection borrow request was withdrawn.')
        scheduleRefetch([col.fetchIncoming])
        break
      case 'collection.request.approved':
        toast.success('Your collection borrow request was approved.')
        scheduleRefetch([col.fetchPendingBorrowing, col.fetchBorrowing, col.fetchBorrowingHistory])
        break
      case 'collection.request.declined':
        toast.info('Your collection borrow request was declined.')
        scheduleRefetch([col.fetchPendingBorrowing, col.fetchBorrowingHistory])
        break
      case 'collection.return.requested':
        toast.info('A borrower marked a collection as returned.')
        scheduleRefetch([col.fetchIncoming, col.fetchLending])
        break
      case 'collection.return.confirmed':
        toast.success('Your collection return was confirmed.')
        scheduleRefetch([col.fetchBorrowing, col.fetchBorrowingHistory, lib.fetchMe])
        break

      default:
        // Unknown reason — ignore rather than guess.
        break
    }
  }

  function onMessage(event) {
    try {
      handle(JSON.parse(event.data))
    } catch {
      // Malformed payload — ignore.
    }
  }

  function open() {
    // The token request is async — if we were stopped (e.g. logout) while it was
    // in flight, don't open a now-orphaned connection.
    if (stopped) return

    const auth = useAuthStore()
    const userId = auth.user?.id
    if (!userId) return

    const topic = encodeURIComponent(`user/${userId}`)
    source = new EventSource(`${HUB_PATH}?topic=${topic}`, { withCredentials: true })
    source.onmessage = onMessage
    source.onopen = () => {
      backoff = RECONNECT_MIN
      // A signal sent during a disconnect (e.g. the ~hourly cookie-JWT expiry, or
      // any network blip) is lost. On *reconnect* — not the first connect — catch
      // up by refetching every loan list so nothing is silently missed.
      if (everConnected) {
        const lib = useLibraryStore()
        const col = useCollectionsStore()
        scheduleRefetch([
          lib.fetchRequests,
          lib.fetchLending,
          lib.fetchBorrowing,
          lib.fetchPendingBorrowing,
          lib.fetchBorrowingHistory,
          lib.fetchMe,
          col.fetchIncoming,
          col.fetchLending,
          col.fetchBorrowing,
          col.fetchPendingBorrowing,
        ])
      }
      everConnected = true
    }
    source.onerror = () => {
      // CLOSED = fatal (e.g. the subscribe-cookie expired → 401). Refresh the
      // cookie and reconnect. CONNECTING = transient drop; let EventSource retry.
      if (source && source.readyState === EventSource.CLOSED) {
        scheduleReconnect()
      }
    }
  }

  function scheduleReconnect() {
    teardownSource()
    if (stopped || reconnectTimer) return
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null
      connect()
    }, backoff)
    backoff = Math.min(backoff * 2, RECONNECT_MAX)
  }

  // Mint/refresh the subscribe-cookie, then open the stream.
  function connect() {
    if (stopped) return
    api
      .get('/mercure/token')
      .then(open)
      .catch(scheduleReconnect)
  }

  function teardownSource() {
    if (source) {
      source.onmessage = source.onopen = source.onerror = null
      source.close()
      source = null
    }
  }

  function start() {
    if (!stopped) return
    stopped = false
    backoff = RECONNECT_MIN
    // Fresh session: the first open is initial, not a catch-up reconnect.
    everConnected = false
    connect()
  }

  function stop() {
    stopped = true
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    if (refetchTimer) {
      clearTimeout(refetchTimer)
      refetchTimer = null
    }
    pendingFetches.clear()
    teardownSource()
  }

  return { start, stop }
}
