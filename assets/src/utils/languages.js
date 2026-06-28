import api from '@/api'

/**
 * Loads the language vocabulary (`[{ code, name }]`) from the API and memoizes
 * it for the session — the list is static, so one fetch backs every dropdown.
 * Concurrent callers share the in-flight request.
 */
let cache = null
let inflight = null

export async function loadLanguages() {
  if (cache) return cache
  if (!inflight) {
    inflight = api
      .get('/languages')
      .then(({ data }) => {
        cache = data
        inflight = null
        return cache
      })
      .catch(e => {
        inflight = null
        throw e
      })
  }
  return inflight
}
