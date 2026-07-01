<script setup>
import { ref, watch, computed } from 'vue'
import CategorySelector from '@/components/library/CategorySelector.vue'
import BookTemplateSearch from '@/components/library/BookTemplateSearch.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  open:  { type: Boolean, default: false },
  book:  { type: Object, default: null },   // null = create mode
  // Parent-controlled: true while a save/delete request is in flight.
  busy:  { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'delete', 'close'])

// Statuses an owner may pick by hand. 'lent' is intentionally absent: it's set
// only by the lending lifecycle (approve), never chosen manually — doing so would
// flag a book as on-loan while it still sits home. It's still shown (read-only)
// when viewing a lent book, via `statusOptions` below.
const SELECTABLE_STATUSES = [
  { value: 'own',               label: 'Available' },
  { value: 'currently_reading', label: 'Currently reading' },
  { value: 'unavailable',       label: 'Unavailable' },
]

// Matches BookInput's Assert\Length(max: 500) on description.
const DESC_MAX = 500

const form = ref(blank())
// Which action the parent is currently processing — drives the right spinner.
const pendingAction = ref(null) // 'save' | 'delete' | null
const errorMsg = ref(null)
// Create mode only: 'manual' form vs. 'template' search. Reset on every open.
const activeTab = ref('manual')

const isEdit = computed(() => !!props.book)

// A book that's out on loan is locked server-side (BookVoter): show its details
// but block any mutation until it's returned. `canEdit` comes from the API.
const readOnly = computed(() => isEdit.value && props.book?.canEdit === false)

// A lent book opens read-only; surface its 'Lent out' value so the (disabled)
// dropdown renders correctly without offering it as a manual choice elsewhere.
const statusOptions = computed(() =>
  form.value.status === 'lent'
    ? [{ value: 'lent', label: 'Lent out' }, ...SELECTABLE_STATUSES]
    : SELECTABLE_STATUSES,
)

const descRemaining = computed(() => DESC_MAX - form.value.description.length)

function blank() {
  // categories: array of { id, name, colorHex }
  return { title: '', author: '', description: '', isbn: '', status: 'own', language: null, coverPath: '', categories: [] }
}

// Repopulate whenever the modal opens or the target book changes.
watch(
  () => [props.open, props.book],
  () => {
    if (!props.open) return
    errorMsg.value = null
    pendingAction.value = null
    activeTab.value = 'manual'
    form.value = props.book
      ? {
          title: props.book.title ?? '',
          author: props.book.author ?? '',
          description: props.book.description ?? '',
          isbn: props.book.isbn ?? '',
          status: props.book.status ?? 'own',
          language: props.book.language ?? null,
          coverPath: props.book.coverPath ?? '',
          categories: [...(props.book.categories ?? [])],
        }
      : blank()
  },
  { immediate: true },
)

function onSave() {
  if (readOnly.value) return
  if (!form.value.title.trim() || !form.value.author.trim()) {
    errorMsg.value = 'Title and author are required.'
    return
  }
  errorMsg.value = null
  pendingAction.value = 'save'
  // The parent performs the request and toggles `busy`; it closes the modal
  // on success, which resets `pendingAction` via the open watcher.
  emit('save', {
    title: form.value.title.trim(),
    author: form.value.author.trim(),
    description: form.value.description.trim() || null,
    isbn: form.value.isbn.trim() || null,
    status: form.value.status,
    language: form.value.language || null,
    coverPath: form.value.coverPath.trim() || null,
    categoryIds: form.value.categories.map(c => c.id),
  })
}

function onDelete() {
  pendingAction.value = 'delete'
  emit('delete', props.book.id)
}

// Picking a search result seeds the manual form with its metadata and switches
// to the manual tab so the user can tweak and save. Status/categories aren't
// part of a template — keep the create defaults.
function applyTemplate(t) {
  form.value = {
    title: t.title ?? '',
    author: t.author ?? '',
    description: t.description ?? '',
    isbn: t.isbn ?? '',
    status: 'own',
    language: t.language ?? null,
    coverPath: t.coverPath ?? '',
    categories: [],
  }
  errorMsg.value = null
  activeTab.value = 'manual'
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-overlay" @click.self="emit('close')">
      <div class="modal" role="dialog" aria-modal="true">
        <header class="modal__header">
          <h2 class="modal__title">{{ isEdit ? 'Edit Book' : 'Add New Book' }}</h2>
          <button class="modal__close" aria-label="Close" @click="emit('close')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <!-- On-loan notice: the book is locked until it's returned -->
          <p v-if="readOnly" class="modal__notice">
            <span class="material-symbols-outlined">lock</span>
            This book is out on loan and can't be edited until it's returned.
          </p>

          <!-- Create mode: enter details by hand or fill from an existing book -->
          <div v-if="!isEdit && !readOnly" class="modal__tabs" role="tablist">
            <button
              type="button"
              class="modal__tab"
              :class="{ 'modal__tab--active': activeTab === 'manual' }"
              role="tab"
              :aria-selected="activeTab === 'manual'"
              @click="activeTab = 'manual'"
            >
              Create manually
            </button>
            <button
              type="button"
              class="modal__tab"
              :class="{ 'modal__tab--active': activeTab === 'template' }"
              role="tab"
              :aria-selected="activeTab === 'template'"
              @click="activeTab = 'template'"
            >
              Find a template
            </button>
          </div>

          <!-- Template search (create mode only) -->
          <BookTemplateSearch v-if="!isEdit && activeTab === 'template'" @select="applyTemplate" />

          <!-- Manual entry form -->
          <div v-show="activeTab === 'manual'" class="modal__form">
          <!-- Cover preview + URL -->
          <div class="field">
            <label class="field__label" for="mb-cover">Cover image URL</label>
            <div class="cover-row">
              <div class="cover-preview">
                <img v-if="form.coverPath" :src="form.coverPath" alt="Cover preview" />
                <span v-else class="material-symbols-outlined cover-preview__icon">menu_book</span>
              </div>
              <input
                id="mb-cover"
                v-model="form.coverPath"
                class="input"
                type="url"
                placeholder="https://…"
                :disabled="readOnly"
              />
            </div>
          </div>

          <div class="field">
            <label class="field__label" for="mb-title">Title <span class="req">*</span></label>
            <input id="mb-title" v-model="form.title" class="input" type="text" placeholder="Enter title" :disabled="readOnly" />
          </div>

          <div class="field">
            <label class="field__label" for="mb-author">Author <span class="req">*</span></label>
            <input id="mb-author" v-model="form.author" class="input" type="text" placeholder="Enter author name" :disabled="readOnly" />
          </div>

          <div class="field">
            <label class="field__label" for="mb-description">Description</label>
            <textarea
              id="mb-description"
              v-model="form.description"
              class="input textarea"
              rows="4"
              :maxlength="DESC_MAX"
              placeholder="A short blurb or summary"
              :disabled="readOnly"
            ></textarea>
            <span v-if="!readOnly" class="field__counter">{{ descRemaining }}</span>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="field__label" for="mb-isbn">ISBN</label>
              <input id="mb-isbn" v-model="form.isbn" class="input" type="text" placeholder="e.g. 978-…" :disabled="readOnly" />
            </div>
            <div class="field">
              <label class="field__label" for="mb-status">Status</label>
              <BaseSelect id="mb-status" v-model="form.status" :options="statusOptions" :disabled="readOnly" />
            </div>
          </div>

          <div class="field">
            <label class="field__label" for="mb-language">Language</label>
            <LanguageSelect id="mb-language" v-model="form.language" :disabled="readOnly" placeholder="No language set" />
          </div>

          <div class="field">
            <label class="field__label">Categories</label>
            <CategorySelector v-model="form.categories" :disabled="readOnly" />
          </div>

          <p v-if="errorMsg" class="modal__error">{{ errorMsg }}</p>
          </div>
        </div>

        <footer class="modal__footer">
          <template v-if="readOnly">
            <div class="modal__footer-actions">
              <button class="btn-primary" type="button" @click="emit('close')">Close</button>
            </div>
          </template>
          <template v-else>
            <button v-if="isEdit" class="btn-delete" type="button" :disabled="busy" @click="onDelete">
              <BaseSpinner v-if="busy && pendingAction === 'delete'" size="sm" />
              <span v-else class="material-symbols-outlined">delete</span>
              {{ busy && pendingAction === 'delete' ? 'Deleting…' : 'Delete' }}
            </button>
            <div class="modal__footer-actions">
              <button class="btn-secondary" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
              <button v-if="activeTab === 'manual'" class="btn-primary" type="button" :disabled="busy" @click="onSave">
                <BaseSpinner v-if="busy && pendingAction === 'save'" size="sm" />
                {{ busy && pendingAction === 'save' ? 'Saving…' : 'Save' }}
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
  background: rgba(48, 49, 46, 0.4);   /* inverse-surface @ 40% */
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-md);
  z-index: 100;
}

.modal {
  background: var(--color-surface-container-lowest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.08);
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.modal__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  color: var(--color-on-background);
  margin: 0;
}
.modal__close {
  display: flex;
  color: var(--color-secondary);
  transition: color 0.2s;
}
.modal__close:hover { color: var(--color-on-background); }

.modal__body {
  padding: var(--space-md);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

/* Manual-entry form: preserve the body's field spacing now that fields are wrapped */
.modal__form { display: flex; flex-direction: column; gap: var(--space-md); }

/* Create-mode tabs */
.modal__tabs {
  display: flex;
  gap: var(--space-xs);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.modal__tab {
  padding: var(--space-sm) var(--space-base);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color 0.2s, border-color 0.2s;
}
.modal__tab:hover { color: var(--color-on-background); }
.modal__tab--active { color: var(--color-primary); border-bottom-color: var(--color-primary); }

.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
}
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
}
.req { color: var(--color-error); }

.textarea { resize: vertical; min-height: 88px; font-family: var(--font-body); line-height: 1.5; }
.field__counter {
  align-self: flex-end;
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
}

.input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  transition: border-color 0.2s;
}
.input:focus {
  outline: none;
  border-color: var(--color-primary);
}

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

.modal__error {
  color: var(--color-error);
  font-size: var(--text-label-md);
  margin: 0;
}

/* On-loan lock notice */
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

.input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: var(--color-surface-container-low);
}

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
  gap: var(--space-sm);
}
.modal__footer-actions {
  display: flex;
  gap: var(--space-sm);
  margin-left: auto;
}

.btn-primary,
.btn-secondary,
.btn-delete {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 20px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s;
}
.btn-primary {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
.btn-primary:hover { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-secondary {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-secondary);
  color: var(--color-on-surface-variant);
}
.btn-secondary:hover { background: var(--color-surface-container-low); }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-delete {
  background: transparent;
  color: var(--color-error);
  padding-left: 0;
}
.btn-delete:hover:not(:disabled) { text-decoration: underline; }
.btn-delete:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-delete .material-symbols-outlined { font-size: 20px; }

@media (max-width: 520px) {
  .field-row { grid-template-columns: 1fr; }
  .modal { max-width: 100%; }
}
</style>
