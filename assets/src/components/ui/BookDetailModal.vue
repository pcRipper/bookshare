<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'

/**
 * Read-only book overview. Opens from browse surfaces (Discover, the Following
 * feed, other readers' profiles) where a click can't edit the book — never from
 * the owner's own library/profile, where a click opens the Manage Book modal.
 *
 * The full description reads top-to-bottom in normal flow (the reason this modal
 * exists — the old hover overlay clipped the start of long blurbs).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  book: { type: Object, default: null },
  // Parent-controlled: true while this book's borrow request is in flight.
  pending: { type: Boolean, default: false },
  // When the viewer owns this book (own profile) there's no borrow action —
  // the footer shows only Close and the modal is a pure preview.
  isSelf: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'request'])

const hasDescription = computed(() => !!props.book?.description?.trim())

// Status pill — a compact read of the book's availability.
const statusPill = computed(() => {
  switch (props.book?.status) {
    case 'own':               return { label: 'Available', tone: 'available' }
    case 'lent':              return { label: 'On loan', tone: 'muted' }
    case 'currently_reading': return { label: 'Being read', tone: 'muted' }
    case 'unavailable':       return { label: 'Unavailable', tone: 'muted' }
    default:                  return null
  }
})

// Footer action — mirrors the card button states (see DiscoverBookCard).
const action = computed(() => {
  if (props.book?.requested) return { label: 'Requested', state: 'requested' }
  if (props.book?.status === 'own') return { label: 'Request to Borrow', state: 'available' }
  const label = props.book?.status === 'lent' ? 'Currently Lent'
    : props.book?.status === 'currently_reading' ? 'Reading'
    : 'Unavailable'
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
      <div class="modal" role="dialog" aria-modal="true" :aria-label="`Details for ${book.title}`">
        <button class="modal__close" type="button" aria-label="Close" @click="close">
          <span class="material-symbols-outlined">close</span>
        </button>

        <div class="modal__content">
          <!-- Cover -->
          <div class="modal__cover">
            <img
              v-if="book.coverPath"
              :src="book.coverPath"
              :alt="`Cover of ${book.title}`"
              class="modal__cover-img"
            />
            <div v-else class="modal__cover-placeholder" aria-hidden="true">
              <span class="material-symbols-outlined">menu_book</span>
            </div>
          </div>

          <!-- Info (scrolls independently on desktop) -->
          <div class="modal__info">
            <span v-if="statusPill" class="detail-status" :class="`detail-status--${statusPill.tone}`">
              {{ statusPill.label }}
            </span>

            <h2 class="detail-title">{{ book.title }}</h2>
            <p class="detail-author">by {{ book.author }}</p>

            <RouterLink
              v-if="book.owner"
              :to="`/profile/${book.owner.id}`"
              class="detail-owner"
              @click="close"
            >
              <BaseAvatar :src="book.owner.avatarUrl" :name="book.owner.fullName" size="sm" />
              <span class="detail-owner__name">{{ book.owner.fullName }}</span>
            </RouterLink>

            <dl v-if="book.languageName || book.isbn" class="detail-meta">
              <div v-if="book.languageName" class="detail-meta__row">
                <dt><span class="material-symbols-outlined">language</span> Language</dt>
                <dd>{{ book.languageName }}</dd>
              </div>
              <div v-if="book.isbn" class="detail-meta__row">
                <dt><span class="material-symbols-outlined">qr_code_2</span> ISBN</dt>
                <dd>{{ book.isbn }}</dd>
              </div>
            </dl>

            <ul v-if="book.categories?.length" class="detail-categories">
              <li v-for="cat in book.categories" :key="cat.id">
                <CategoryTag :label="cat.name" :color="cat.colorHex" />
              </li>
            </ul>

            <section class="detail-about">
              <h3 class="detail-about__heading">About this book</h3>
              <p v-if="hasDescription" class="detail-about__text">{{ book.description }}</p>
              <p v-else class="detail-about__empty">No description has been added for this book.</p>
            </section>
          </div>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" @click="close">Close</button>
          <button
            v-if="!isSelf"
            class="btn-request"
            :class="`btn-request--${action.state}`"
            type="button"
            :disabled="action.state !== 'available' || pending"
            @click="onRequest"
          >
            <BaseSpinner v-if="pending" size="sm" />
            <span v-else-if="action.state === 'available'" class="material-symbols-outlined">handshake</span>
            <span v-else-if="action.state === 'requested'" class="material-symbols-outlined">check</span>
            {{ pending ? 'Requesting…' : action.label }}
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
  padding: var(--space-md);
  z-index: 100;
}

.modal {
  position: relative;
  background: var(--color-surface-container-lowest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.12);
  width: 100%;
  max-width: 640px;
  max-height: 90vh;
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

.modal__cover {
  flex-shrink: 0;
  aspect-ratio: 2 / 3;
  background: var(--color-surface-container-low);
  overflow: hidden;
}
@media (min-width: 640px) {
  .modal__cover { width: 220px; aspect-ratio: auto; }
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

.detail-status {
  align-self: flex-start;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.detail-status--available {
  background: var(--color-primary-container);
  color: var(--color-on-primary-container);
}
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
