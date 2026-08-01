import { ref, watch } from 'vue'

/**
 * App-wide preferences for how book lists render, shared by the Library,
 * Profile and Discover book lists and persisted to localStorage so the app
 * reopens the way it was left:
 *
 *  - `bookView`      — 'cards' (the cover-heavy grid) or 'table' (compact list)
 *  - `tableDetailed` — in table mode, show the full record (categories,
 *                      description, ISBN, holder, added date) instead of only
 *                      the essential columns
 *
 * Module-level refs ⇒ singletons: every caller reads/writes the same values.
 */
const VIEW_KEY = 'bookView'
const DETAILED_KEY = 'bookTableDetailed'

const bookView = ref(localStorage.getItem(VIEW_KEY) === 'table' ? 'table' : 'cards')
const tableDetailed = ref(localStorage.getItem(DETAILED_KEY) === '1')

watch(bookView, v => localStorage.setItem(VIEW_KEY, v))
watch(tableDetailed, v => localStorage.setItem(DETAILED_KEY, v ? '1' : '0'))

export function useBookView() {
  function setBookView(v) {
    bookView.value = v === 'table' ? 'table' : 'cards'
  }

  function setTableDetailed(v) {
    tableDetailed.value = !!v
  }

  return { bookView, tableDetailed, setBookView, setTableDetailed }
}
