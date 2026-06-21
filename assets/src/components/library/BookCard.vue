<script setup>
import CategoryTag from '@/components/ui/CategoryTag.vue'

defineProps({
  book: {
    type: Object,
    required: true,
    /* shape: { id, title, author, coverPath: string|null, categories: [{id, name, colorHex}] } */
  },
})

defineEmits(['click'])
</script>

<template>
  <article class="book-card" @click="$emit('click', book)">
    <!-- Cover -->
    <div class="book-card__cover">
      <img
        v-if="book.coverPath"
        :src="book.coverPath"
        :alt="`Cover of ${book.title}`"
        class="book-card__img"
      />
      <div v-else class="book-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined book-card__placeholder-icon">menu_book</span>
      </div>
    </div>

    <!-- Body -->
    <div class="book-card__body">
      <h3 class="book-card__title">{{ book.title }}</h3>
      <p class="book-card__author">{{ book.author }}</p>

      <div class="book-card__tags">
        <CategoryTag
          v-for="cat in book.categories"
          :key="cat.id"
          :label="cat.name"
          :color="cat.colorHex"
        />
      </div>
    </div>
  </article>
</template>

<style scoped>
.book-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  cursor: pointer;
  transition: border-color 0.2s;
}
.book-card:hover { border-color: var(--color-outline-variant); }

/* Cover — fixed height on desktop, portrait aspect on mobile */
.book-card__cover {
  height: 192px;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
  flex-shrink: 0;
}
@media (max-width: 767px) {
  .book-card__cover {
    height: auto;
    aspect-ratio: 2 / 3;
  }
}

.book-card__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s ease;
}
.book-card:hover .book-card__img { transform: scale(1.05); }

.book-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.book-card__placeholder-icon {
  font-size: 48px;
  color: var(--color-outline);
  opacity: 0.5;
}

/* Body */
.book-card__body {
  padding: var(--space-base) var(--space-sm) var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) {
  .book-card__body { padding: 16px; }
}

.book-card__title {
  font-family: var(--font-display);
  font-size: 18px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0 0 4px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
@media (min-width: 768px) {
  .book-card__title { font-size: 20px; }
}

.book-card__author {
  font-size: 13px;
  color: var(--color-secondary);
  margin: 0 0 var(--space-base);
}
@media (min-width: 768px) {
  .book-card__author { font-size: var(--text-label-md); }
}

.book-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
  margin-top: auto;
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}
</style>
