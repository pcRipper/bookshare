<script setup>
/**
 * A single shimmering placeholder block. Compose several to mirror the shape of
 * content that's loading (a card, a row, a heading) instead of a "Loading…" line.
 */
defineProps({
  width: { type: String, default: '100%' },
  height: { type: String, default: '1em' },
  radius: { type: String, default: 'var(--radius-default)' },
  circle: { type: Boolean, default: false },
})
</script>

<template>
  <span
    class="skeleton"
    :style="{ width, height, borderRadius: circle ? '50%' : radius }"
    aria-hidden="true"
  />
</template>

<style scoped>
.skeleton {
  display: block;
  background: linear-gradient(
    90deg,
    var(--color-surface-container-high) 25%,
    var(--color-surface-container-highest) 37%,
    var(--color-surface-container-high) 63%
  );
  background-size: 400% 100%;
  animation: shimmer 1.4s ease infinite;
}

@keyframes shimmer {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}
@media (prefers-reduced-motion: reduce) {
  .skeleton { animation: none; }
}
</style>
