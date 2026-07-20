<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import api from '@/api'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * Create or edit a collection: cover, name, description, and a two-pane book
 * picker (already-selected vs. books to add, the latter searchable). Save is
 * gated on a name and at least two selected books (matching the server rule).
 * Edit mode carries a Delete action; a collection that's out on loan opens
 * read-only (mirroring the book modal's on-loan lock).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  collection: { type: Object, default: null }, // null ⇒ create
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'delete', 'close'])

const MIN = 2
const toast = useToastStore()

const name = ref('')
const description = ref('')
const coverUrl = ref('')
const selected = ref(new Set())
const bookQuery = ref('')

const books = ref([])          // the owner's books to choose from
const loadingBooks = ref(false)
const pendingAction = ref(null) // 'save' | 'delete' — drives the right spinner

const isEdit = computed(() => !!props.collection)
// A collection that's out on loan is frozen server-side (CollectionVoter).
const readOnly = computed(() => isEdit.value && props.collection?.canEdit === false)
const canSave = computed(() => !readOnly.value && name.value.trim().length > 0 && selected.value.size >= MIN)

// The two panes: what's in the collection vs. what can still be added.
const selectedBooks = computed(() => books.value.filter(b => selected.value.has(b.id)))
const availableBooks = computed(() => {
  const q = bookQuery.value.trim().toLowerCase()
  return books.value.filter(b => {
    if (selected.value.has(b.id)) return false
    if (!q) return true
    return [b.title, b.author, b.isbn].some(f => (f ?? '').toLowerCase().includes(q))
  })
})

// Books of any status can be grouped — only the owner's own books are listed
// (the endpoint is owner-scoped, so borrowed-in books never appear); a member
// that isn't available just can't be borrowed until it's home again. For edit,
// merge in the current members so they can be kept, and pre-select them.
async function loadBooks() {
  loadingBooks.value = true
  try {
    const { data } = await api.get('/books', { params: { perPage: 100 } })
    const byId = new Map(data.items.map(b => [b.id, b]))
    if (isEdit.value) {
      for (const b of props.collection.books ?? []) if (!byId.has(b.id)) byId.set(b.id, b)
    }
    books.value = [...byId.values()]
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Could not load your books.'))
  } finally {
    loadingBooks.value = false
  }
}

// A short badge for members that can't currently be borrowed, so the owner
// knows what state each book is in when building the collection.
const STATUS_LABELS = {
  lent: 'On loan',
  unavailable: 'Unavailable',
  currently_reading: 'Reading',
}
function statusLabel(book) {
  return STATUS_LABELS[book.status] ?? null
}

watch(
  () => props.open,
  open => {
    if (!open) return
    name.value = props.collection?.name ?? ''
    description.value = props.collection?.description ?? ''
    coverUrl.value = props.collection?.coverUrl ?? ''
    selected.value = new Set((props.collection?.books ?? []).map(b => b.id))
    bookQuery.value = ''
    pendingAction.value = null
    loadBooks()
  },
  { immediate: true },
)

function add(book) {
  if (readOnly.value) return
  const next = new Set(selected.value)
  next.add(book.id)
  selected.value = next
}
function remove(book) {
  if (readOnly.value) return
  const next = new Set(selected.value)
  next.delete(book.id)
  selected.value = next
}

function close() {
  if (!props.busy) emit('close')
}
function onSave() {
  if (!canSave.value || props.busy) return
  pendingAction.value = 'save'
  emit('save', {
    name: name.value.trim(),
    description: description.value.trim() || null,
    coverUrl: coverUrl.value.trim() || null,
    bookIds: [...selected.value],
  })
}
function onDelete() {
  if (props.busy) return
  pendingAction.value = 'delete'
  emit('delete', props.collection.id)
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) close()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-overlay" @click.self="close">
      <div class="modal" role="dialog" aria-modal="true">
        <header class="modal__header">
          <div>
            <span class="modal__eyebrow">
              <span class="material-symbols-outlined">library_books</span> Collection
            </span>
            <h2 class="modal__title">{{ isEdit ? 'Edit collection' : 'New collection' }}</h2>
          </div>
          <button class="modal__close" type="button" aria-label="Close" :disabled="busy" @click="close">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <p v-if="readOnly" class="modal__notice">
            <span class="material-symbols-outlined">lock</span>
            This collection is out on loan and can't be edited until it's returned.
          </p>

          <!-- Cover preview + URL (matches the book create/edit modal) -->
          <div class="field">
            <span class="field__label">Cover image URL <span class="field__opt">(optional)</span></span>
            <div class="cover-row">
              <div class="cover-preview">
                <img v-if="coverUrl" :src="coverUrl" alt="Cover preview" />
                <span v-else class="material-symbols-outlined cover-preview__icon">library_books</span>
              </div>
              <input v-model="coverUrl" class="field__input" type="url" maxlength="500" placeholder="https://…" :disabled="busy || readOnly" />
            </div>
          </div>

          <label class="field">
            <span class="field__label">Name</span>
            <input v-model="name" class="field__input" type="text" maxlength="255" placeholder="e.g. The Expanse" :disabled="busy || readOnly" />
          </label>

          <label class="field">
            <span class="field__label">Description <span class="field__opt">(optional)</span></span>
            <textarea v-model="description" class="field__input field__textarea" maxlength="500" rows="2" placeholder="What ties these books together?" :disabled="busy || readOnly" />
          </label>

          <!-- Selected books -->
          <div class="field">
            <span class="field__label">In this collection ({{ selected.size }})</span>
            <p v-if="!selectedBooks.length" class="picker__empty">Pick at least {{ MIN }} books below.</p>
            <ul v-else class="picker">
              <li
                v-for="book in selectedBooks"
                :key="book.id"
                class="picker__row picker__row--selected"
                :class="{ 'picker__row--static': readOnly }"
                @click="remove(book)"
              >
                <span class="picker__check picker__check--remove" aria-hidden="true">
                  <span class="material-symbols-outlined">{{ readOnly ? 'check' : 'remove_circle' }}</span>
                </span>
                <div class="picker__cover">
                  <img v-if="book.coverPath" :src="book.coverPath" :alt="`Cover of ${book.title}`" />
                  <span v-else class="material-symbols-outlined">menu_book</span>
                </div>
                <div class="picker__info">
                  <p class="picker__title">{{ book.title }}</p>
                  <p class="picker__author">{{ book.author }}</p>
                </div>
                <span v-if="statusLabel(book)" class="picker__status">{{ statusLabel(book) }}</span>
              </li>
            </ul>
          </div>

          <!-- Books to add -->
          <div v-if="!readOnly" class="field">
            <span class="field__label">Add books</span>
            <SearchInput
              placeholder="Search your books by title, author or ISBN"
              :debounce="150"
              @search="bookQuery = $event"
            />

            <div v-if="loadingBooks" class="picker__loading">
              <BaseSpinner size="sm" /> Loading your books…
            </div>
            <p v-else-if="!books.length" class="picker__empty">
              You need at least {{ MIN }} books in your library to make a collection.
            </p>
            <p v-else-if="!availableBooks.length" class="picker__empty">
              {{ bookQuery ? 'No books match your search.' : 'Every book is already in this collection.' }}
            </p>
            <ul v-else class="picker">
              <li
                v-for="book in availableBooks"
                :key="book.id"
                class="picker__row"
                @click="add(book)"
              >
                <span class="picker__check" aria-hidden="true">
                  <span class="material-symbols-outlined">add_circle</span>
                </span>
                <div class="picker__cover">
                  <img v-if="book.coverPath" :src="book.coverPath" :alt="`Cover of ${book.title}`" />
                  <span v-else class="material-symbols-outlined">menu_book</span>
                </div>
                <div class="picker__info">
                  <p class="picker__title">{{ book.title }}</p>
                  <p class="picker__author">{{ book.author }}</p>
                </div>
                <span v-if="statusLabel(book)" class="picker__status">{{ statusLabel(book) }}</span>
              </li>
            </ul>
          </div>
        </div>

        <footer class="modal__footer">
          <template v-if="readOnly">
            <div class="modal__footer-actions">
              <button class="btn-secondary" type="button" @click="close">Close</button>
            </div>
          </template>
          <template v-else>
            <button v-if="isEdit" class="btn-delete" type="button" :disabled="busy" @click="onDelete">
              <BaseSpinner v-if="busy && pendingAction === 'delete'" size="sm" />
              <span v-else class="material-symbols-outlined">delete</span>
              {{ busy && pendingAction === 'delete' ? 'Deleting…' : 'Delete' }}
            </button>
            <div class="modal__footer-actions">
              <button class="btn-secondary" type="button" :disabled="busy" @click="close">Cancel</button>
              <button class="btn-primary" type="button" :disabled="!canSave || busy" @click="onSave">
                <BaseSpinner v-if="busy && pendingAction === 'save'" size="sm" />
                {{ busy && pendingAction === 'save' ? 'Saving…' : isEdit ? 'Save changes' : 'Create collection' }}
              </button>
            </div>
          </template>
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

.modal__body {
  padding: 0 var(--space-md) var(--space-sm);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.modal__notice {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin: 0;
  padding: var(--space-sm) var(--space-base);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  font-size: var(--text-label-md);
}
.modal__notice .material-symbols-outlined { font-size: 18px; }

.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.field__opt { text-transform: none; font-weight: 400; opacity: 0.7; }
.field__input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
}
.field__input:focus { outline: none; border-color: var(--color-primary); }
.field__input:disabled { opacity: 0.6; }
.field__textarea { resize: vertical; }

/* Cover preview + URL row (consistent with the book create/edit modal). */
.cover-row { display: flex; gap: var(--space-sm); align-items: center; }
.cover-preview {
  width: 56px;
  height: 80px;
  flex-shrink: 0;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  overflow: hidden;
  background: var(--color-surface-container-low);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-outline);
}
.cover-preview img { width: 100%; height: 100%; object-fit: cover; }
.cover-preview__icon { font-size: 24px; }
.cover-row .field__input { flex: 1; min-width: 0; }

.picker__loading,
.picker__empty {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
  padding: var(--space-xs) 0;
  margin: 0;
}

.picker {
  list-style: none;
  margin: var(--space-xs) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  max-height: 220px;
  overflow-y: auto;
}
.picker__row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xs);
  border: 1px solid var(--color-surface-variant);
  border-radius: var(--radius-default);
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.picker__row:hover { border-color: var(--color-outline-variant); }
/* A light, low-alpha tint so selected rows stay easy to read from a distance. */
.picker__row--selected {
  border-color: var(--color-primary);
  background: color-mix(in srgb, var(--color-primary) 12%, transparent);
}
.picker__row--static { cursor: default; }
.picker__check { display: flex; color: var(--color-primary); }
.picker__check--remove { color: var(--color-error); }
.picker__row--static .picker__check--remove { color: var(--color-primary); }
.picker__check .material-symbols-outlined { font-size: 22px; }
.picker__cover {
  width: 32px;
  height: 46px;
  flex-shrink: 0;
  background: var(--color-surface-variant);
  border-radius: var(--radius-sm);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-outline);
}
.picker__cover img { width: 100%; height: 100%; object-fit: cover; }
.picker__cover .material-symbols-outlined { font-size: 18px; }
.picker__info { flex: 1; min-width: 0; }
.picker__title {
  font-size: var(--text-label-md);
  font-weight: 600;
  color: var(--color-on-background);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.picker__author {
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* Marks a member that can't currently be borrowed (on loan / unavailable / reading). */
.picker__status {
  flex-shrink: 0;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  font-size: var(--text-label-sm);
  font-weight: 600;
  white-space: nowrap;
}

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}
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
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-delete {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-sm) 0;
  background: transparent;
  color: var(--color-error);
  font-size: var(--text-label-md);
  font-weight: 500;
  cursor: pointer;
}
.btn-delete:hover:not(:disabled) { text-decoration: underline; }
.btn-delete:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-delete .material-symbols-outlined { font-size: 20px; }

@media (max-width: 639px) {
  .modal { max-width: 100%; }
}
</style>
