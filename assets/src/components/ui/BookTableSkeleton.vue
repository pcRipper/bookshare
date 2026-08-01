<script setup>
/**
 * Loading placeholder for `ui/BookTable.vue` — the table counterpart of
 * `BookGridSkeleton`. Mirrors the row rhythm (cover block + stacked title and
 * author bars, plus the trailing meta cells) so the layout doesn't jump from a
 * card grid to a table when the data lands. `detailed` matches the wider set of
 * columns the detailed table renders.
 */
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'

defineProps({
  count: { type: Number, default: 8 },
  detailed: { type: Boolean, default: false },
})
</script>

<template>
  <div class="table-skeleton" role="status" aria-label="Loading books">
    <div class="table-skeleton__head">
      <BaseSkeleton width="100%" height="12px" />
    </div>
    <div v-for="n in count" :key="n" class="table-skeleton__row">
      <BaseSkeleton width="18px" height="18px" radius="3px" />
      <BaseSkeleton width="32px" height="48px" radius="3px" />
      <div class="table-skeleton__title">
        <BaseSkeleton width="60%" height="14px" />
        <BaseSkeleton width="35%" height="11px" />
      </div>
      <template v-if="detailed">
        <BaseSkeleton class="table-skeleton__cell" width="120px" height="18px" radius="var(--radius-full)" />
        <BaseSkeleton class="table-skeleton__cell" width="160px" height="12px" />
        <BaseSkeleton class="table-skeleton__cell" width="90px" height="12px" />
      </template>
      <BaseSkeleton class="table-skeleton__cell" width="70px" height="12px" />
      <BaseSkeleton class="table-skeleton__cell" width="80px" height="18px" radius="var(--radius-full)" />
    </div>
  </div>
</template>

<style scoped>
.table-skeleton { margin-top: var(--space-sm); }

.table-skeleton__head {
  padding: var(--space-xs) var(--space-sm);
  border-bottom: 1px solid var(--color-outline-variant);
}

.table-skeleton__row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-sm);
  border-bottom: 1px solid var(--color-surface-container-highest);
}

.table-skeleton__title {
  flex: 1 1 auto;
  min-width: 120px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

/* The trailing meta cells sit off-screen on a phone, exactly as the real
   table's do inside its scroll container. */
.table-skeleton__cell { flex: 0 0 auto; }
@media (max-width: 599px) {
  .table-skeleton__cell { display: none; }
}
</style>
