import { currentLocale, t } from '@/i18n'

/**
 * Formats an ISO-8601 timestamp as a short relative string for the UI,
 * e.g. "today", "yesterday", "3 days ago", or a date for older items.
 *
 * The buckets go through the message catalog (Ukrainian needs three plural
 * forms, so the counted ones use `t`'s pluralization), and the absolute
 * fallback is formatted in the active locale rather than the browser's.
 */
export function relativeTime(iso) {
  if (!iso) return ''
  const then = new Date(iso)
  const now = new Date()
  const days = Math.floor((startOfDay(now) - startOfDay(then)) / 86_400_000)

  if (days <= 0) return t('time.today')
  if (days === 1) return t('time.yesterday')
  if (days < 7) return t('time.daysAgo', days, { named: { count: days } })
  if (days < 14) return t('time.lastWeek')
  if (days < 30) {
    const weeks = Math.floor(days / 7)
    return t('time.weeksAgo', weeks, { named: { count: weeks } })
  }
  return then.toLocaleDateString(currentLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
}

function startOfDay(d) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
}
