<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useLibraryStore } from '@/stores/library'
import { CATEGORY_PALETTE, resolveCategoryColors } from '@/utils/categoryColors'

/**
 * "Search or create" category picker used in the Manage Book modal.
 * - Selected categories render as removable colour chips (v-model: an array of
 *   {id, name, colorHex} objects).
 * - Typing searches existing categories (debounced, race-safe).
 * - When the search returns nothing for a non-empty query, a "Create New
 *   Category" panel appears: pick a colour, hit create — the category is
 *   persisted and immediately added to the selection.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] }, // [{ id, name, colorHex }]
  // Read-only: render the selected chips but hide search/create/remove controls.
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const store = useLibraryStore()

const MAX_NAME = 50
const DEBOUNCE_MS = 250

const query = ref('')
const results = ref([])
const searching = ref(false)
const creating = ref(false)
const error = ref(null)
const selectedColor = ref(CATEGORY_PALETTE[0].bg)
const highlight = ref(0) // index of the keyboard-highlighted result

let debounceTimer = null
let searchSeq = 0 // guards against out-of-order responses

const trimmedQuery = computed(() => query.value.trim())
const selectedIds = computed(() => new Set(props.modelValue.map(c => c.id)))

// Matches not already attached to the book.
const availableResults = computed(() =>
  results.value.filter(c => !selectedIds.value.has(c.id)),
)

// Keep the highlight within bounds as results change.
const activeIndex = computed(() => {
  const n = availableResults.value.length
  if (n === 0) return -1
  return Math.min(highlight.value, n - 1)
})

// Does an existing category (selected or not) already carry this exact name?
const nameTaken = computed(() => {
  const q = trimmedQuery.value.toLowerCase()
  return results.value.some(c => c.name.toLowerCase() === q)
})

// Offer creation only when the typed name matched nothing that exists yet.
const canCreate = computed(() =>
  trimmedQuery.value !== '' &&
  trimmedQuery.value.length <= MAX_NAME &&
  !searching.value &&
  !nameTaken.value &&
  availableResults.value.length === 0,
)

const tooLong = computed(() => trimmedQuery.value.length > MAX_NAME)

watch(query, () => {
  error.value = null
  highlight.value = 0
  clearTimeout(debounceTimer)
  debounceTimer = null
  const q = trimmedQuery.value
  if (q === '') {
    results.value = []
    searching.value = false
    return
  }
  searching.value = true
  debounceTimer = setTimeout(() => { debounceTimer = null; runSearch() }, DEBOUNCE_MS)
})

onBeforeUnmount(() => clearTimeout(debounceTimer))

// Resolve any pending/in-flight search immediately so callers (Enter) act on
// results that reflect what's currently typed — never a stale empty list.
async function flushSearch() {
  if (debounceTimer || searching.value) {
    clearTimeout(debounceTimer)
    debounceTimer = null
    await runSearch()
  }
}

async function runSearch() {
  const q = trimmedQuery.value
  if (q === '') return
  const seq = ++searchSeq
  try {
    const data = await store.searchCategories(q)
    if (seq !== searchSeq) return // a newer search superseded this one
    results.value = data
  } catch {
    if (seq === searchSeq) {
      results.value = []
      error.value = 'Could not search categories. Try again.'
    }
  } finally {
    if (seq === searchSeq) searching.value = false
  }
}

function addCategory(category) {
  if (!category || selectedIds.value.has(category.id)) return
  emit('update:modelValue', [...props.modelValue, category])
  reset()
}

function removeCategory(id) {
  emit('update:modelValue', props.modelValue.filter(c => c.id !== id))
}

async function createCategory() {
  if (!canCreate.value || creating.value) return
  creating.value = true
  error.value = null
  try {
    const created = await store.createCategory({
      name: trimmedQuery.value,
      colorHex: selectedColor.value,
    })
    addCategory(created)
  } catch (e) {
    if (e.response?.status === 409) {
      // Someone created it meanwhile — resurface it so the user can pick it.
      error.value = 'That category already exists — pick it from the list.'
      runSearch()
    } else {
      error.value = e.response?.data?.error ?? 'Could not create the category.'
    }
  } finally {
    creating.value = false
  }
}

function reset() {
  query.value = ''
  results.value = []
  error.value = null
  highlight.value = 0
  selectedColor.value = CATEGORY_PALETTE[0].bg
}

// Move the keyboard highlight through the results list.
function move(delta) {
  const n = availableResults.value.length
  if (n === 0) return
  highlight.value = (activeIndex.value + delta + n) % n
}

// Enter: first make sure results reflect what's typed (a fast Enter can land
// before the debounced search runs), then pick the highlighted match — or
// create a new category when nothing matched.
async function onEnter() {
  await flushSearch()
  if (activeIndex.value >= 0) {
    addCategory(availableResults.value[activeIndex.value])
  } else if (canCreate.value) {
    createCategory()
  }
}

function chipStyle(colorHex) {
  const c = resolveCategoryColors(colorHex)
  return { background: c.bg, color: c.text, borderColor: c.border }
}
</script>

<template>
  <div class="cat">
    <!-- Selected chips -->
    <div v-if="modelValue.length" class="cat__chips">
      <span
        v-for="cat in modelValue"
        :key="cat.id"
        class="cat__chip"
        :style="chipStyle(cat.colorHex)"
      >
        {{ cat.name }}
        <button
          v-if="!disabled"
          type="button"
          class="cat__chip-remove"
          :aria-label="`Remove ${cat.name}`"
          @click="removeCategory(cat.id)"
        >
          <span class="material-symbols-outlined">close</span>
        </button>
      </span>
    </div>
    <p v-if="disabled && !modelValue.length" class="cat__hint">No categories.</p>

    <!-- Search -->
    <template v-if="!disabled">
    <div class="cat__search">
      <span class="material-symbols-outlined cat__search-icon">search</span>
      <input
        v-model="query"
        class="cat__search-input"
        type="text"
        :maxlength="MAX_NAME + 10"
        placeholder="Search or create category…"
        aria-label="Search or create category"
        @keydown.enter.prevent="onEnter"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
      />
      <span v-if="searching" class="cat__search-status">…</span>
    </div>

    <!-- Results -->
    <ul v-if="availableResults.length" class="cat__results">
      <li v-for="(cat, i) in availableResults" :key="cat.id">
        <button
          type="button"
          class="cat__result"
          :class="{ 'cat__result--active': i === activeIndex }"
          @click="addCategory(cat)"
          @mouseenter="highlight = i"
        >
          <span class="cat__dot" :style="{ background: resolveCategoryColors(cat.colorHex).bg, borderColor: resolveCategoryColors(cat.colorHex).border }" />
          <span class="cat__result-name">{{ cat.name }}</span>
          <span class="material-symbols-outlined cat__result-add">add</span>
        </button>
      </li>
    </ul>

    <!-- Create panel (no existing match) -->
    <div v-else-if="canCreate" class="cat__create">
      <div class="cat__create-info">
        <span class="cat__create-label">Create new category</span>
        <span class="cat__create-name">“{{ trimmedQuery }}”</span>
        <div class="cat__swatches" role="radiogroup" aria-label="Category colour">
          <button
            v-for="swatch in CATEGORY_PALETTE"
            :key="swatch.bg"
            type="button"
            class="cat__swatch"
            :class="{ 'cat__swatch--active': selectedColor === swatch.bg }"
            :style="{ background: swatch.bg, borderColor: swatch.border }"
            :aria-label="swatch.label"
            :aria-pressed="selectedColor === swatch.bg"
            @click="selectedColor = swatch.bg"
          />
        </div>
      </div>
      <button
        type="button"
        class="cat__create-btn"
        :disabled="creating"
        aria-label="Create category"
        @click="createCategory"
      >
        <span class="material-symbols-outlined">{{ creating ? 'hourglass_empty' : 'add' }}</span>
      </button>
    </div>

    <!-- Hints / errors -->
    <p v-if="tooLong" class="cat__hint cat__hint--error">
      Category names must be {{ MAX_NAME }} characters or fewer.
    </p>
    <p v-else-if="error" class="cat__hint cat__hint--error">{{ error }}</p>
    <p
      v-else-if="trimmedQuery && !searching && !availableResults.length && !canCreate"
      class="cat__hint"
    >
      Already added.
    </p>
    </template>
  </div>
</template>

<style scoped>
.cat { display: flex; flex-direction: column; gap: var(--space-sm); }

/* Chips */
.cat__chips { display: flex; flex-wrap: wrap; gap: var(--space-xs); }
.cat__chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 4px 2px 10px;
  border: 1px solid transparent;
  border-radius: var(--radius-full);
  font-size: var(--text-label-sm);
  font-weight: 600;
}
.cat__chip-remove { display: flex; color: inherit; opacity: 0.7; }
.cat__chip-remove:hover { opacity: 1; }
.cat__chip-remove .material-symbols-outlined { font-size: 14px; }

/* Search */
.cat__search { position: relative; display: flex; align-items: center; }
.cat__search-icon {
  position: absolute;
  left: 8px;
  font-size: 18px;
  color: var(--color-secondary);
  pointer-events: none;
}
.cat__search-input {
  width: 100%;
  padding: 10px 12px 10px 32px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-low);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  transition: border-color 0.2s;
}
.cat__search-input:focus { outline: none; border-color: var(--color-primary); }
.cat__search-status {
  position: absolute;
  right: 12px;
  color: var(--color-secondary);
}

/* Results */
.cat__results {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  padding: var(--space-xs);
  list-style: none;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  max-height: 180px;
  overflow-y: auto;
}
.cat__result {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  width: 100%;
  padding: 8px;
  border-radius: var(--radius-default);
  text-align: left;
  transition: background 0.15s;
}
.cat__result:hover,
.cat__result--active { background: var(--color-surface-container-high); }
.cat__dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 1px solid transparent;
  flex-shrink: 0;
}
.cat__result-name { flex-grow: 1; font-size: var(--text-body-md); color: var(--color-on-background); }
.cat__result-add { font-size: 18px; color: var(--color-secondary); }

/* Create panel */
.cat__create {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-sm);
  border: 1px dashed var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-low);
}
.cat__create-info { display: flex; flex-direction: column; gap: var(--space-xs); min-width: 0; }
.cat__create-label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  text-transform: uppercase;
  font-weight: 600;
  color: var(--color-secondary);
}
.cat__create-name {
  font-size: var(--text-label-md);
  color: var(--color-on-background);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cat__swatches { display: flex; gap: var(--space-xs); }
.cat__swatch {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid transparent;
  transition: box-shadow 0.15s;
}
.cat__swatch--active { box-shadow: 0 0 0 2px var(--color-primary); }
.cat__create-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-primary);
  color: var(--color-on-primary);
  transition: background 0.2s;
}
.cat__create-btn:hover:not(:disabled) { background: var(--color-primary-container); }
.cat__create-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.cat__create-btn .material-symbols-outlined { font-size: 20px; }

/* Hints */
.cat__hint { margin: 0; font-size: var(--text-label-sm); color: var(--color-secondary); }
.cat__hint--error { color: var(--color-error); }
</style>
