<script setup>
import BookCardSkeleton from '@/components/ui/BookCardSkeleton.vue'

defineProps({
  count: { type: Number, default: 8 },
})
</script>

<template>
  <div class="grid-skeleton" role="status" aria-label="Loading books">
    <BookCardSkeleton v-for="n in count" :key="n" />
  </div>
</template>

<style scoped>
.grid-skeleton {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
}
@media (min-width: 600px) { .grid-skeleton { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 960px) { .grid-skeleton { grid-template-columns: repeat(4, 1fr); } }
</style>
