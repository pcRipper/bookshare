<script setup>
import { computed } from 'vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'

/**
 * The library header's dedicated stat block. On mobile it's a solid enclosed
 * card with divided cells; on desktop it renders flush (no frame of its own)
 * so it can fill the shared action panel it sits in, directly under the
 * "Add New Book" button — the two read as one solid unit. Only the Library
 * uses it — the public Profile drops stats entirely since its tabs already
 * surface the same counts.
 */
const props = defineProps({
  stats: { type: Array, default: () => [] }, // [{ label, value }]
  loading: { type: Boolean, default: false },
  /**
   * 'panel' is the original: a card on mobile, frameless vertical rows on
   * desktop so it fills the Library's action panel. 'grid' keeps the cells
   * framed and side by side at every width, which is what a full-width
   * dashboard KPI row needs — a genuinely different affordance rather than a
   * second component that would drift from this one.
   */
  variant: {
    type: String,
    default: 'panel',
    validator: value => ['panel', 'grid'].includes(value),
  },
})

/** Hold the real number of cells while loading so the layout doesn't jump. */
const skeletonCount = computed(() => props.stats.length || 3)
</script>

<template>
  <section class="stat-bar" :class="`stat-bar--${variant}`">
    <template v-if="loading">
      <BaseSkeleton v-for="n in skeletonCount" :key="n" width="56px" height="40px" />
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
/* Mobile: a solid, enclosed card with divided cells. */
.stat-bar {
  display: flex;
  align-items: stretch;
  width: 100%;
  padding: var(--space-sm) 0;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  background: var(--color-surface-container-low);
}
/* Desktop: flush, frameless stacked rows that fill the parent action panel
   (the panel — shared with the Add New Book button — provides the frame). */
@media (min-width: 768px) {
  .stat-bar {
    flex-direction: column;
    width: 100%;
    padding: 0;
    border: none;
    border-radius: 0;
    background: transparent;
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

/* ── grid variant ──────────────────────────────────────────────────────────
   A dashboard KPI row: framed cells side by side at every width, wrapping to
   2x2 on a phone. Overrides come last and are scoped to the modifier, so the
   panel variant above is untouched. */
.stat-bar--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: var(--space-sm);
  padding: 0;
  border: none;
  border-radius: 0;
  background: transparent;
}
@media (min-width: 768px) {
  .stat-bar--grid { flex-direction: row; }
}
.stat-bar--grid .stat {
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  background: var(--color-surface-container-lowest);
}
.stat-bar--grid .stat + .stat { border-left: 1px solid var(--color-outline-variant); border-top: none; }
.stat-bar--grid .stat__value { min-width: 0; text-align: left; }
.stat-bar--grid .stat__label { text-align: left; }
</style>
