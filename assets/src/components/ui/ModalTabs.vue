<script setup>
/**
 * The underlined tab strip used inside a modal — the Manage Book modal's
 * "Create manually / Find a template" pair and the book detail sheet's
 * "Details / Review" pair.
 *
 * This is that markup lifted out of `ManageBookModal` once a second caller
 * wanted it, the same way `SubTabNav` was lifted out of the loan history. It is
 * deliberately *not* a `SubTabNav` variant: that one is a pill strip in a
 * rounded track carrying badges and counts, a different control for a different
 * job (second-level page navigation, not switching a dialog's panel).
 *
 * Each item is `{ key, label }`. Arrow keys move between tabs and the strip is
 * a single tab stop — what `role="tablist"` promises, and what these tabs
 * lacked while the markup lived inline.
 */
const props = defineProps({
  modelValue: { type: String, required: true },
  items: { type: Array, required: true },
  ariaLabel: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

function onKeydown(e) {
  const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0
  if (!dir) return
  e.preventDefault()
  const i = Math.max(props.items.findIndex(it => it.key === props.modelValue), 0)
  const next = (i + dir + props.items.length) % props.items.length
  emit('update:modelValue', props.items[next].key)
  // Follow the selection with focus, so the next arrow press continues from it.
  // By index, not by `[aria-selected]` — that attribute hasn't moved yet.
  e.currentTarget.querySelectorAll('[role="tab"]')[next]?.focus()
}
</script>

<template>
  <div class="modal-tabs" role="tablist" :aria-label="ariaLabel" @keydown="onKeydown">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      class="modal-tabs__tab"
      :class="{ 'modal-tabs__tab--active': modelValue === item.key }"
      role="tab"
      :aria-selected="modelValue === item.key"
      :tabindex="modelValue === item.key ? 0 : -1"
      @click="emit('update:modelValue', item.key)"
    >
      {{ item.label }}
    </button>
  </div>
</template>

<style scoped>
.modal-tabs {
  display: flex;
  gap: var(--space-xs);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.modal-tabs__tab {
  padding: var(--space-sm) var(--space-base);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  background: none;
  border: 0;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  cursor: pointer;
  transition: color 0.2s, border-color 0.2s;
}
.modal-tabs__tab:hover { color: var(--color-on-background); }
.modal-tabs__tab--active { color: var(--color-primary); border-bottom-color: var(--color-primary); }
.modal-tabs__tab:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: -2px;
}

@media (prefers-reduced-motion: reduce) {
  .modal-tabs__tab { transition: none; }
}
</style>
