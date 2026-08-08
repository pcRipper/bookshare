import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import de from './locales/de.json'
import es from './locales/es.json'
import fr from './locales/fr.json'
import uk from './locales/uk.json'

/**
 * UI localization. The catalogs are bundled (not fetched) — they're small and a
 * language switch must be instant, with no loading state mid-page.
 *
 * `SUPPORTED` is one of the two halves of the locale allow-list: the backend
 * mirrors it in `App\I18n\LocaleCatalog` (which validates the persisted value).
 * Adding a language means touching both, plus a new file under `locales/`.
 * Labels are deliberately written in their own language — that's what a reader
 * who can't read the current one needs to recognise.
 */
export const SUPPORTED = [
  { code: 'en', label: 'English' },
  { code: 'de', label: 'Deutsch' },
  { code: 'es', label: 'Español' },
  { code: 'fr', label: 'Français' },
  { code: 'uk', label: 'Українська' },
]

export const DEFAULT_LOCALE = 'en'

const STORAGE_KEY = 'locale'
const PENDING_KEY = 'pendingLocale'

function isSupported(code) {
  return SUPPORTED.some(l => l.code === code)
}

/**
 * Resolves a locale tag (`uk`, `uk-UA`, `de-AT`) to a supported code, so a
 * browser preference like `de-CH` still lands on German.
 */
export function negotiate(tag) {
  if (!tag) return null
  const code = String(tag).toLowerCase().split('-')[0]
  return isSupported(code) ? code : null
}

function initialLocale() {
  const stored = negotiate(localStorage.getItem(STORAGE_KEY))
  if (stored) return stored
  for (const tag of navigator.languages ?? [navigator.language]) {
    const match = negotiate(tag)
    if (match) return match
  }
  return DEFAULT_LOCALE
}

/**
 * Slavic plural forms — Ukrainian needs three (1 книга / 2 книги / 5 книг),
 * where vue-i18n's built-in rule only offers two. The message lists the forms
 * in `one | few | many` order.
 */
function ukrainianPluralRule(choice, choicesLength) {
  if (choicesLength < 3) return choice === 1 ? 0 : 1

  const mod10 = choice % 10
  const mod100 = choice % 100

  if (mod10 === 1 && mod100 !== 11) return 0
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 1
  return 2
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: initialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en, de, es, fr, uk },
  pluralRules: { uk: ukrainianPluralRule },
  // The catalogs are filled per area, so a key that hasn't been translated yet
  // must fall through to English silently rather than spam the console.
  missingWarn: false,
  fallbackWarn: false,
})

/**
 * The single entry point for changing language. Besides the i18n instance it
 * updates `<html lang>` (screen readers, hyphenation) and persists the choice —
 * `localStorage` is also what the axios interceptor reads for `Accept-Language`,
 * so the API's error messages follow the UI without extra plumbing.
 */
export function setLocale(code) {
  const next = negotiate(code) ?? DEFAULT_LOCALE
  i18n.global.locale.value = next
  document.documentElement.setAttribute('lang', next)
  localStorage.setItem(STORAGE_KEY, next)
  return next
}

/*
 * A language picked while signed out (login page, public share page) can't be
 * saved to the account — there's no token yet. It's parked here so the Google
 * callback can commit it the moment one exists, which is what makes the choice
 * made on the login screen stick to the account rather than to this browser.
 *
 * `sessionStorage`, not `localStorage`: the intent belongs to this sign-in
 * attempt. A stale flag from days ago must not silently rewrite the account's
 * language on the next login.
 */
export function markPendingLocale(code) {
  sessionStorage.setItem(PENDING_KEY, code)
}

/** Reads and consumes the pending choice — null when there wasn't one. */
export function takePendingLocale() {
  const code = negotiate(sessionStorage.getItem(PENDING_KEY))
  sessionStorage.removeItem(PENDING_KEY)
  return code
}

/** The active locale, for non-component code (`utils/time.js`, `utils/languages.js`). */
export function currentLocale() {
  return i18n.global.locale.value
}

/** `t()` outside a component — the same catalog, no `useI18n()` context needed. */
export function t(...args) {
  return i18n.global.t(...args)
}

// Keep the document and storage consistent with the negotiated initial value.
setLocale(i18n.global.locale.value)

export default i18n
