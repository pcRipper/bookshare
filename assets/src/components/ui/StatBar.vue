<script setup>
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'

/**
 * Shared profile stat block used by both the Library and public Profile
 * headers so the two never drift. Renders a flat framed bar on mobile and a
 * compact, self-contained card on desktop; the parent decides where it sits.
 */
defineProps({
  stats: { type: Array, default: () => [] }, // [{ label, value }]
  loading: { type: Boolean, default: false },
})
</script>

<template>
  <section class="stat-bar">
    <template v-if="loading">
      <BaseSkeleton v-for="n in 3" :key="n" width="56px" height="40px" />
    </template>
    <template v-else>
      <div v-for="stat in stats" :key="stat.label" class="stat">
        <span class="stat__value">{{ stat.value }}</span>
        <span class="stat__label">{{ stat.label }}</span>
      </div>
    </template>
  </section>
</template>

<style scoped>
/* Mobile: a flat, full-width framed bar. */
.stat-bar {
  display: flex;
  align-items: stretch;
  width: 100%;
  padding: var(--space-sm) 0;
  border-top: 1px solid var(--color-outline-variant);
  border-bottom: 1px solid var(--color-outline-variant);
}
/* Desktop: a compact, self-contained card that sits inline in the header. */
@media (min-width: 768px) {
  /* fit-content (not auto) so the card shrinks to its cells whether it sits
     in a flex header row (Library) or a block column (Profile). */
  .stat-bar {
    width: fit-content;
    border: 1px solid var(--color-outline-variant);
    border-radius: var(--radius-lg);
    background: var(--color-surface-container-low);
  }
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  min-width: 0;
  padding: 0 var(--space-md);
}
.stat + .stat { border-left: 1px solid var(--color-outline-variant); }
@media (min-width: 768px) { .stat { flex: none; } }

.stat__value {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  font-weight: 700;
  color: var(--color-primary);
}

.stat__label {
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
  text-align: center;
}
</style>
