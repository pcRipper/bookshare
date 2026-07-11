<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import api from '@/api'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * Create or edit a collection: name, optional description + cover URL, and a
 * multi-select of the owner's books. Save is gated on a name and at least two
 * selected books (matching the server rule).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  collection: { type: Object, default: null }, // null ⇒ create
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'close'])

const MIN = 2
const toast = useToastStore()

const name = ref('')
const description = ref('')
const coverUrl = ref('')
const selected = ref(new Set())

const books = ref([])          // the owner's books to choose from
const loadingBooks = ref(false)

const isEdit = computed(() => !!props.collection)
const canSave = computed(() => name.value.trim().length > 0 && selected.value.size >= MIN)

// Load the owner's available books to pick from; for edit, merge in the current
// members (which may now be on loan) so they can be kept, and pre-select them.
async function loadBooks() {
  loadingBooks.value = true
  try {
    const { data } = await api.get('/books', { params: { status: 'own', perPage: 100 } })
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

watch(
  () => props.open,
  open => {
    if (!open) return
    name.value = props.collection?.name ?? ''
    description.value = props.collection?.description ?? ''
    coverUrl.value = props.collection?.coverUrl ?? ''
    selected.value = new Set((props.collection?.books ?? []).map(b => b.id))
    loadBooks()
  },
  { immediate: true },
)

function toggle(book) {
  const next = new Set(selected.value)
  next.has(book.id) ? next.delete(book.id) : next.add(book.id)
  selected.value = next
}

function close() {
  if (!props.busy) emit('close')
}

function onSave() {
  if (!canSave.value || props.busy) return
  emit('save', {
    name: name.value.trim(),
    description: description.value.trim() || null,
    coverUrl: coverUrl.value.trim() || null,
    bookIds: [...selected.value],
  })
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
          <label class="field">
            <span class="field__label">Name</span>
            <input v-model="name" class="field__input" type="text" maxlength="255" placeholder="e.g. The Expanse" :disabled="busy" />
          </label>

          <label class="field">
            <span class="field__label">Description <span class="field__opt">(optional)</span></span>
            <textarea v-model="description" class="field__input field__textarea" maxlength="500" rows="2" placeholder="What ties these books together?" :disabled="busy" />
          </label>

          <label class="field">
            <span class="field__label">Cover image URL <span class="field__opt">(optional)</span></span>
            <input v-model="coverUrl" class="field__input" type="url" maxlength="500" placeholder="https://…" :disabled="busy" />
          </label>

          <div class="field">
            <span class="field__label">
              Books <span class="field__opt">(pick at least {{ MIN }})</span>
            </span>

            <div v-if="loadingBooks" class="picker__loading">
              <BaseSpinner size="sm" /> Loading your books…
            </div>
            <p v-else-if="!books.length" class="picker__empty">
              You need at least {{ MIN }} books in your library to make a collection.
            </p>
            <ul v-else class="picker">
              <li
                v-for="book in books"
                :key="book.id"
                class="picker__row"
                :class="{ 'picker__row--selected': selected.has(book.id) }"
                @click="toggle(book)"
              >
                <span class="picker__check" aria-hidden="true">
                  <span class="material-symbols-outlined">{{ selected.has(book.id) ? 'check_box' : 'check_box_outline_blank' }}</span>
                </span>
                <div class="picker__cover">
                  <img v-if="book.coverPath" :src="book.coverPath" :alt="`Cover of ${book.title}`" />
                  <span v-else class="material-symbols-outlined">menu_book</span>
                </div>
                <div class="picker__info">
                  <p class="picker__title">{{ book.title }}</p>
                  <p class="picker__author">{{ book.author }}</p>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <footer class="modal__footer">
          <span class="modal__count">{{ selected.size }} selected</span>
          <div class="modal__footer-actions">
            <button class="btn-secondary" type="button" :disabled="busy" @click="close">Cancel</button>
            <button class="btn-primary" type="button" :disabled="!canSave || busy" @click="onSave">
              <BaseSpinner v-if="busy" size="sm" />
              {{ busy ? 'Saving…' : isEdit ? 'Save changes' : 'Create collection' }}
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

.modal__body {
  padding: 0 var(--space-md) var(--space-sm);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

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

.picker__loading,
.picker__empty {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
  padding: var(--space-sm) 0;
}

.picker {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  max-height: 240px;
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
.picker__row--selected { border-color: var(--color-primary); background: var(--color-primary-container); }
.picker__check { display: flex; color: var(--color-primary); }
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

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}
.modal__count { font-size: var(--text-label-sm); color: var(--color-on-surface-variant); }
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
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 639px) {
  .modal { max-width: 100%; }
}
</style>
