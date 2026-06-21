<script setup>
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
</script>

<template>
  <div class="card-skeleton" aria-hidden="true">
    <div class="card-skeleton__cover">
      <BaseSkeleton width="100%" height="100%" radius="0" />
    </div>
    <div class="card-skeleton__body">
      <BaseSkeleton width="85%" height="16px" />
      <BaseSkeleton width="55%" height="12px" />
      <BaseSkeleton width="45%" height="20px" radius="var(--radius-full)" />
    </div>
  </div>
</template>

<style scoped>
.card-skeleton {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.card-skeleton__cover { aspect-ratio: 2 / 3; }
.card-skeleton__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  gap: var(--space-base);
}
@media (min-width: 768px) {
  .card-skeleton__body { padding: 16px; }
}
</style>
