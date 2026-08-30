<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { languageLabel } from '@/utils/languages'
import { wishPriorityMeta, wishPriorityKey } from '@/utils/wishPriority'
import { useCoverFallback } from '@/composables/useCoverFallback'

const { hasCover, onCoverError } = useCoverFallback()
const { t } = useI18n()

const props = defineProps({
  book: {
    type: Object,
    required: true,
    /* shape: { id, title, author, description, coverPath, status, requested, categories, languageName } */
  },
  // When true the viewer owns this profile — borrowing is hidden.
  isSelf: { type: Boolean, default: false },
  // Parent-controlled: true while this book's borrow request is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['request', 'open'])

// Clicking the card always opens the read-only detail modal — on your own
// profile too, so the book section stays a preview (management lives in the
// library, not here).
function onCardClick() {
  emit('open', props.book)
}

const available = computed(() => props.book.status === 'own')

// Corner badge for non-available books in the full collection view.
//
// A wish-list book has no lending state — its owner doesn't have it — so the
// badge carries the priority instead and the borrow action is withheld below.
// Decided here rather than by a parent prop: the shelf is a property of the
// book, and every surface rendering this card would otherwise have to know.
const wishMeta = computed(() => props.book.isWished ? wishPriorityMeta(props.book.wishPriority) : null)

const statusBadge = computed(() => {
  if (props.book.isWished) return t(wishPriorityKey(props.book.wishPriority))
  if (props.book.status === 'lent') return t('book.status.lent')
  if (props.book.status === 'currently_reading') return t('book.status.reading')
  if (props.book.status === 'unavailable') return t('book.status.unavailable')
  return null
})

const action = computed(() => {
  if (props.book.requested) return { label: t('profile.requested'), state: 'requested' }
  if (available.value) return { label: t('profile.requestToBorrow'), state: 'available' }
  const label = props.book.status === 'lent' ? t('profile.currentlyLent')
    : props.book.status === 'currently_reading' ? t('book.status.reading')
    : t('book.status.unavailable')
  return { label, state: 'disabled' }
})

function onAction() {
  if (action.value.state === 'available') emit('request', props.book.id)
}
</script>

<template>
  <article class="borrow-card borrow-card--clickable" @click="onCardClick">
    <div class="borrow-card__cover">
      <img
        v-if="hasCover(book)"
        :src="book.coverPath"
        :alt="t('book.coverAlt', { title: book.title })"
        class="borrow-card__img"
        @error="onCoverError(book.id)"
      />
      <div v-else class="borrow-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">menu_book</span>
      </div>
      <span
        v-if="statusBadge"
        class="borrow-card__badge"
        :class="wishMeta ? `borrow-card__badge--wish-${wishMeta.tone}` : null"
      >{{ statusBadge }}</span>
      <span v-if="book.isRead" class="borrow-card__read" :title="t('profile.readBadge')">
        <span class="material-symbols-outlined">check_circle</span>
        {{ t('book.read') }}
      </span>
    </div>

    <div class="borrow-card__body">
      <h3 class="borrow-card__title">{{ book.title }}</h3>
      <p class="borrow-card__author">{{ book.author }}</p>

      <p v-if="book.language" class="borrow-card__lang">
        <span class="material-symbols-outlined">language</span>
        {{ languageLabel(book.language, book.languageName) }}
      </p>

      <!-- Own-profile cards are a preview only — no borrow affordance. Neither
           is a wish-list book: its owner hasn't got it to lend. -->
      <button
        v-if="!isSelf && !book.isWished"
        class="borrow-card__action"
        :class="`borrow-card__action--${action.state}`"
        :disabled="action.state === 'requested' || action.state === 'disabled' || pending"
        @click.stop="onAction"
      >
        <BaseSpinner v-if="pending" size="sm" />
        <span v-else-if="action.state === 'available'" class="material-symbols-outlined">handshake</span>
        <span v-else-if="action.state === 'requested'" class="material-symbols-outlined">check</span>
        {{ pending ? t('profile.requesting') : action.label }}
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
/* Wish-list priority, the same traffic light the library cards use. */
.borrow-card__badge--wish-green { background: var(--color-primary); color: var(--color-on-primary); }
.borrow-card__badge--wish-amber { background: var(--color-tertiary); color: #ffffff; }
.borrow-card__badge--wish-red { background: var(--color-error); color: #ffffff; }

.borrow-card__read {
  position: absolute;
  top: var(--space-base);
  left: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px 2px 6px;
  border-radius: var(--radius-full);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.borrow-card__read .material-symbols-outlined {
  font-size: 12px;
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
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

.borrow-card__lang {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--color-secondary);
  margin: 0 0 var(--space-sm);
}
.borrow-card__lang .material-symbols-outlined { font-size: 14px; }

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
</style>
