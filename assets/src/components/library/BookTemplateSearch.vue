<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useLibraryStore } from '@/stores/library'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'

/**
 * "Find a template" panel for the Add New Book modal. A full-width search over
 * title/ISBN, a source dropdown (this site vs. external catalogues), and a list
 * of large, easy-to-scan result cards. Picking one emits `select` with the
 * template so the parent can pre-fill the manual form.
 */
const emit = defineEmits(['select'])

const store = useLibraryStore()

// Per-source debounce. External catalogues are network round-trips, so they wait
// longer after the last keystroke than the local catalogue search.
const DEBOUNCE_MS = { site: 250, external: 500, bookfinder: 400 }

// The search strategies the backend exposes (source keys mirror the API), with
// the copy the brief asks for. The local catalogue is the default.
const SOURCES = [
  { key: 'site',       label: 'On this site',     hint: 'Search existing templates on the site' },
  { key: 'external',   label: 'Open Library',     hint: 'Search Open Library' },
  { key: 'bookfinder', label: 'Ukrainian stores', hint: 'Search bookfinder.com.ua' },
]
const sourceOptions = SOURCES.map(s => ({ value: s.key, label: s.label }))

const query = ref('')
const source = ref('site')
const results = ref([])        // accumulated across pages
const hasMore = ref(false)
const searching = ref(false)   // initial page load
const loadingMore = ref(false) // fetching a subsequent page
const error = ref(null)

const searchInput = ref(null)
const listEl = ref(null)       // scroll container (IntersectionObserver root)
const sentinel = ref(null)     // bottom marker; visible ⇒ load the next page

let debounceTimer = null
let searchSeq = 0 // guards against out-of-order responses / superseded searches
let inFlight = null // AbortController of the current request, if any
let page = 1
const seen = new Set() // keys of rendered templates, to drop cross-page repeats
let observer = null

const trimmedQuery = computed(() => query.value.trim())
const activeSource = computed(() => SOURCES.find(s => s.key === source.value))

// A stable identity for a template, matching the backend dedupeKey fields, so we
// only drop *exact* repeats across pages (distinct editions still show).
function keyOf(t) {
  return [t.title, t.author, t.isbn, t.language, t.coverPath]
    .map(v => (v ?? '').toString().trim().toLowerCase())
    .join('|')
}

function reset() {
  results.value = []
  hasMore.value = false
  page = 1
  seen.clear()
}

function appendItems(items) {
  for (const t of items) {
    const k = keyOf(t)
    if (seen.has(k)) continue
    seen.add(k)
    results.value.push(t)
  }
}

// Re-run whenever the query or the chosen source changes. Each change cancels the
// pending timer *and* aborts any request already in flight (initial or load-more),
// so a fast typer never leaves a stale external call racing against the newest input.
watch([query, source], () => {
  error.value = null
  clearTimeout(debounceTimer)
  debounceTimer = null
  inFlight?.abort()
  inFlight = null
  if (trimmedQuery.value === '') {
    reset()
    searching.value = false
    return
  }
  searching.value = true
  debounceTimer = setTimeout(() => { debounceTimer = null; runSearch() }, DEBOUNCE_MS[source.value] ?? 250)
})

async function runSearch() {
  const q = trimmedQuery.value
  if (q === '') return
  reset()
  const seq = ++searchSeq
  const controller = new AbortController()
  inFlight = controller
  try {
    const { items, hasMore: more } = await store.searchBookTemplates(q, source.value, 1, controller.signal)
    if (seq !== searchSeq) return // a newer search superseded this one
    appendItems(items)
    hasMore.value = !!more
  } catch (e) {
    if (e.code === 'ERR_CANCELED') return // aborted by a newer search — ignore
    if (seq === searchSeq) {
      reset()
      error.value = 'Could not search for templates. Try again.'
    }
  } finally {
    if (inFlight === controller) inFlight = null
    if (seq === searchSeq) searching.value = false
  }
}

async function loadMore() {
  if (!hasMore.value || loadingMore.value || searching.value) return
  const q = trimmedQuery.value
  if (q === '') return
  const seq = searchSeq // don't bump — a new search bumps and supersedes this
  const controller = new AbortController()
  inFlight = controller
  loadingMore.value = true
  try {
    const { items, hasMore: more } = await store.searchBookTemplates(q, source.value, page + 1, controller.signal)
    if (seq !== searchSeq) return
    page += 1
    appendItems(items)
    hasMore.value = !!more
  } catch (e) {
    if (e.code === 'ERR_CANCELED') return
    if (seq === searchSeq) hasMore.value = false // stop scrolling on error
  } finally {
    if (inFlight === controller) inFlight = null
    if (seq === searchSeq) loadingMore.value = false
  }
}

// (Re)wire the observer to the sentinel as it mounts/unmounts. The scroll list is
// the root, so it fires on the list's own inner scroll, not the page's.
watch(sentinel, el => {
  observer?.disconnect()
  if (!el) return
  observer = new IntersectionObserver(
    entries => { if (entries[0].isIntersecting) loadMore() },
    { root: listEl.value, rootMargin: '120px' },
  )
  observer.observe(el)
}, { flush: 'post' })

onBeforeUnmount(() => { clearTimeout(debounceTimer); inFlight?.abort(); observer?.disconnect() })
onMounted(() => searchInput.value?.focus())

// The "nothing found" copy names the active source so an empty result reads as
// "nothing here" rather than a dead end.
const emptyMessage = computed(() =>
  `No matching books found in ${activeSource.value.label}. Try a different title or ISBN.`,
)

const showEmpty = computed(() =>
  trimmedQuery.value !== '' && !searching.value && !error.value && results.value.length === 0,
)
</script>

<template>
  <div class="tpl">
    <!-- Search box -->
    <div class="tpl__search">
      <span class="material-symbols-outlined tpl__search-icon">search</span>
      <input
        ref="searchInput"
        v-model="query"
        class="tpl__search-input"
        type="text"
        placeholder="Search by title or ISBN…"
        aria-label="Search by title or ISBN"
      />
      <BaseSpinner v-if="searching" size="sm" class="tpl__search-spinner" />
    </div>

    <!-- Source picker -->
    <div class="tpl__sources">
      <label class="tpl__source-label" for="tpl-source">Search source</label>
      <BaseSelect id="tpl-source" v-model="source" :options="sourceOptions" />
      <p class="tpl__source-hint">{{ activeSource.hint }}</p>
    </div>

    <!-- Results -->
    <div class="tpl__body">
      <p v-if="error" class="tpl__msg tpl__msg--error">{{ error }}</p>

      <p v-else-if="trimmedQuery === ''" class="tpl__msg">
        Search {{ activeSource.label.toLowerCase() }} to fill a new book from an existing one.
      </p>

      <p v-else-if="showEmpty" class="tpl__msg">{{ emptyMessage }}</p>

      <ul v-else-if="results.length" ref="listEl" class="tpl__list">
        <li v-for="(t, i) in results" :key="`${keyOf(t)}-${i}`">
          <button type="button" class="tpl__option" @click="emit('select', t)">
            <span class="tpl__cover">
              <img v-if="t.coverPath" :src="t.coverPath" :alt="`Cover of ${t.title}`" />
              <span v-else class="material-symbols-outlined tpl__cover-icon">menu_book</span>
            </span>
            <span class="tpl__meta">
              <span class="tpl__title">{{ t.title }}</span>
              <span class="tpl__author">{{ t.author }}</span>
              <span v-if="t.description" class="tpl__desc">{{ t.description }}</span>
              <span class="tpl__tags">
                <span v-if="t.languageName" class="tpl__tag">{{ t.languageName }}</span>
                <span v-if="t.isbn" class="tpl__isbn">ISBN {{ t.isbn }}</span>
              </span>
            </span>
            <span class="material-symbols-outlined tpl__pick">arrow_forward</span>
          </button>
        </li>
        <!-- Sentinel: when it scrolls into view the next page loads. -->
        <li v-if="hasMore || loadingMore" ref="sentinel" class="tpl__sentinel">
          <BaseSpinner v-if="loadingMore" size="sm" />
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.tpl { display: flex; flex-direction: column; gap: var(--space-md); }

/* Search box */
.tpl__search { position: relative; display: flex; align-items: center; }
.tpl__search-icon {
  position: absolute;
  left: 12px;
  font-size: 20px;
  color: var(--color-secondary);
  pointer-events: none;
}
.tpl__search-input {
  width: 100%;
  padding: 12px 40px 12px 40px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  transition: border-color 0.2s;
}
.tpl__search-input:focus { outline: none; border-color: var(--color-primary); }
.tpl__search-spinner { position: absolute; right: 12px; }

/* Source picker */
.tpl__sources { display: flex; flex-direction: column; gap: var(--space-xs); }
.tpl__source-label { font-size: var(--text-label-sm); font-weight: 600; color: var(--color-secondary); }
.tpl__source-hint { margin: 0; font-size: var(--text-label-sm); color: var(--color-secondary); }

/* Results */
.tpl__body { min-height: 120px; }
.tpl__msg {
  margin: 0;
  padding: var(--space-md) var(--space-base);
  font-size: var(--text-body-md);
  color: var(--color-secondary);
  text-align: center;
}
.tpl__msg--error { color: var(--color-error); }

.tpl__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  max-height: 360px;
  overflow-y: auto;
}
.tpl__option {
  display: flex;
  align-items: center;
  gap: var(--space-base);
  width: 100%;
  padding: var(--space-sm);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  text-align: left;
  transition: border-color 0.15s, background 0.15s;
}
.tpl__option:hover {
  border-color: var(--color-primary);
  background: var(--color-surface-container-low);
}
.tpl__cover {
  width: 48px;
  height: 68px;
  flex-shrink: 0;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  overflow: hidden;
  background: var(--color-surface-container-low);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-outline);
}
.tpl__cover img { width: 100%; height: 100%; object-fit: cover; }
.tpl__cover-icon { font-size: 24px; }
.tpl__meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex-grow: 1; }
.tpl__title {
  font-family: var(--font-display);
  font-size: var(--text-body-lg);
  color: var(--color-on-background);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tpl__author {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tpl__desc {
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  line-height: 1.4;
  margin-top: 2px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.tpl__tags { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-xs); margin-top: 2px; }
.tpl__tag {
  padding: 1px 8px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-high);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.tpl__isbn { font-size: var(--text-label-sm); color: var(--color-secondary); }
.tpl__sentinel { display: flex; align-items: center; justify-content: center; min-height: 32px; }
.tpl__pick { flex-shrink: 0; color: var(--color-secondary); }
.tpl__option:hover .tpl__pick { color: var(--color-primary); }
</style>
