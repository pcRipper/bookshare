<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { loadLanguages } from '@/utils/languages'

/**
 * Searchable language picker (combobox). The trigger shows the current
 * selection; opening it reveals a type-to-filter search over the full
 * vocabulary with keyboard navigation. `v-model` is the ISO code (string) or
 * null when nothing is selected.
 */
const props = defineProps({
  modelValue: { type: String, default: null }, // ISO 639-1 code | null
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Select language' },
  // Label for the "no selection" row at the top of the list.
  anyLabel: { type: String, default: 'Any language' },
  id: { type: String, default: undefined },
})
const emit = defineEmits(['update:modelValue'])

const languages = ref([])
const open = ref(false)
const query = ref('')
const highlight = ref(0)
const root = ref(null)
const searchInput = ref(null)

onMounted(async () => {
  try {
    languages.value = await loadLanguages()
  } catch {
    /* Non-fatal: the control still renders the current code as a fallback. */
  }
})

const selectedName = computed(() => {
  if (!props.modelValue) return null
  const found = languages.value.find(l => l.code === props.modelValue)
  return found ? found.name : props.modelValue
})

// "Any" pseudo-row + the filtered vocabulary; index 0 is always "Any".
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const matches = q
    ? languages.value.filter(l => l.name.toLowerCase().includes(q) || l.code.includes(q))
    : languages.value
  return [{ code: null, name: props.anyLabel }, ...matches]
})

watch(query, () => { highlight.value = 0 })

watch(open, async v => {
  if (v) {
    query.value = ''
    highlight.value = 0
    await nextTick()
    searchInput.value?.focus()
  }
})

function toggle() {
  if (!props.disabled) open.value = !open.value
}

function select(code) {
  emit('update:modelValue', code)
  open.value = false
}

function move(delta) {
  const n = filtered.value.length
  if (n) highlight.value = (highlight.value + delta + n) % n
}

function onEnter() {
  const item = filtered.value[highlight.value]
  if (item) select(item.code)
}

function onClickOutside(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('mousedown', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
  <div ref="root" class="lang">
    <button
      :id="id"
      type="button"
      class="lang__trigger"
      :class="{ 'lang__trigger--placeholder': !selectedName }"
      :disabled="disabled"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="toggle"
    >
      <span class="lang__trigger-text">{{ selectedName ?? placeholder }}</span>
      <span class="material-symbols-outlined lang__caret">{{ open ? 'expand_less' : 'expand_more' }}</span>
    </button>

    <div v-if="open" class="lang__popover">
      <div class="lang__search">
        <span class="material-symbols-outlined lang__search-icon">search</span>
        <input
          ref="searchInput"
          v-model="query"
          class="lang__search-input"
          type="text"
          placeholder="Search languages…"
          aria-label="Search languages"
          @keydown.enter.prevent="onEnter"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.esc.prevent="open = false"
        />
      </div>

      <ul class="lang__list" role="listbox">
        <li v-for="(lang, i) in filtered" :key="lang.code ?? '__any'">
          <button
            type="button"
            class="lang__option"
            :class="{
              'lang__option--active': i === highlight,
              'lang__option--selected': lang.code === modelValue,
              'lang__option--any': lang.code === null,
            }"
            @click="select(lang.code)"
            @mouseenter="highlight = i"
          >
            <span class="lang__option-name">{{ lang.name }}</span>
            <span v-if="lang.code === modelValue" class="material-symbols-outlined lang__check">check</span>
          </button>
        </li>
        <li v-if="filtered.length === 1" class="lang__empty">No languages match.</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.lang { position: relative; }

.lang__trigger {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-xs);
  padding: 10px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  text-align: left;
  transition: border-color 0.2s;
}
.lang__trigger:focus { outline: none; border-color: var(--color-primary); }
.lang__trigger:disabled { opacity: 0.6; cursor: not-allowed; background: var(--color-surface-container-low); }
.lang__trigger--placeholder { color: var(--color-secondary); }
.lang__trigger-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lang__caret { font-size: 20px; color: var(--color-secondary); flex-shrink: 0; }

.lang__popover {
  position: absolute;
  z-index: 10;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  box-shadow: 0 8px 24px rgba(35, 44, 51, 0.12);
  overflow: hidden;
}

.lang__search { position: relative; display: flex; align-items: center; padding: var(--space-xs); }
.lang__search-icon {
  position: absolute;
  left: 16px;
  font-size: 18px;
  color: var(--color-secondary);
  pointer-events: none;
}
.lang__search-input {
  width: 100%;
  padding: 8px 12px 8px 32px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-low);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
}
.lang__search-input:focus { outline: none; border-color: var(--color-primary); }

.lang__list {
  list-style: none;
  margin: 0;
  padding: var(--space-xs);
  max-height: 220px;
  overflow-y: auto;
}
.lang__option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-default);
  text-align: left;
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  transition: background 0.15s;
}
.lang__option--active { background: var(--color-surface-container-high); }
.lang__option--selected { font-weight: 600; color: var(--color-primary); }
.lang__option--any { color: var(--color-secondary); font-style: italic; }
.lang__option-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lang__check { font-size: 18px; color: var(--color-primary); }

.lang__empty {
  padding: 8px 10px;
  font-size: var(--text-label-md);
  color: var(--color-secondary);
}
</style>
