import { ref, watch } from 'vue'

/**
 * A single, app-wide preference for how book lists render — 'cards' (the
 * cover-heavy grid) or 'table' (the compact, scannable list). Shared by the
 * Library, Profile and Discover book lists and persisted to localStorage so the
 * app reopens in the last-used view.
 *
 * Module-level ref ⇒ a singleton: every caller reads/writes the same value.
 */
const STORAGE_KEY = 'bookView'

const stored = localStorage.getItem(STORAGE_KEY)
const bookView = ref(stored === 'table' ? 'table' : 'cards')

watch(bookView, v => localStorage.setItem(STORAGE_KEY, v))

export function useBookView() {
  function setBookView(v) {
    bookView.value = v === 'table' ? 'table' : 'cards'
  }

  return { bookView, setBookView }
}
