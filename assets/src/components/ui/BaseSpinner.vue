<script setup>
/**
 * Inline loading spinner that inherits the current text colour (`currentColor`),
 * so it sits naturally inside buttons of any variant. Sizes: sm | md | lg.
 */
defineProps({
  size: {
    type: String,
    default: 'md',
    validator: v => ['sm', 'md', 'lg'].includes(v),
  },
})
</script>

<template>
  <span class="spinner" :class="`spinner--${size}`" role="status" aria-label="Loading" />
</template>

<style scoped>
.spinner {
  display: inline-block;
  border-radius: 50%;
  border: 2px solid currentColor;
  border-right-color: transparent;
  animation: spin 0.6s linear infinite;
  vertical-align: middle;
  flex-shrink: 0;
}
.spinner--sm { width: 14px; height: 14px; }
.spinner--md { width: 18px; height: 18px; }
.spinner--lg { width: 24px; height: 24px; border-width: 3px; }

@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .spinner { animation-duration: 1.2s; }
}
</style>
