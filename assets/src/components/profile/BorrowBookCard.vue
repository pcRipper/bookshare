<script setup>
import { computed } from 'vue'

const props = defineProps({
  book: {
    type: Object,
    required: true,
    /* shape: { id, title, author, coverPath, status, requested, categories } */
  },
  // When true the viewer owns this profile — borrowing is hidden.
  isSelf: { type: Boolean, default: false },
})

const emit = defineEmits(['request', 'edit'])

const available = computed(() => props.book.status === 'own')

// Corner badge for non-available books in the full collection view.
const statusBadge = computed(() => {
  if (props.book.status === 'lent') return 'On Loan'
  if (props.book.status === 'unavailable') return 'Unavailable'
  return null
})

const action = computed(() => {
  if (props.isSelf) return { label: 'Manage', state: 'manage' }
  if (props.book.requested) return { label: 'Requested', state: 'requested' }
  if (available.value) return { label: 'Request to Borrow', state: 'available' }
  return { label: props.book.status === 'lent' ? 'Currently Lent' : 'Unavailable', state: 'disabled' }
})

function onAction() {
  if (action.value.state === 'available') emit('request', props.book.id)
  else if (action.value.state === 'manage') emit('edit', props.book)
}
</script>

<template>
  <article class="borrow-card" :class="{ 'borrow-card--clickable': isSelf }" @click="isSelf && emit('edit', book)">
    <div class="borrow-card__cover">
      <img
        v-if="book.coverPath"
        :src="book.coverPath"
        :alt="`Cover of ${book.title}`"
        class="borrow-card__img"
      />
      <div v-else class="borrow-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">menu_book</span>
      </div>
      <span v-if="statusBadge" class="borrow-card__badge">{{ statusBadge }}</span>
    </div>

    <div class="borrow-card__body">
      <h3 class="borrow-card__title">{{ book.title }}</h3>
      <p class="borrow-card__author">{{ book.author }}</p>

      <button
        class="borrow-card__action"
        :class="`borrow-card__action--${action.state}`"
        :disabled="action.state === 'requested' || action.state === 'disabled'"
        @click.stop="onAction"
      >
        <span v-if="action.state === 'available'" class="material-symbols-outlined">handshake</span>
        <span v-else-if="action.state === 'requested'" class="material-symbols-outlined">check</span>
        <span v-else-if="action.state === 'manage'" class="material-symbols-outlined">edit</span>
        {{ action.label }}
      </button>
    </div>
  </article>
</template>

<style scoped>
.borrow-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.borrow-card:hover { border-color: var(--color-outline-variant); }
.borrow-card--clickable { cursor: pointer; }

.borrow-card__cover {
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
}
.borrow-card__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s ease;
}
.borrow-card:hover .borrow-card__img { transform: scale(1.05); }

.borrow-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.borrow-card__placeholder .material-symbols-outlined {
  font-size: 48px;
  color: var(--color-outline);
  opacity: 0.5;
}

.borrow-card__badge {
  position: absolute;
  top: var(--space-base);
  right: var(--space-base);
  padding: 2px 10px;
  border-radius: var(--radius-full);
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.borrow-card__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) {
  .borrow-card__body { padding: 16px; }
}

.borrow-card__title {
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
  .borrow-card__title { font-size: 18px; -webkit-line-clamp: 2; }
}

.borrow-card__author {
  font-size: 13px;
  color: var(--color-on-surface-variant);
  margin: 0 0 var(--space-sm);
}

.borrow-card__action {
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
.borrow-card__action .material-symbols-outlined { font-size: 18px; }

.borrow-card__action--available {
  background: var(--color-primary);
  color: var(--color-on-primary);
  cursor: pointer;
}
.borrow-card__action--available:hover { background: var(--color-primary-container); }
.borrow-card__action--available:active { transform: scale(0.98); }

.borrow-card__action--requested {
  background: var(--color-primary-fixed);
  color: var(--color-on-primary-fixed-variant);
  cursor: default;
}

.borrow-card__action--disabled {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  cursor: not-allowed;
}

.borrow-card__action--manage {
  background: transparent;
  color: var(--color-primary);
  border-color: var(--color-outline-variant);
  cursor: pointer;
}
.borrow-card__action--manage:hover { background: var(--color-surface-container-low); }
</style>
