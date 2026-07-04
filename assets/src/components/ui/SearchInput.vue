<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

/**
 * Minimal reusable text-search box (search icon + native type="search"), owning
 * its own debounce. Emits `search` with the trimmed value after the user pauses
 * typing (and immediately when cleared). While a search is pending (debounce) or
 * the parent reports `loading`, it shows a spinner on the right — matching the
 * "Find a template" search box; otherwise a clear button once there's text. Used
 * by the library and profile book lists.
 */
const props = defineProps({
  placeholder: { type: String, default: 'Search…' },
  debounce: { type: Number, default: 300 },
  // Parent-controlled: true while the search request is in flight.
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['search'])

const value = ref('')
const pending = ref(false) // between a keystroke and its debounced emit
let timer = null

watch(value, v => {
  const trimmed = v.trim()
  clearTimeout(timer)
  // Emit immediately when the box is cleared; debounce otherwise.
  if (trimmed === '') {
    pending.value = false
    emit('search', '')
    return
  }
  pending.value = true
  timer = setTimeout(() => {
    emit('search', trimmed)
    pending.value = false
  }, props.debounce)
})

// Spinner while typing settles or the fetch runs; never on an empty box (so
// initial/paginated loads of an unsearched list don't spin the search field).
const showSpinner = computed(() => pending.value || (props.loading && value.value !== ''))

function clear() {
  value.value = ''
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="search-input" role="search">
    <span class="material-symbols-outlined search-input__icon">search</span>
    <input
      v-model="value"
      class="search-input__field"
      type="search"
      :placeholder="placeholder"
      aria-label="Search books"
    />
    <BaseSpinner v-if="showSpinner" size="sm" class="search-input__spinner" />
    <button
      v-else-if="value"
      class="search-input__clear"
      type="button"
      aria-label="Clear search"
      @click="clear"
    >
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
</template>

<style scoped>
.search-input {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}
.search-input__icon {
  position: absolute;
  left: 12px;
  font-size: 20px;
  color: var(--color-on-surface-variant);
  pointer-events: none;
}
.search-input__field {
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
.search-input__field::placeholder { color: var(--color-on-surface-variant); }
.search-input__field:focus {
  outline: none;
  border-color: var(--color-primary);
}
/* Hide the browser's native clear affordance in favour of our own button. */
.search-input__field::-webkit-search-cancel-button { display: none; }

.search-input__spinner { position: absolute; right: 12px; }

.search-input__clear {
  position: absolute;
  right: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-full);
  color: var(--color-on-surface-variant);
  transition: background 0.2s, color 0.2s;
}
.search-input__clear:hover {
  background: var(--color-surface-container-high);
  color: var(--color-on-background);
}
.search-input__clear .material-symbols-outlined { font-size: 18px; }
</style>
