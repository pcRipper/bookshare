<script setup>
import { computed } from 'vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

/**
 * A book collection card. Two variants:
 *  - 'owner'  → the viewer's own collection (Library tab). The whole card opens
 *    the edit modal (mirroring how a BookCard opens the Manage Book modal —
 *    edit/delete live in that modal, not on the card). An "On loan" badge marks
 *    a collection that's frozen while borrowed.
 *  - 'browse' → someone else's collection (Profile tab): the card opens a preview
 *    and carries a "Borrow collection" action.
 *
 * Always badged "Collection" so it's never mistaken for a single-book card.
 */
const props = defineProps({
  collection: {
    type: Object,
    required: true,
    /* shape: { id, name, description, coverUrl, bookCount, availableCount, canEdit, books } */
  },
  variant: { type: String, default: 'owner' }, // 'owner' | 'browse'
  // 'browse' only: viewer owns this profile → borrowing is hidden (preview).
  isSelf: { type: Boolean, default: false },
  // Parent-controlled: true while a borrow for this card is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['borrow', 'open', 'edit'])

const isOwner = computed(() => props.variant === 'owner')

// A collection is borrowable only when at least two of its books are available.
const borrowable = computed(() => props.collection.availableCount >= 2)

// Up to three member covers to hint at the contents behind the header.
const previewCovers = computed(() =>
  (props.collection.books ?? [])
    .map(b => b.coverPath)
    .filter(Boolean)
    .slice(0, 3),
)

function onCardClick() {
  emit(isOwner.value ? 'edit' : 'open', props.collection)
}
</script>

<template>
  <article class="collection-card collection-card--clickable" @click="onCardClick">
    <div class="collection-card__cover">
      <img
        v-if="collection.coverUrl"
        :src="collection.coverUrl"
        :alt="`Cover of ${collection.name}`"
        class="collection-card__img"
        loading="lazy"
      />
      <div v-else class="collection-card__motif" aria-hidden="true">
        <div v-if="previewCovers.length" class="collection-card__stack">
          <img
            v-for="(cover, i) in previewCovers"
            :key="i"
            :src="cover"
            class="collection-card__stack-img"
            :style="{ '--i': i }"
            alt=""
          />
        </div>
        <span v-else class="material-symbols-outlined collection-card__motif-icon">library_books</span>
      </div>

      <!-- Always mark it as a collection. -->
      <span class="collection-card__badge">
        <span class="material-symbols-outlined">library_books</span>Collection
      </span>

      <!-- Owner: frozen while out on loan. -->
      <span v-if="isOwner && !collection.canEdit" class="collection-card__status">
        <span class="material-symbols-outlined">handshake</span>On loan
      </span>
    </div>

    <div class="collection-card__body">
      <h3 class="collection-card__title">{{ collection.name }}</h3>
      <p v-if="collection.description" class="collection-card__desc">{{ collection.description }}</p>

      <p class="collection-card__meta">
        <span>{{ collection.bookCount }} {{ collection.bookCount === 1 ? 'book' : 'books' }}</span>
        <span class="collection-card__dot">·</span>
        <span>{{ collection.availableCount }} available</span>
      </p>

      <!-- Browse action (someone else's collection) -->
      <button
        v-if="!isOwner && !isSelf"
        class="collection-btn collection-btn--borrow"
        :disabled="!borrowable || pending"
        @click.stop="emit('borrow', collection)"
      >
        <BaseSpinner v-if="pending" size="sm" />
        <span v-else class="material-symbols-outlined">handshake</span>
        {{ borrowable ? 'Borrow collection' : 'Not enough available' }}
      </button>
    </div>
  </article>
</template>

<style scoped>
.collection-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.collection-card:hover {
  border-color: var(--color-outline-variant);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.08);
}
.collection-card--clickable { cursor: pointer; }

/* Portrait 2/3 cover so a collection card matches a book card in the grid. */
.collection-card__cover {
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
}
.collection-card__img { width: 100%; height: 100%; object-fit: cover; }

.collection-card__motif {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-primary-container) 0%, var(--color-surface-variant) 100%);
}
.collection-card__motif-icon { font-size: 48px; color: var(--color-primary); opacity: 0.6; }

/* Fanned member covers as a preview when there's no explicit cover. */
.collection-card__stack {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  height: 100%;
  padding: var(--space-sm);
}
.collection-card__stack-img {
  width: 64px;
  height: 92px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 2px solid var(--color-surface-container-lowest);
  box-shadow: 0 2px 6px rgba(35, 44, 51, 0.2);
  margin-left: calc(var(--i) * -14px);
  transform: rotate(calc((var(--i) - 1) * 4deg));
}

.collection-card__badge {
  position: absolute;
  top: var(--space-base);
  left: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px 3px 7px;
  border-radius: var(--radius-full);
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  box-shadow: 0 1px 4px rgba(35, 44, 51, 0.18);
}
.collection-card__badge .material-symbols-outlined { font-size: 13px; }

.collection-card__status {
  position: absolute;
  top: var(--space-base);
  right: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px 3px 6px;
  border-radius: var(--radius-full);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  box-shadow: 0 1px 4px rgba(35, 44, 51, 0.18);
}
.collection-card__status .material-symbols-outlined {
  font-size: 13px;
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
}

.collection-card__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) { .collection-card__body { padding: 16px; } }

.collection-card__title {
  font-family: var(--font-display);
  font-size: 18px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0 0 4px;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.collection-card__desc {
  font-size: 13px;
  color: var(--color-on-surface-variant);
  margin: 0 0 var(--space-sm);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.collection-card__meta {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  margin: 0 0 var(--space-sm);
}
.collection-card__dot { opacity: 0.6; }

.collection-btn {
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
.collection-btn .material-symbols-outlined { font-size: 18px; }
.collection-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.collection-btn--borrow {
  margin-top: auto;
  width: 100%;
  background: var(--color-primary);
  color: var(--color-on-primary);
  cursor: pointer;
}
.collection-btn--borrow:hover:not(:disabled) { background: var(--color-primary-container); }
.collection-btn--borrow:active:not(:disabled) { transform: scale(0.98); }
</style>
