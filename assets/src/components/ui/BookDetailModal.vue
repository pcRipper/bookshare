<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import StarRating from '@/components/ui/StarRating.vue'
import ModalTabs from '@/components/ui/ModalTabs.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'
import { languageLabel } from '@/utils/languages'
import { wishPriorityMeta, wishPriorityKey } from '@/utils/wishPriority'
import { useCoverFallback } from '@/composables/useCoverFallback'

const { t } = useI18n()

/**
 * Book overview. Opens from browse surfaces (Discover, the Following feed, other
 * readers' profiles) and from the owner's own profile, where it doubles as the
 * place a review is written. Editing the *book* still lives in /library.
 *
 * The full description reads top-to-bottom in normal flow (the reason this modal
 * exists — the old hover overlay clipped the start of long blurbs).
 *
 * Two tabs over the info column, so the cover stays put while the right side
 * swaps. The **Review** tab is editable only under `canReview`, which is a
 * separate prop and deliberately NOT `isSelf`: the public share page passes
 * `is-self` to mean "no borrow button", so gating an editor on it would hand one
 * to signed-out visitors. For everyone else the tab is read-only, and it is not
 * rendered at all when there is nothing to read — a dead tab is worse than none.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  book: { type: Object, default: null },
  // Parent-controlled: true while this book's borrow request is in flight.
  pending: { type: Boolean, default: false },
  // When the viewer owns this book (own profile) there's no borrow action —
  // the footer shows only Close and the modal is a pure preview. Also true on
  // the signed-out share page, where it means the same thing for a different
  // reason — which is why it must never gate the review editor.
  isSelf: { type: Boolean, default: false },
  // The viewer is this book's owner *and* signed in: the Review tab is a form.
  canReview: { type: Boolean, default: false },
  // Parent-controlled: true while a review save is in flight.
  savingReview: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'request', 'save-review'])

const REVIEW_MAX = 1000

const activeTab = ref('details')
const draft = ref({ rating: null, review: '' })

const hasReview = computed(() => !!props.book?.rating || !!props.book?.review?.trim())
// Read-only viewers get the tab only when there is something behind it.
const showReviewTab = computed(() => props.canReview || hasReview.value)

const tabs = computed(() => [
  { key: 'details', label: t('bookDetail.tabDetails') },
  { key: 'review', label: t('bookDetail.tabReview') },
])

const reviewRemaining = computed(() => REVIEW_MAX - (draft.value.review?.length ?? 0))

// Re-seed the draft (and go back to Details) whenever the modal opens on a book:
// the same modal instance is reused for every card in a list.
watch(
  () => [props.open, props.book?.id],
  () => {
    if (!props.open) return
    activeTab.value = 'details'
    draft.value = { rating: props.book?.rating ?? null, review: props.book?.review ?? '' }
  },
  { immediate: true },
)

function onSaveReview() {
  const review = draft.value.review.trim()
  emit('save-review', {
    id: props.book.id,
    rating: draft.value.rating,
    review: review !== '' ? review : null,
  })
}

const { hasCover, onCoverError } = useCoverFallback()

const hasDescription = computed(() => !!props.book?.description?.trim())

// A wish-list book has no lending state — its owner doesn't have it — so the
// pill carries the priority instead and the borrow action is withheld below.
// Read off the book rather than taken as a prop, the same call BorrowBookCard
// makes: the shelf is a property of the book, not of the surface showing it.
const wishMeta = computed(() => props.book?.isWished ? wishPriorityMeta(props.book.wishPriority) : null)

// Status pill — a compact read of the book's availability.
const statusPill = computed(() => {
  if (props.book?.isWished) {
    return { label: t(wishPriorityKey(props.book.wishPriority)), tone: 'muted' }
  }
  switch (props.book?.status) {
    case 'own':               return { label: t('bookDetail.status.own'), tone: 'available' }
    case 'lent':              return { label: t('bookDetail.status.lent'), tone: 'muted' }
    case 'currently_reading': return { label: t('bookDetail.status.reading'), tone: 'muted' }
    case 'unavailable':       return { label: t('bookDetail.status.unavailable'), tone: 'muted' }
    default:                  return null
  }
})

// Footer action — mirrors the card button states (see DiscoverBookCard).
const action = computed(() => {
  if (props.book?.requested) return { label: t('profile.requested'), state: 'requested' }
  if (props.book?.status === 'own') return { label: t('profile.requestToBorrow'), state: 'available' }
  const label = props.book?.status === 'lent' ? t('profile.currentlyLent')
    : props.book?.status === 'currently_reading' ? t('book.status.reading')
    : t('book.status.unavailable')
  return { label, state: 'disabled' }
})

function close() {
  emit('close')
}

function onRequest() {
  if (action.value.state === 'available' && !props.pending) emit('request', props.book.id)
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) close()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open && book" class="modal-overlay" @click.self="close">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="t('bookDetail.aria', { title: book.title })">
        <button class="modal__close" type="button" :aria-label="t('common.close')" @click="close">
          <span class="material-symbols-outlined">close</span>
        </button>

        <div class="modal__content">
          <!-- Cover -->
          <div class="modal__cover">
            <img
              v-if="hasCover(book)"
              :src="book.coverPath"
              :alt="t('book.coverAlt', { title: book.title })"
              class="modal__cover-img"
              @error="onCoverError(book.id)"
            />
            <div v-else class="modal__cover-placeholder" aria-hidden="true">
              <span class="material-symbols-outlined">menu_book</span>
            </div>
          </div>

          <!-- Info (scrolls independently on desktop) -->
          <div class="modal__info">
            <ModalTabs
              v-if="showReviewTab"
              v-model="activeTab"
              :items="tabs"
              :aria-label="t('bookDetail.aria', { title: book.title })"
            />

            <div v-show="!showReviewTab || activeTab === 'details'" class="detail-panel">
            <div v-if="statusPill || book.isRead" class="detail-pills">
              <span
                v-if="statusPill"
                class="detail-status"
                :class="wishMeta ? `detail-status--wish-${wishMeta.tone}` : `detail-status--${statusPill.tone}`"
              >
                {{ statusPill.label }}
              </span>
              <span v-if="book.isRead" class="detail-status detail-status--read">
                <span class="material-symbols-outlined">check_circle</span>
                {{ t('book.read') }}
              </span>
            </div>

            <h2 class="detail-title">{{ book.title }}</h2>
            <p class="detail-author">{{ t('bookDetail.byAuthor', { author: book.author }) }}</p>

            <!-- The stars stay on Details as a fact about the book; the words
                 (and the form) live one tab over. -->
            <p v-if="book.rating" class="detail-rating">
              <StarRating :model-value="book.rating" variant="stars" size="lg" />
              <button
                v-if="showReviewTab && book.review"
                class="detail-rating__link"
                type="button"
                @click="activeTab = 'review'"
              >{{ t('bookDetail.tabReview') }}</button>
              <span v-else class="detail-rating__label">{{ t('book.rating') }}</span>
            </p>

            <RouterLink
              v-if="book.owner"
              :to="`/profile/${book.owner.id}`"
              class="detail-owner"
              @click="close"
            >
              <BaseAvatar :src="book.owner.avatarUrl" :name="book.owner.fullName" size="sm" />
              <span class="detail-owner__name">{{ book.owner.fullName }}</span>
            </RouterLink>

            <dl v-if="book.language || book.isbn" class="detail-meta">
              <div v-if="book.language" class="detail-meta__row">
                <dt><span class="material-symbols-outlined">language</span> {{ t('table.language') }}</dt>
                <dd>{{ languageLabel(book.language, book.languageName) }}</dd>
              </div>
              <div v-if="book.isbn" class="detail-meta__row">
                <dt><span class="material-symbols-outlined">qr_code_2</span> {{ t('table.isbn') }}</dt>
                <dd>{{ book.isbn }}</dd>
              </div>
            </dl>

            <ul v-if="book.categories?.length" class="detail-categories">
              <li v-for="cat in book.categories" :key="cat.id">
                <CategoryTag :label="cat.name" :color="cat.colorHex" />
              </li>
            </ul>

            <section class="detail-about">
              <h3 class="detail-about__heading">{{ t('bookDetail.about') }}</h3>
              <p v-if="hasDescription" class="detail-about__text">{{ book.description }}</p>
              <p v-else class="detail-about__empty">{{ t('bookDetail.noDescription') }}</p>
            </section>
            </div>

            <!-- Review: a form for the owner, prose for everyone else. -->
            <div v-if="showReviewTab" v-show="activeTab === 'review'" class="detail-panel">
              <template v-if="canReview">
                <div class="review-field">
                  <span class="review-field__label">{{ t('book.rating') }}</span>
                  <StarRating v-model="draft.rating" editable size="lg" :disabled="savingReview" />
                </div>

                <div class="review-field">
                  <label class="review-field__label" for="bd-review">{{ t('bookDetail.tabReview') }}</label>
                  <textarea
                    id="bd-review"
                    v-model="draft.review"
                    class="review-field__text"
                    rows="6"
                    :maxlength="REVIEW_MAX"
                    :disabled="savingReview"
                    :placeholder="t('bookDetail.reviewPlaceholder')"
                  ></textarea>
                  <span class="review-field__counter">{{ reviewRemaining }}</span>
                </div>

                <button class="btn-save-review" type="button" :disabled="savingReview" @click="onSaveReview">
                  <BaseSpinner v-if="savingReview" size="sm" />
                  {{ t('bookDetail.saveReview') }}
                </button>
              </template>

              <template v-else>
                <StarRating v-if="book.rating" :model-value="book.rating" variant="stars" size="lg" />
                <p v-if="book.review" class="review-text">{{ book.review }}</p>
                <p v-else class="detail-about__empty">{{ t('bookDetail.noReview') }}</p>
                <!-- Named only where the payload carries an owner (Discover):
                     on a profile the whole page already says whose shelf it is. -->
                <p v-if="book.owner" class="review-byline">
                  {{ t('bookDetail.reviewBy', { name: book.owner.fullName }) }}
                </p>
              </template>
            </div>
          </div>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" @click="close">{{ t('common.close') }}</button>
          <!-- Your own book has no borrow affordance, and neither does a
               wish-list one: its owner hasn't got it to lend (the API rejects
               such a request outright — see LibraryRequestService::create). -->
          <button
            v-if="!isSelf && !book.isWished"
            class="btn-request"
            :class="`btn-request--${action.state}`"
            type="button"
            :disabled="action.state !== 'available' || pending"
            @click="onRequest"
          >
            <BaseSpinner v-if="pending" size="sm" />
            <span v-else-if="action.state === 'available'" class="material-symbols-outlined">handshake</span>
            <span v-else-if="action.state === 'requested'" class="material-symbols-outlined">check</span>
            {{ pending ? t('profile.requesting') : action.label }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(48, 49, 46, 0.4);   /* inverse-surface @ 40% */
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--modal-gutter);
  z-index: 100;
}

.modal {
  position: relative;
  background: var(--color-surface-container-lowest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.12);
  width: 100%;
  max-width: var(--modal-w-xl);
  max-height: var(--modal-max-h);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.modal__close {
  position: absolute;
  top: var(--space-sm);
  right: var(--space-sm);
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  background: rgba(35, 44, 51, 0.45);
  color: #fff;
  backdrop-filter: blur(2px);
  transition: background 0.2s;
}
.modal__close:hover { background: rgba(35, 44, 51, 0.65); }

/* Content: stacked on mobile, cover + info side-by-side on desktop. */
.modal__content {
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}
@media (min-width: 640px) {
  .modal__content {
    flex-direction: row;
    align-items: stretch;
    overflow: hidden;   /* the info column owns the scroll on desktop */
  }
}

/* Mobile: a centred plate rather than a full-width one. At the sheet's whole
   width a 2:3 cover is ~500px tall — four fifths of the modal — so the title,
   metadata and the footer all start below the fold. Narrowing it (rather than
   capping its height) halves that while still showing the *whole* cover; a
   max-height would crop the art to a band. */
.modal__cover {
  flex-shrink: 0;
  width: 55%;
  margin: 0 auto;
  aspect-ratio: 2 / 3;
  background: var(--color-surface-container-low);
  overflow: hidden;
}
@media (min-width: 640px) {
  .modal__cover { width: 220px; margin: 0; aspect-ratio: auto; }
}
/* The wider modal would otherwise leave the cover looking like a thumbnail
   pinned to a large sheet — grow it in step so the proportion holds. */
@media (min-width: 768px) {
  .modal__cover { width: 300px; }
}
.modal__cover-img { width: 100%; height: 100%; object-fit: cover; }
.modal__cover-placeholder {
  width: 100%;
  height: 100%;
  min-height: 220px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.modal__cover-placeholder .material-symbols-outlined {
  font-size: 56px;
  color: var(--color-outline);
  opacity: 0.5;
}

.modal__info {
  padding: var(--space-lg) var(--space-md) var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
@media (min-width: 640px) {
  .modal__info {
    flex: 1;
    min-width: 0;
    overflow-y: auto;
    padding: var(--space-lg);
  }
}

.detail-pills {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
}
.detail-status {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.detail-status .material-symbols-outlined {
  font-size: 13px;
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
}
.detail-status--read {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
.detail-status--available {
  background: var(--color-primary-container);
  color: var(--color-on-primary-container);
}
/* Wish-list priority, the same traffic light the cards use. */
.detail-status--wish-green { background: var(--color-primary); color: var(--color-on-primary); }
.detail-status--wish-amber { background: var(--color-tertiary); color: #ffffff; }
.detail-status--wish-red { background: var(--color-error); color: #ffffff; }

.detail-status--muted {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}

.detail-title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: 1.2;
  color: var(--color-on-background);
  margin: 0;
}
.detail-author {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  margin: 0;
}

.detail-rating {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  margin: 0;
}
.detail-rating__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  text-transform: uppercase;
  color: var(--color-secondary);
}

.detail-owner {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  align-self: flex-start;
  margin-top: 2px;
  color: var(--color-secondary);
}
.detail-owner:hover .detail-owner__name { color: var(--color-primary); }
.detail-owner__name {
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: color 0.15s;
}

.detail-meta {
  margin: var(--space-xs) 0 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.detail-meta__row {
  display: flex;
  align-items: baseline;
  gap: var(--space-sm);
}
.detail-meta__row dt {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 96px;
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.detail-meta__row dt .material-symbols-outlined { font-size: 15px; }
.detail-meta__row dd {
  margin: 0;
  font-size: var(--text-body-md);
  color: var(--color-on-background);
}

.detail-categories {
  list-style: none;
  margin: var(--space-xs) 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
}

.detail-about {
  margin-top: var(--space-sm);
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}
.detail-about__heading {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
  margin: 0 0 var(--space-xs);
}
.detail-about__text {
  margin: 0;
  font-size: var(--text-body-md);
  line-height: 1.55;
  color: var(--color-on-background);
  white-space: pre-line;   /* honour author line breaks; wraps normally */
}
.detail-about__empty {
  margin: 0;
  font-size: var(--text-body-md);
  font-style: italic;
  color: var(--color-secondary);
}

.detail-panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.detail-rating__link {
  background: none;
  border: 0;
  padding: 0;
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  text-transform: uppercase;
  color: var(--color-primary);
  text-decoration: underline;
  cursor: pointer;
}

.review-field { display: flex; flex-direction: column; gap: var(--space-xs); }
.review-field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.review-field__text {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  line-height: 1.5;
  resize: vertical;
  min-height: 120px;
}
.review-field__text:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: -1px;
  border-color: var(--color-primary);
}
.review-field__counter {
  align-self: flex-end;
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
}

.btn-save-review {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 20px;
  border: 0;
  border-radius: var(--radius-default);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 600;
  cursor: pointer;
}
.btn-save-review:disabled { opacity: 0.7; cursor: default; }

.review-text {
  margin: 0;
  font-size: var(--text-body-md);
  line-height: 1.55;
  color: var(--color-on-background);
  white-space: pre-line;   /* honour the writer's line breaks */
}
.review-byline {
  margin: 0;
  font-size: var(--text-label-sm);
  font-style: italic;
  color: var(--color-secondary);
}

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}

.btn-secondary {
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  border: 1px solid var(--color-outline-variant);
  background: transparent;
  color: var(--color-on-background);
  font-size: var(--text-label-md);
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s;
}
.btn-secondary:hover { background: var(--color-surface-container-low); }

.btn-request {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  border: 1px solid transparent;
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.btn-request .material-symbols-outlined { font-size: 18px; }
.btn-request--available {
  background: var(--color-primary);
  color: var(--color-on-primary);
  cursor: pointer;
}
.btn-request--available:hover { background: var(--color-primary-container); }
.btn-request--available:active { transform: scale(0.98); }
.btn-request--requested {
  background: var(--color-primary-fixed);
  color: var(--color-on-primary-fixed-variant);
  cursor: default;
}
.btn-request--disabled {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  cursor: not-allowed;
}

@media (max-width: 639px) {
  .modal { max-width: 100%; }
}
</style>
