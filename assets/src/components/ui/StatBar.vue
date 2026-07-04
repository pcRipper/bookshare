<script setup>
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'

/**
 * The library header's dedicated stat block. Renders a flat, full-width framed
 * bar on mobile and a small, self-contained vertical card (stacked rows) on
 * desktop. Only the Library uses it — the public Profile drops stats entirely
 * since its tabs already surface the same counts.
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
/* Mobile: a flat, full-width framed bar with divided cells. */
.stat-bar {
  display: flex;
  align-items: stretch;
  width: 100%;
  padding: var(--space-sm) 0;
  border-top: 1px solid var(--color-outline-variant);
  border-bottom: 1px solid var(--color-outline-variant);
}
/* Desktop: a small, dedicated vertical block — stacked rows in a soft card. */
@media (min-width: 768px) {
  .stat-bar {
    flex-direction: column;
    width: fit-content;
    padding: 0;
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
@media (min-width: 768px) {
  /* Each stat becomes a compact row: value, then label. */
  .stat {
    flex: none;
    flex-direction: row;
    align-items: baseline;
    gap: var(--space-sm);
    padding: 10px var(--space-md);
  }
  .stat + .stat { border-left: none; border-top: 1px solid var(--color-outline-variant); }
}

.stat__value {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  font-weight: 700;
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
}
/* Right-align the number in a fixed column so labels line up row to row. */
@media (min-width: 768px) {
  .stat__value { min-width: 2.5ch; text-align: right; }
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
@media (min-width: 768px) { .stat__label { text-align: left; } }
</style>
