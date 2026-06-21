<script setup>
import { computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'

const props = defineProps({
  book: {
    type: Object,
    required: true,
    /* shape: { id, title, author, coverPath, status, requested, categories, owner } */
  },
  // Parent-controlled: true while this book's borrow request is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['request'])

const primaryCategory = computed(() => props.book.categories?.[0] ?? null)
const available = computed(() => props.book.status === 'own')

const action = computed(() => {
  if (props.book.requested) return { label: 'Requested', state: 'requested' }
  if (available.value) return { label: 'Request to Borrow', state: 'available' }
  return { label: props.book.status === 'lent' ? 'Currently Lent' : 'Unavailable', state: 'disabled' }
})

function onAction() {
  if (action.value.state === 'available') emit('request', props.book.id)
}
</script>

<template>
  <article class="discover-card">
    <div class="discover-card__cover">
      <img
        v-if="book.coverPath"
        :src="book.coverPath"
        :alt="`Cover of ${book.title}`"
        class="discover-card__img"
        loading="lazy"
      />
      <div v-else class="discover-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">menu_book</span>
      </div>
      <CategoryTag
        v-if="primaryCategory"
        :label="primaryCategory.name"
        :color="primaryCategory.colorHex"
        class="discover-card__chip"
      />
    </div>

    <div class="discover-card__body">
      <h3 class="discover-card__title">{{ book.title }}</h3>
      <p class="discover-card__author">{{ book.author }}</p>

      <RouterLink
        v-if="book.owner"
        :to="`/profile/${book.owner.id}`"
        class="discover-card__owner"
        @click.stop
      >
        <BaseAvatar :src="book.owner.avatarUrl" :name="book.owner.fullName" size="sm" />
        <span class="discover-card__owner-name">{{ book.owner.fullName }}</span>
      </RouterLink>

      <button
        class="discover-card__action"
        :class="`discover-card__action--${action.state}`"
        :disabled="action.state !== 'available' || pending"
        @click="onAction"
      >
        <BaseSpinner v-if="pending" size="sm" />
        <span v-else-if="action.state === 'available'" class="material-symbols-outlined">handshake</span>
        <span v-else-if="action.state === 'requested'" class="material-symbols-outlined">check</span>
        {{ pending ? 'Requesting…' : action.label }}
      </button>
    </div>
  </article>
</template>

<style scoped>
.discover-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.discover-card:hover {
  border-color: var(--color-outline-variant);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.08);
}

.discover-card__cover {
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
}
.discover-card__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s ease;
}
.discover-card:hover .discover-card__img { transform: scale(1.05); }

.discover-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.discover-card__placeholder .material-symbols-outlined {
  font-size: 48px;
  color: var(--color-outline);
  opacity: 0.5;
}

.discover-card__chip {
  position: absolute;
  top: var(--space-base);
  left: var(--space-base);
}

.discover-card__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) {
  .discover-card__body { padding: 16px; }
}

.discover-card__title {
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0 0 2px;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
@media (min-width: 768px) {
  .discover-card__title { font-size: 18px; -webkit-line-clamp: 2; }
}

.discover-card__author {
  font-size: 13px;
  color: var(--color-on-surface-variant);
  margin: 0 0 var(--space-sm);
}

.discover-card__owner {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
  color: var(--color-secondary);
  min-width: 0;
}
.discover-card__owner:hover .discover-card__owner-name { color: var(--color-primary); }
.discover-card__owner-name {
  font-size: var(--text-label-sm);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.15s;
}

.discover-card__action {
  margin-top: auto;
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  border: 1px solid transparent;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.discover-card__action .material-symbols-outlined { font-size: 18px; }

.discover-card__action--available {
  background: var(--color-primary);
  color: var(--color-on-primary);
  cursor: pointer;
}
.discover-card__action--available:hover { background: var(--color-primary-container); }
.discover-card__action--available:active { transform: scale(0.98); }

.discover-card__action--requested {
  background: var(--color-primary-fixed);
  color: var(--color-on-primary-fixed-variant);
  cursor: default;
}

.discover-card__action--disabled {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  cursor: not-allowed;
}
</style>
