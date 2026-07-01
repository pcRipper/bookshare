<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

/**
 * Plain dropdown (listbox) styled to match `ui/LanguageSelect.vue` so simple
 * option pickers share the same look as the searchable one. The trigger shows
 * the current selection; opening it reveals the options with keyboard
 * navigation. `v-model` is the selected option's `value`.
 */
const props = defineProps({
  modelValue: { type: [String, Number, null], default: null },
  // Array of { value, label }.
  options: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Select…' },
  id: { type: String, default: undefined },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const highlight = ref(0)
const root = ref(null)

const selectedLabel = computed(() => {
  const found = props.options.find(o => o.value === props.modelValue)
  return found ? found.label : null
})

// Open onto the current selection so arrow keys start from the right place.
watch(open, v => {
  if (v) {
    const i = props.options.findIndex(o => o.value === props.modelValue)
    highlight.value = i >= 0 ? i : 0
  }
})

function toggle() {
  if (!props.disabled) open.value = !open.value
}

function select(value) {
  emit('update:modelValue', value)
  open.value = false
}

function move(delta) {
  const n = props.options.length
  if (n) highlight.value = (highlight.value + delta + n) % n
}

function onEnter() {
  const item = props.options[highlight.value]
  if (item) select(item.value)
}

function onClickOutside(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('mousedown', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
  <div ref="root" class="sel">
    <button
      :id="id"
      type="button"
      class="sel__trigger"
      :class="{ 'sel__trigger--placeholder': !selectedLabel }"
      :disabled="disabled"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="toggle"
      @keydown.down.prevent="open ? move(1) : (open = true)"
      @keydown.up.prevent="open && move(-1)"
      @keydown.enter.prevent="open ? onEnter() : (open = true)"
      @keydown.esc.prevent="open = false"
    >
      <span class="sel__trigger-text">{{ selectedLabel ?? placeholder }}</span>
      <span class="material-symbols-outlined sel__caret">{{ open ? 'expand_less' : 'expand_more' }}</span>
    </button>

    <div v-if="open" class="sel__popover">
      <ul class="sel__list" role="listbox">
        <li v-for="(opt, i) in options" :key="opt.value">
          <button
            type="button"
            class="sel__option"
            :class="{
              'sel__option--active': i === highlight,
              'sel__option--selected': opt.value === modelValue,
            }"
            @click="select(opt.value)"
            @mouseenter="highlight = i"
          >
            <span class="sel__option-name">{{ opt.label }}</span>
            <span v-if="opt.value === modelValue" class="material-symbols-outlined sel__check">check</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.sel { position: relative; }

.sel__trigger {
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
.sel__trigger:focus { outline: none; border-color: var(--color-primary); }
.sel__trigger:disabled { opacity: 0.6; cursor: not-allowed; background: var(--color-surface-container-low); }
.sel__trigger--placeholder { color: var(--color-secondary); }
.sel__trigger-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sel__caret { font-size: 20px; color: var(--color-secondary); flex-shrink: 0; }

.sel__popover {
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

.sel__list {
  list-style: none;
  margin: 0;
  padding: var(--space-xs);
  max-height: 220px;
  overflow-y: auto;
}
.sel__option {
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
.sel__option--active { background: var(--color-surface-container-high); }
.sel__option--selected { font-weight: 600; color: var(--color-primary); }
.sel__option-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sel__check { font-size: 18px; color: var(--color-primary); }
</style>
