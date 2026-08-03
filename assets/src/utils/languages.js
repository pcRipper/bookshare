import api from '@/api'
import { currentLocale } from '@/i18n'

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

/**
 * The display label for a book language. The API's `name` / `languageName` is
 * always English (it comes from `LanguageCatalog`, the validation source of
 * truth), so the label is re-derived from the ISO code in the active UI locale
 * — no per-language catalog entries to maintain on our side.
 *
 * `Intl.DisplayNames` returns the code itself for anything it can't name, which
 * is exactly when the server's English label is the better answer.
 */
export function languageLabel(code, fallback = null) {
  if (!code) return fallback ?? ''
  try {
    const named = new Intl.DisplayNames([currentLocale()], { type: 'language' }).of(code)
    if (named && named.toLowerCase() !== String(code).toLowerCase()) return named
  } catch {
    /* Unsupported locale/code — fall through to the server's label. */
  }
  return fallback ?? code
}
