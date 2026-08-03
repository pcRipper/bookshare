<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'
import { languageLabel } from '@/utils/languages'
import { useCoverFallback } from '@/composables/useCoverFallback'

const { hasCover, onCoverError } = useCoverFallback()
const { t } = useI18n()

const props = defineProps({
  book: {
    type: Object,
    required: true,
    /* shape: { id, title, author, description, coverPath, status, requested, categories, owner } */
  },
  // Parent-controlled: true while this book's borrow request is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['request', 'open'])

const primaryCategory = computed(() => props.book.categories?.[0] ?? null)
const available = computed(() => props.book.status === 'own')

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
  <article class="discover-card discover-card--clickable" @click="emit('open', book)">
    <div class="discover-card__cover">
      <img
        v-if="hasCover(book)"
        :src="book.coverPath"
        :alt="t('book.coverAlt', { title: book.title })"
        class="discover-card__img"
        loading="lazy"
        @error="onCoverError(book.id)"
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
      <span v-if="book.isRead" class="discover-card__read" :title="t('profile.readBadge')">
        <span class="material-symbols-outlined">check_circle</span>
        {{ t('book.read') }}
      </span>
    </div>

    <div class="discover-card__body">
      <h3 class="discover-card__title">{{ book.title }}</h3>
      <p class="discover-card__author">{{ book.author }}</p>

      <p v-if="book.language" class="discover-card__lang">
        <span class="material-symbols-outlined">language</span>
        {{ languageLabel(book.language, book.languageName) }}
      </p>

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
.discover-card--clickable { cursor: pointer; }

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

.discover-card__read {
  position: absolute;
  top: var(--space-base);
  right: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px 3px 6px;
  border-radius: var(--radius-full);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  background: var(--color-primary);
  color: var(--color-on-primary);
  box-shadow: 0 1px 4px rgba(35, 44, 51, 0.18);
}
.discover-card__read .material-symbols-outlined {
  font-size: 13px;
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
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

.discover-card__lang {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--color-secondary);
  margin: 0 0 var(--space-sm);
}
.discover-card__lang .material-symbols-outlined { font-size: 14px; }

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
