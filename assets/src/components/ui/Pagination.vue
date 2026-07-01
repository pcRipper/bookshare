<script setup>
import { computed } from 'vue'

/**
 * Numbered pagination control: prev/next arrows plus page-number buttons with
 * ellipsis gaps for large ranges. Renders nothing when there's a single page.
 * `page` is the 1-based current page; emits `change` with the requested page.
 */
const props = defineProps({
  page: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  // Disable interaction while a page is loading.
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['change'])

// The page numbers to render, with '…' markers where the range is collapsed.
// Always shows the first and last page and a window around the current one.
const items = computed(() => {
  const total = props.totalPages
  const cur = props.page
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  const out = [1]
  const start = Math.max(2, cur - 1)
  const end = Math.min(total - 1, cur + 1)
  if (start > 2) out.push('…')
  for (let i = start; i <= end; i++) out.push(i)
  if (end < total - 1) out.push('…')
  out.push(total)
  return out
})

function go(page) {
  if (props.disabled) return
  if (page < 1 || page > props.totalPages || page === props.page) return
  emit('change', page)
}
</script>

<template>
  <nav v-if="totalPages > 1" class="pagination" role="navigation" aria-label="Pagination">
    <button
      type="button"
      class="pagination__arrow"
      :disabled="disabled || page <= 1"
      aria-label="Previous page"
      @click="go(page - 1)"
    >
      <span class="material-symbols-outlined">chevron_left</span>
    </button>

    <template v-for="(item, i) in items" :key="i">
      <span v-if="item === '…'" class="pagination__gap" aria-hidden="true">…</span>
      <button
        v-else
        type="button"
        class="pagination__page"
        :class="{ 'pagination__page--active': item === page }"
        :disabled="disabled"
        :aria-current="item === page ? 'page' : undefined"
        @click="go(item)"
      >
        {{ item }}
      </button>
    </template>

    <button
      type="button"
      class="pagination__arrow"
      :disabled="disabled || page >= totalPages"
      aria-label="Next page"
      @click="go(page + 1)"
    >
      <span class="material-symbols-outlined">chevron_right</span>
    </button>
  </nav>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: var(--space-xs);
  padding: var(--space-lg) 0 var(--space-xs);
}

.pagination__arrow,
.pagination__page {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  height: 36px;
  padding: 0 8px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.pagination__arrow:hover:not(:disabled),
.pagination__page:hover:not(:disabled) {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
  border-color: var(--color-outline);
}
.pagination__arrow:disabled,
.pagination__page:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.pagination__arrow .material-symbols-outlined { font-size: 20px; }

.pagination__page--active,
.pagination__page--active:hover:not(:disabled) {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 600;
}

.pagination__gap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 36px;
  color: var(--color-secondary);
}
</style>
