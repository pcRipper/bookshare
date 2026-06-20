/**
 * Formats an ISO-8601 timestamp as a short relative string for the UI,
 * e.g. "today", "yesterday", "3 days ago", or a date for older items.
 */
export function relativeTime(iso) {
  if (!iso) return ''
  const then = new Date(iso)
  const now = new Date()
  const days = Math.floor((startOfDay(now) - startOfDay(then)) / 86_400_000)

  if (days <= 0) return 'today'
  if (days === 1) return 'yesterday'
  if (days < 7) return `${days} days ago`
  if (days < 14) return 'last week'
  if (days < 30) return `${Math.floor(days / 7)} weeks ago`
  return then.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function startOfDay(d) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
}
