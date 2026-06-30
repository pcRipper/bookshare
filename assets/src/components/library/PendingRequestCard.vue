<script setup>
import { computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  request: {
    type: Object,
    required: true,
    /* shape: { id, status, requestedAt, book: { id, title, author, coverPath, owner } } */
  },
  // Parent-controlled: true while this request's cancellation is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel'])

const book = computed(() => props.request.book)
const owner = computed(() => props.request.book?.owner ?? null)

function onCancel() {
  if (!props.pending) emit('cancel', props.request.id)
}
</script>

<template>
  <article class="pending-card">
    <div class="pending-card__cover">
      <img
        v-if="book.coverPath"
        :src="book.coverPath"
        :alt="`Cover of ${book.title}`"
        class="pending-card__img"
        loading="lazy"
      />
      <div v-else class="pending-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">menu_book</span>
      </div>
      <span class="pending-card__badge">
        <span class="material-symbols-outlined">hourglass_empty</span>Awaiting approval
      </span>
    </div>

    <div class="pending-card__body">
      <h3 class="pending-card__title">{{ book.title }}</h3>
      <p class="pending-card__author">{{ book.author }}</p>

      <RouterLink v-if="owner" :to="`/profile/${owner.id}`" class="pending-card__owner">
        <BaseAvatar :src="owner.avatarUrl" :name="owner.fullName" size="sm" />
        <span class="pending-card__owner-name">from {{ owner.fullName }}</span>
      </RouterLink>

      <p class="pending-card__meta">Requested {{ request.requestedAt }}</p>

      <button class="pending-card__action" :disabled="pending" @click="onCancel">
        <BaseSpinner v-if="pending" size="sm" />
        <span v-else class="material-symbols-outlined">close</span>
        {{ pending ? 'Cancelling…' : 'Cancel request' }}
      </button>
    </div>
  </article>
</template>

<style scoped>
.pending-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.pending-card:hover {
  border-color: var(--color-outline-variant);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.08);
}

.pending-card__cover {
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
}
.pending-card__img { width: 100%; height: 100%; object-fit: cover; }
.pending-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.pending-card__placeholder .material-symbols-outlined { font-size: 48px; color: var(--color-outline); opacity: 0.5; }

.pending-card__badge {
  position: absolute;
  top: var(--space-base);
  left: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px 3px 6px;
  border-radius: var(--radius-full);
  background: var(--color-secondary-container);
  color: var(--color-on-secondary-fixed-variant);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  box-shadow: 0 1px 4px rgba(35, 44, 51, 0.18);
}
.pending-card__badge .material-symbols-outlined { font-size: 13px; }

.pending-card__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) { .pending-card__body { padding: 16px; } }

.pending-card__title {
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
@media (min-width: 768px) { .pending-card__title { font-size: 18px; } }

.pending-card__author { font-size: 13px; color: var(--color-on-surface-variant); margin: 0 0 var(--space-sm); }

.pending-card__owner {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-xs);
  color: var(--color-secondary);
  min-width: 0;
}
.pending-card__owner:hover .pending-card__owner-name { color: var(--color-primary); }
.pending-card__owner-name {
  font-size: var(--text-label-sm);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.15s;
}

.pending-card__meta {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
  margin: 0 0 var(--space-sm);
}

.pending-card__action {
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
  border: 1px solid var(--color-error);
  color: var(--color-error);
  background: transparent;
  cursor: pointer;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.pending-card__action .material-symbols-outlined { font-size: 18px; }
.pending-card__action:hover:not(:disabled) { background: var(--color-error-container); }
.pending-card__action:active:not(:disabled) { transform: scale(0.98); }
.pending-card__action:disabled { cursor: not-allowed; opacity: 0.7; }
</style>
