<script setup>
import { computed } from 'vue'
import { CATEGORY_PALETTE, resolveCategoryColors } from '@/utils/categoryColors'

const props = defineProps({
  label: { type: String, required: true },
  color: { type: String, default: null },  // stored colorHex from the backend
})

// Prefer the category's stored colour. Fall back to a deterministic hash of the
// name so legacy data (or callers that omit the colour) still renders stably.
const colors = computed(() => {
  if (props.color) return resolveCategoryColors(props.color)
  let hash = 0
  for (const ch of props.label) {
    hash = (hash * 31 + ch.charCodeAt(0)) % CATEGORY_PALETTE.length
  }
  return CATEGORY_PALETTE[Math.abs(hash)]
})
</script>

<template>
  <span
    class="category-tag"
    :style="{ background: colors.bg, color: colors.text, borderColor: colors.border }"
  >
    {{ label }}
  </span>
</template>

<style scoped>
.category-tag {
  display: inline-block;
  padding: 2px 8px;
  border: 1px solid transparent;
  border-radius: var(--radius-full);
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  white-space: nowrap;
}
</style>
