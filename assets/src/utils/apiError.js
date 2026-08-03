import { t } from '@/i18n'

/**
 * Pulls a human-readable message out of a failed API response, coping with both
 * shapes the backend emits: RFC 7807 problem-details (`{ detail }`) and the
 * simpler `{ error }` payloads. Falls back to a generic message.
 *
 * Server-side text arrives already translated (the axios interceptor sends
 * `Accept-Language`), so only the fallback needs the catalog. It's resolved
 * lazily — at module-eval time the i18n instance isn't ready yet.
 */
export function apiErrorMessage(error, fallback = null) {
  const data = error?.response?.data
  return data?.detail || data?.error || error?.message || fallback || t('errors.generic')
}
