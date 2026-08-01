<script setup>
/**
 * Loading placeholder shaped like DiscoverUserCard: an 80px avatar (BaseAvatar
 * size="lg"), name, two bio lines, a stats line and the follow button. Used by
 * the Discover "Readers" grid, which loads on every visit now that it lists
 * members by default — book-card skeletons there made the layout jump.
 */
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
</script>

<template>
  <div class="user-card-skeleton" aria-hidden="true">
    <BaseSkeleton width="80px" height="80px" circle />
    <BaseSkeleton width="60%" height="18px" />
    <BaseSkeleton width="90%" height="12px" />
    <BaseSkeleton width="70%" height="12px" />
    <BaseSkeleton width="45%" height="12px" />
    <BaseSkeleton class="user-card-skeleton__action" width="100%" height="38px" />
  </div>
</template>

<style scoped>
.user-card-skeleton {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-md);
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
}
.user-card-skeleton__action { margin-top: var(--space-md); }
</style>
