/**
 * Pulls a human-readable message out of a failed API response, coping with both
 * shapes the backend emits: RFC 7807 problem-details (`{ detail }`) and the
 * simpler `{ error }` payloads. Falls back to a generic message.
 */
export function apiErrorMessage(error, fallback = 'Something went wrong. Please try again.') {
  const data = error?.response?.data
  return data?.detail || data?.error || error?.message || fallback
}
