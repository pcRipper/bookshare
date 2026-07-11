<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

/**
 * Borrow a whole collection or a partial selection of it. Unavailable (or
 * already-requested) books are locked; you must pick at least two available ones.
 * Available books are pre-selected so "borrow everything" is one click.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  collection: { type: Object, default: null }, // { id, name, books: [{...status, requested}] }
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['borrow', 'close'])

const MIN = 2
const selected = ref(new Set())

function isAvailable(book) {
  return book.status === 'own' && !book.requested
}

const availableBooks = computed(() => (props.collection?.books ?? []).filter(isAvailable))
const canBorrow = computed(() => selected.value.size >= MIN)

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
  if (!isAvailable(book)) return
  const next = new Set(selected.value)
  next.has(book.id) ? next.delete(book.id) : next.add(book.id)
  selected.value = next
}

function lockLabel(book) {
  if (book.requested) return 'Requested'
  if (book.status === 'lent') return 'On loan'
  if (book.status === 'currently_reading') return 'Being read'
  return 'Unavailable'
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
      <div class="modal" role="dialog" aria-modal="true" :aria-label="`Borrow from ${collection.name}`">
        <header class="modal__header">
          <div>
            <span class="modal__eyebrow">
              <span class="material-symbols-outlined">library_books</span> Collection
            </span>
            <h2 class="modal__title">Borrow “{{ collection.name }}”</h2>
          </div>
          <button class="modal__close" type="button" aria-label="Close" :disabled="busy" @click="close">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <p class="modal__hint">
          Pick the books you'd like to borrow — at least {{ MIN }}. Unavailable books can't be selected.
        </p>

        <ul class="book-list">
          <li
            v-for="book in collection.books"
            :key="book.id"
            class="book-row"
            :class="{ 'book-row--locked': !isAvailable(book), 'book-row--selected': selected.has(book.id) }"
            @click="toggle(book)"
          >
            <span class="book-row__check" aria-hidden="true">
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

            <span v-if="!isAvailable(book)" class="book-row__lock-label">{{ lockLabel(book) }}</span>
          </li>
        </ul>

        <footer class="modal__footer">
          <span class="modal__count">{{ selected.size }} selected</span>
          <div class="modal__footer-actions">
            <button class="btn-secondary" type="button" :disabled="busy" @click="close">Cancel</button>
            <button class="btn-primary" type="button" :disabled="!canBorrow || busy" @click="onBorrow">
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
  max-width: 520px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.modal__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-md) var(--space-md) var(--space-sm);
}
.modal__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-primary);
}
.modal__eyebrow .material-symbols-outlined { font-size: 14px; }
.modal__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: 1.2;
  color: var(--color-on-background);
  margin: 2px 0 0;
}
.modal__close {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  color: var(--color-on-surface-variant);
  transition: background 0.2s;
}
.modal__close:hover:not(:disabled) { background: var(--color-surface-container-low); }

.modal__hint {
  padding: 0 var(--space-md) var(--space-sm);
  margin: 0;
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}

.book-list {
  list-style: none;
  margin: 0;
  padding: 0 var(--space-md);
  overflow-y: auto;
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
.book-row:hover:not(.book-row--locked) { border-color: var(--color-outline-variant); }
.book-row--selected {
  border-color: var(--color-primary);
  background: var(--color-primary-container);
}
.book-row--locked { cursor: not-allowed; opacity: 0.6; }

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
.book-row__lock-label {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-on-surface-variant);
}

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-md);
  margin-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}
.modal__count {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.modal__footer-actions { display: flex; gap: var(--space-sm); }

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
