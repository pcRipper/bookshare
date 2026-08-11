<script setup>
/**
 * Loading state for the dashboard, mirroring the real layout so nothing jumps
 * when the data lands — and, importantly, holding the chart wrappers at their
 * final height. Chart.js measures its container at construction, so a canvas
 * created inside a collapsed box yields a chart that never recovers.
 */
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
</script>

<template>
  <div class="admin-skeleton">
    <section v-for="section in 2" :key="`kpi-${section}`" class="admin-skeleton__section">
      <BaseSkeleton width="180px" height="24px" />
      <div class="admin-skeleton__kpis">
        <BaseSkeleton v-for="n in 4" :key="n" height="76px" radius="8px" />
      </div>
      <div class="admin-skeleton__charts">
        <BaseSkeleton v-for="n in 2" :key="n" class="admin-skeleton__chart" radius="8px" />
      </div>
    </section>

    <section class="admin-skeleton__section">
      <BaseSkeleton width="180px" height="24px" />
      <div class="admin-skeleton__rows">
        <BaseSkeleton v-for="n in 6" :key="n" height="36px" />
      </div>
    </section>
  </div>
</template>

<style scoped>
.admin-skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
}
.admin-skeleton__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.admin-skeleton__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: var(--space-sm);
}
.admin-skeleton__charts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: var(--space-md);
}
/* Same height the real charts use, so the swap is invisible. */
.admin-skeleton__chart { height: 220px; }
@media (min-width: 768px) {
  .admin-skeleton__chart { height: 300px; }
}
.admin-skeleton__rows {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
</style>
