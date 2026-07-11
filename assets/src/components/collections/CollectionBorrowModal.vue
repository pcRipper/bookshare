<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

/**
 * Read-only collection preview that doubles as the borrow dialog — the collection
 * equivalent of BookDetailModal. Shows the cover, owner, description and member
 * books; when the viewer isn't the owner it lets them pick a whole/partial set
 * (unavailable or already-requested books are locked) and needs at least two.
 * On the owner's own profile (`isSelf`) it's a pure preview.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  collection: { type: Object, default: null }, // { id, name, description, coverUrl, owner, books[] }
  busy: { type: Boolean, default: false },
  isSelf: { type: Boolean, default: false },
})

const emit = defineEmits(['borrow', 'close'])

const MIN = 2
const selected = ref(new Set())

function isAvailable(book) {
  return book.status === 'own' && !book.requested
}

const books = computed(() => props.collection?.books ?? [])
const availableBooks = computed(() => books.value.filter(isAvailable))
const availableCount = computed(() => availableBooks.value.length)
const canBorrow = computed(() => !props.isSelf && selected.value.size >= MIN)
// Too few available to ever meet the ≥2 rule — explain why borrowing is blocked.
const notEnoughAvailable = computed(() => !props.isSelf && availableCount.value < MIN)

// (Re)seed the selection with every available book each time the modal opens.
watch(
  () => [props.open, props.collection?.id],
  () => {
    if (props.open && props.collection) {
      selected.value = new Set(availableBooks.value.map(b => b.id))
    }
  },
  { immediate: true },
)

function toggle(book) {
  if (props.isSelf || !isAvailable(book)) return
  const next = new Set(selected.value)
  next.has(book.id) ? next.delete(book.id) : next.add(book.id)
  selected.value = next
}

function lockLabel(book) {
  if (book.requested) return 'Requested'
  if (book.status === 'lent') return 'On loan'
  if (book.status === 'currently_reading') return 'Being read'
  if (book.status === 'unavailable') return 'Unavailable'
  return 'Available'
}

function close() {
  if (!props.busy) emit('close')
}
function onBorrow() {
  if (canBorrow.value && !props.busy) emit('borrow', [...selected.value])
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) close()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open && collection" class="modal-overlay" @click.self="close">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="`Collection ${collection.name}`">
        <button class="modal__close" type="button" aria-label="Close" :disabled="busy" @click="close">
          <span class="material-symbols-outlined">close</span>
        </button>

        <div class="modal__content">
          <!-- Cover -->
          <div class="modal__cover">
            <img
              v-if="collection.coverUrl"
              :src="collection.coverUrl"
              :alt="`Cover of ${collection.name}`"
              class="modal__cover-img"
            />
            <div v-else class="modal__cover-placeholder" aria-hidden="true">
              <span class="material-symbols-outlined">library_books</span>
            </div>
          </div>

          <!-- Info -->
          <div class="modal__info">
            <span class="detail-eyebrow">
              <span class="material-symbols-outlined">library_books</span> Collection
            </span>

            <h2 class="detail-title">{{ collection.name }}</h2>

            <RouterLink
              v-if="collection.owner"
              :to="`/profile/${collection.owner.id}`"
              class="detail-owner"
              @click="close"
            >
              <BaseAvatar :src="collection.owner.avatarUrl" :name="collection.owner.fullName" size="sm" />
              <span class="detail-owner__name">{{ collection.owner.fullName }}</span>
            </RouterLink>

            <p class="detail-meta">
              {{ books.length }} {{ books.length === 1 ? 'book' : 'books' }} · {{ availableCount }} available
            </p>

            <section v-if="collection.description" class="detail-about">
              <p class="detail-about__text">{{ collection.description }}</p>
            </section>

            <!-- Books -->
            <h3 class="detail-heading">
              {{ isSelf ? 'Books' : 'Choose books to borrow' }}
            </h3>
            <p v-if="!isSelf" class="detail-sub">Pick at least {{ MIN }} — unavailable books are locked.</p>

            <ul class="book-list">
              <li
                v-for="book in books"
                :key="book.id"
                class="book-row"
                :class="{
                  'book-row--locked': !isAvailable(book),
                  'book-row--selected': !isSelf && selected.has(book.id),
                  'book-row--static': isSelf,
                }"
                @click="toggle(book)"
              >
                <span v-if="!isSelf" class="book-row__check" aria-hidden="true">
                  <span v-if="selected.has(book.id)" class="material-symbols-outlined">check_box</span>
                  <span v-else-if="isAvailable(book)" class="material-symbols-outlined">check_box_outline_blank</span>
                  <span v-else class="material-symbols-outlined">lock</span>
                </span>

                <div class="book-row__cover">
                  <img v-if="book.coverPath" :src="book.coverPath" :alt="`Cover of ${book.title}`" />
                  <span v-else class="material-symbols-outlined">menu_book</span>
                </div>

                <div class="book-row__info">
                  <p class="book-row__title">{{ book.title }}</p>
                  <p class="book-row__author">{{ book.author }}</p>
                </div>

                <span
                  class="book-row__label"
                  :class="{ 'book-row__label--muted': !isAvailable(book) }"
                >{{ lockLabel(book) }}</span>
              </li>
            </ul>
          </div>
        </div>

        <footer class="modal__footer">
          <span
            v-if="!isSelf"
            class="modal__count"
            :class="{ 'modal__count--warn': notEnoughAvailable }"
          >{{ notEnoughAvailable ? `Needs ${MIN}+ available books` : `${selected.size} selected` }}</span>
          <div class="modal__footer-actions">
            <button class="btn-secondary" type="button" :disabled="busy" @click="close">Close</button>
            <button v-if="!isSelf" class="btn-primary" type="button" :disabled="!canBorrow || busy" @click="onBorrow">
              <BaseSpinner v-if="busy" size="sm" />
              <span v-else class="material-symbols-outlined">handshake</span>
              {{ busy ? 'Requesting…' : `Borrow ${selected.size} ${selected.size === 1 ? 'book' : 'books'}` }}
            </button>
          </div>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(48, 49, 46, 0.4);
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
.modal__close:hover:not(:disabled) { background: rgba(35, 44, 51, 0.65); }

.modal__content {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;      /* let the info column own the scroll */
  overflow-y: auto;   /* mobile: the whole stacked body scrolls */
}
@media (min-width: 640px) {
  .modal__content { flex-direction: row; align-items: stretch; overflow: hidden; }
}

/* Mobile: a short banner so a long book list isn't pushed off-screen. */
.modal__cover {
  flex-shrink: 0;
  width: 100%;
  height: 160px;
  background: var(--color-surface-container-low);
  overflow: hidden;
}
@media (min-width: 640px) {
  /* Desktop: fixed side column pinned to the top so it never stretches. */
  .modal__cover { width: 190px; height: auto; align-self: flex-start; aspect-ratio: 2 / 3; }
}
.modal__cover-img { width: 100%; height: 100%; object-fit: cover; }
.modal__cover-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-primary-container) 0%, var(--color-surface-variant) 100%);
}
.modal__cover-placeholder .material-symbols-outlined { font-size: 56px; color: var(--color-primary); opacity: 0.6; }

.modal__info {
  padding: var(--space-lg) var(--space-md) var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
@media (min-width: 640px) {
  .modal__info { flex: 1; min-width: 0; min-height: 0; overflow-y: auto; padding: var(--space-lg); }
}

.detail-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  align-self: flex-start;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-primary);
}
.detail-eyebrow .material-symbols-outlined { font-size: 14px; }

.detail-title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: 1.2;
  color: var(--color-on-background);
  margin: 0;
}

.detail-owner {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  align-self: flex-start;
  color: var(--color-secondary);
}
.detail-owner:hover .detail-owner__name { color: var(--color-primary); }
.detail-owner__name { font-size: var(--text-label-md); font-weight: 500; transition: color 0.15s; }

.detail-meta { font-size: var(--text-label-md); color: var(--color-on-surface-variant); margin: 0; }

.detail-about { margin: 0; }
.detail-about__text {
  margin: 0;
  font-size: var(--text-body-md);
  line-height: 1.55;
  color: var(--color-on-background);
  white-space: pre-line;
}

.detail-heading {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
  margin: var(--space-sm) 0 0;
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}
.detail-sub { font-size: var(--text-label-md); color: var(--color-on-surface-variant); margin: 0; }

.book-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.book-row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xs);
  border: 1px solid var(--color-surface-variant);
  border-radius: var(--radius-default);
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.book-row:hover:not(.book-row--locked):not(.book-row--static) { border-color: var(--color-outline-variant); }
/* Light, low-alpha tint so a selected row stays easy to read from a distance. */
.book-row--selected { border-color: var(--color-primary); background: color-mix(in srgb, var(--color-primary) 12%, transparent); }
.book-row--locked { cursor: not-allowed; opacity: 0.6; }
.book-row--static { cursor: default; }

.book-row__check { display: flex; color: var(--color-primary); }
.book-row--locked .book-row__check { color: var(--color-outline); }
.book-row__check .material-symbols-outlined { font-size: 22px; }

.book-row__cover {
  width: 36px;
  height: 52px;
  flex-shrink: 0;
  background: var(--color-surface-variant);
  border-radius: var(--radius-sm);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-outline);
}
.book-row__cover img { width: 100%; height: 100%; object-fit: cover; }
.book-row__cover .material-symbols-outlined { font-size: 20px; }

.book-row__info { flex: 1; min-width: 0; }
.book-row__title {
  font-size: var(--text-label-md);
  font-weight: 600;
  color: var(--color-on-background);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.book-row__author {
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.book-row__label {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-primary);
}
.book-row__label--muted { color: var(--color-on-surface-variant); }

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}
.modal__count { font-size: var(--text-label-sm); color: var(--color-on-surface-variant); }
.modal__count--warn { color: var(--color-error); font-weight: 600; }
.modal__footer-actions { display: flex; gap: var(--space-sm); margin-left: auto; }

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
.btn-secondary:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  border: 1px solid transparent;
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-size: var(--text-label-md);
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s, opacity 0.2s;
}
.btn-primary .material-symbols-outlined { font-size: 18px; }
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 639px) {
  .modal { max-width: 100%; }
}
</style>
