<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
})

/* Palette of muted earth-tone pairs (bg + text) */
const PALETTE = [
  { bg: '#E8F0EA', color: '#2d4d3e' },  // sage green
  { bg: '#F4EAE0', color: '#623f18' },  // warm terracotta
  { bg: '#dae4ed', color: '#3f484f' },  // slate blue
  { bg: '#F0E8ED', color: '#5C324E' },  // dusty mauve
  { bg: '#E8EEF0', color: '#324d5c' },  // cool blue-gray
  { bg: '#f5f0e8', color: '#5a4a2a' },  // parchment
]

/* Deterministic hash: same category name → same color everywhere */
const colors = computed(() => {
  let hash = 0
  for (const ch of props.label) {
    hash = (hash * 31 + ch.charCodeAt(0)) % PALETTE.length
  }
  return PALETTE[Math.abs(hash)]
})
</script>

<template>
  <span
    class="category-tag"
    :style="{ background: colors.bg, color: colors.color }"
  >
    {{ label }}
  </span>
</template>

<style scoped>
.category-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  white-space: nowrap;
}
</style>
