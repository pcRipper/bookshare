<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useLibraryStore } from '@/stores/library'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

/**
 * "Find a template" panel for the Add New Book modal. A full-width search over
 * title/ISBN, a source toggle (this site vs. external catalogues), and a list of
 * large, easy-to-scan result cards. Picking one emits `select` with the template
 * so the parent can pre-fill the manual form.
 */
const emit = defineEmits(['select'])

const store = useLibraryStore()

// Per-source debounce. External (Open Library) is rate-limited upstream, so it
// waits longer after the last keystroke than the local catalogue search.
const DEBOUNCE_MS = { site: 250, external: 500 }

// The two search strategies the backend exposes, with the copy the brief asks for.
const SOURCES = [
  { key: 'site',     label: 'On this site',    hint: 'Search existing templates on the site' },
  { key: 'external', label: 'External sources', hint: 'Search Open Library' },
]

const query = ref('')
const source = ref('site')
const results = ref([])
const searching = ref(false)
const error = ref(null)

let debounceTimer = null
let searchSeq = 0 // guards against out-of-order responses
let inFlight = null // AbortController of the current request, if any

const trimmedQuery = computed(() => query.value.trim())
const activeSource = computed(() => SOURCES.find(s => s.key === source.value))

// Re-run whenever the query or the chosen source changes. Each change cancels
// the pending timer *and* aborts any request already in flight, so a fast typer
// never leaves a stale external call racing against the newest input.
watch([query, source], () => {
  error.value = null
  clearTimeout(debounceTimer)
  debounceTimer = null
  inFlight?.abort()
  inFlight = null
  if (trimmedQuery.value === '') {
    results.value = []
    searching.value = false
    return
  }
  searching.value = true
  debounceTimer = setTimeout(() => { debounceTimer = null; runSearch() }, DEBOUNCE_MS[source.value] ?? 250)
})

onBeforeUnmount(() => { clearTimeout(debounceTimer); inFlight?.abort() })
onMounted(() => searchInput.value?.focus())

const searchInput = ref(null)

async function runSearch() {
  const q = trimmedQuery.value
  if (q === '') return
  const seq = ++searchSeq
  const controller = new AbortController()
  inFlight = controller
  try {
    const data = await store.searchBookTemplates(q, source.value, controller.signal)
    if (seq !== searchSeq) return // a newer search superseded this one
    results.value = data
  } catch (e) {
    if (e.code === 'ERR_CANCELED') return // aborted by a newer search — ignore
    if (seq === searchSeq) {
      results.value = []
      error.value = 'Could not search for templates. Try again.'
    }
  } finally {
    if (inFlight === controller) inFlight = null
    if (seq === searchSeq) searching.value = false
  }
}

// The "nothing found" copy depends on the source — an empty external result is
// expected (no integration yet), not a dead end.
const emptyMessage = computed(() =>
  source.value === 'external'
    ? 'No matching books found in Open Library. Try a different title or ISBN.'
    : 'No matching books found. Try a different title or ISBN.',
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

    <!-- Source toggle -->
    <div class="tpl__sources" role="radiogroup" aria-label="Search source">
      <button
        v-for="s in SOURCES"
        :key="s.key"
        type="button"
        class="tpl__source"
        :class="{ 'tpl__source--active': source === s.key }"
        role="radio"
        :aria-checked="source === s.key"
        @click="source = s.key"
      >
        <span class="tpl__source-label">{{ s.label }}</span>
        <span class="tpl__source-hint">{{ s.hint }}</span>
      </button>
    </div>

    <!-- Results -->
    <div class="tpl__body">
      <p v-if="error" class="tpl__msg tpl__msg--error">{{ error }}</p>

      <p v-else-if="trimmedQuery === ''" class="tpl__msg">
        Search {{ activeSource.label.toLowerCase() }} to fill a new book from an existing one.
      </p>

      <p v-else-if="showEmpty" class="tpl__msg">{{ emptyMessage }}</p>

      <ul v-else-if="results.length" class="tpl__list">
        <li v-for="(t, i) in results" :key="`${t.title}-${t.author}-${i}`">
          <button type="button" class="tpl__option" @click="emit('select', t)">
            <span class="tpl__cover">
              <img v-if="t.coverPath" :src="t.coverPath" :alt="`Cover of ${t.title}`" />
              <span v-else class="material-symbols-outlined tpl__cover-icon">menu_book</span>
            </span>
            <span class="tpl__meta">
              <span class="tpl__title">{{ t.title }}</span>
              <span class="tpl__author">{{ t.author }}</span>
              <span class="tpl__tags">
                <span v-if="t.languageName" class="tpl__tag">{{ t.languageName }}</span>
                <span v-if="t.isbn" class="tpl__isbn">ISBN {{ t.isbn }}</span>
              </span>
            </span>
            <span class="material-symbols-outlined tpl__pick">arrow_forward</span>
          </button>
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

/* Source toggle */
.tpl__sources { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm); }
.tpl__source {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--space-sm) var(--space-base);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  text-align: left;
  transition: border-color 0.2s, background 0.2s;
}
.tpl__source--active {
  border-color: var(--color-primary);
  background: var(--color-surface-container-low);
}
.tpl__source-label { font-size: var(--text-body-md); font-weight: 600; color: var(--color-on-background); }
.tpl__source--active .tpl__source-label { color: var(--color-primary); }
.tpl__source-hint { font-size: var(--text-label-sm); color: var(--color-secondary); }

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
.tpl__tags { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-xs); margin-top: 2px; }
.tpl__tag {
  padding: 1px 8px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-high);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.tpl__isbn { font-size: var(--text-label-sm); color: var(--color-secondary); }
.tpl__pick { flex-shrink: 0; color: var(--color-secondary); }
.tpl__option:hover .tpl__pick { color: var(--color-primary); }
</style>
