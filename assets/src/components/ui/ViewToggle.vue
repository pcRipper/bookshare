<script setup>
/**
 * Segmented control switching a book list between the card grid and the compact
 * table. Two icon buttons; v-model carries 'cards' | 'table'.
 */
defineProps({
  modelValue: { type: String, default: 'cards' }, // 'cards' | 'table'
})
const emit = defineEmits(['update:modelValue'])

const options = [
  { value: 'cards', icon: 'grid_view', label: 'Card view' },
  { value: 'table', icon: 'view_list', label: 'Table view' },
]
</script>

<template>
  <div class="view-toggle" role="group" aria-label="Choose list layout">
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      class="view-toggle__btn"
      :class="{ 'view-toggle__btn--active': modelValue === opt.value }"
      :aria-pressed="modelValue === opt.value"
      :title="opt.label"
      :aria-label="opt.label"
      @click="emit('update:modelValue', opt.value)"
    >
      <span class="material-symbols-outlined">{{ opt.icon }}</span>
    </button>
  </div>
</template>

<style scoped>
.view-toggle {
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  flex-shrink: 0;
}
.view-toggle__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 30px;
  border-radius: var(--radius-full);
  color: var(--color-secondary);
  transition: background 0.2s, color 0.2s;
}
.view-toggle__btn .material-symbols-outlined { font-size: 20px; }
.view-toggle__btn:hover:not(.view-toggle__btn--active) { color: var(--color-on-background); }
.view-toggle__btn--active {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
</style>
