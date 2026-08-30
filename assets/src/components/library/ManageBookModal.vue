<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CategorySelector from '@/components/library/CategorySelector.vue'
import BookTemplateSearch from '@/components/library/BookTemplateSearch.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useCoverFallback } from '@/composables/useCoverFallback'
import { WISH_PRIORITIES, WISH_DEFAULT, wishPriorityKey } from '@/utils/wishPriority'

const { hasCover, onCoverError } = useCoverFallback()
const { t } = useI18n()

const props = defineProps({
  open:  { type: Boolean, default: false },
  book:  { type: Object, default: null },   // null = create mode
  // Parent-controlled: true while a save/delete request is in flight.
  busy:  { type: Boolean, default: false },
  // Create mode: open with "add to my wish list" already ticked. Set by the Wish
  // List tab's own add button, so that tab's flow lands where it says it will
  // while the Books tab keeps offering the same checkbox unticked.
  wished: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'delete', 'close', 'acquire'])

// Statuses an owner may pick by hand. 'lent' is intentionally absent: it's set
// only by the lending lifecycle (approve), never chosen manually — doing so would
// flag a book as on-loan while it still sits home. It's still shown (read-only)
// when viewing a lent book, via `statusOptions` below.
const selectableStatuses = computed(() => [
  { value: 'own',               label: t('book.statusOption.own') },
  { value: 'currently_reading', label: t('book.statusOption.currentlyReading') },
  { value: 'unavailable',       label: t('book.statusOption.unavailable') },
])

// Matches BookInput's Assert\Length(max: 500) on description.
const DESC_MAX = 500

const form = ref(blank())
// Which action the parent is currently processing — drives the right spinner.
const pendingAction = ref(null) // 'save' | 'delete' | 'acquire' | null
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
    ? [{ value: 'lent', label: t('book.statusOption.lent') }, ...selectableStatuses.value]
    : selectableStatuses.value,
)

const descRemaining = computed(() => DESC_MAX - form.value.description.length)

// The three levels, most urgent first — the picker's order matches the tab's.
const priorityOptions = computed(() =>
  WISH_PRIORITIES.map(value => ({ value, label: t(wishPriorityKey(value)) })),
)

// A wanted book has no lending state: it isn't on a shelf, so "available /
// reading / unavailable" is a question about a book that isn't there. The field
// is hidden rather than disabled, and the record keeps its stored default.
const showStatus = computed(() => !form.value.isWished)

// Which required field the error is about. Derived rather than stored, so it
// clears itself the moment the user types instead of needing a second Save.
const titleInvalid = computed(() => !!errorMsg.value && !form.value.title.trim())
const authorInvalid = computed(() => !!errorMsg.value && !form.value.author.trim())

function blank() {
  // categories: array of { id, name, colorHex }
  return {
    title: '', author: '', description: '', isbn: '', status: 'own', language: null,
    coverPath: '', isRead: false, isWished: props.wished, wishPriority: WISH_DEFAULT,
    categories: [],
  }
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
          isRead: props.book.isRead ?? false,
          isWished: props.book.isWished ?? false,
          // A book on the shelf still needs a level in hand, so ticking the
          // checkbox doesn't leave the picker empty.
          wishPriority: props.book.wishPriority ?? WISH_DEFAULT,
          categories: [...(props.book.categories ?? [])],
        }
      : blank()
  },
  { immediate: true },
)

function onSave() {
  if (readOnly.value) return
  if (!form.value.title.trim() || !form.value.author.trim()) {
    errorMsg.value = t('manageBook.requiredFields')
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
    isRead: form.value.isRead,
    isWished: form.value.isWished,
    // Sent only when it means something; the server drops it otherwise anyway.
    wishPriority: form.value.isWished ? form.value.wishPriority : null,
    categoryIds: form.value.categories.map(c => c.id),
  })
}

function onDelete() {
  pendingAction.value = 'delete'
  emit('delete', props.book.id)
}

// "I own this now" — a dedicated action rather than unticking the checkbox and
// saving, because it is the one thing a wish-list entry exists to become.
function onAcquire() {
  pendingAction.value = 'acquire'
  emit('acquire', props.book.id)
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
    isRead: false,
    // A template says nothing about which shelf you're filling — keep the one
    // the modal was opened for.
    isWished: form.value.isWished,
    wishPriority: form.value.wishPriority,
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
          <h2 class="modal__title">{{ isEdit ? t('manageBook.editTitle') : t('manageBook.createTitle') }}</h2>
          <button class="modal__close" :aria-label="t('common.close')" @click="emit('close')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <!-- On-loan notice: the book is locked until it's returned -->
          <p v-if="readOnly" class="modal__notice">
            <span class="material-symbols-outlined">lock</span>
            {{ t('manageBook.lockedNotice') }}
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
              {{ t('manageBook.tabManual') }}
            </button>
            <button
              type="button"
              class="modal__tab"
              :class="{ 'modal__tab--active': activeTab === 'template' }"
              role="tab"
              :aria-selected="activeTab === 'template'"
              @click="activeTab = 'template'"
            >
              {{ t('manageBook.tabTemplate') }}
            </button>
          </div>

          <!-- Template search (create mode only) -->
          <BookTemplateSearch v-if="!isEdit && activeTab === 'template'" @select="applyTemplate" />

          <!-- Manual entry form. Two columns from 768px up: the cover stands as a
               real preview beside the fields instead of a thumbnail above them. -->
          <div v-show="activeTab === 'manual'" class="modal__form">
          <!-- Cover preview + URL -->
          <div class="form__aside">
            <div class="field">
              <label class="field__label" for="mb-cover">{{ t('manageBook.coverUrl') }}</label>
              <div class="cover-row">
                <div class="cover-preview">
                  <img
                    v-if="hasCover(form.coverPath)"
                    :src="form.coverPath"
                    :alt="t('book.coverPreview')"
                    @error="onCoverError(form.coverPath)"
                  />
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
          </div>

          <div class="form__main">
          <div class="field">
            <label class="field__label" for="mb-title">{{ t('manageBook.title') }} <span class="req">*</span></label>
            <input
              id="mb-title"
              v-model="form.title"
              class="input"
              :class="{ 'input--invalid': titleInvalid }"
              :aria-invalid="titleInvalid || null"
              type="text"
              :placeholder="t('manageBook.titlePlaceholder')"
              :disabled="readOnly"
            />
          </div>

          <div class="field">
            <label class="field__label" for="mb-author">{{ t('manageBook.author') }} <span class="req">*</span></label>
            <input
              id="mb-author"
              v-model="form.author"
              class="input"
              :class="{ 'input--invalid': authorInvalid }"
              :aria-invalid="authorInvalid || null"
              type="text"
              :placeholder="t('manageBook.authorPlaceholder')"
              :disabled="readOnly"
            />
          </div>

          <div class="field">
            <label class="field__label" for="mb-description">{{ t('manageBook.description') }}</label>
            <textarea
              id="mb-description"
              v-model="form.description"
              class="input textarea"
              rows="4"
              :maxlength="DESC_MAX"
              :placeholder="t('manageBook.descriptionPlaceholder')"
              :disabled="readOnly"
            ></textarea>
            <span v-if="!readOnly" class="field__counter">{{ descRemaining }}</span>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="field__label" for="mb-isbn">{{ t('manageBook.isbn') }}</label>
              <input
                id="mb-isbn"
                v-model="form.isbn"
                class="input"
                type="text"
                :placeholder="t('manageBook.isbnPlaceholder')"
                :disabled="readOnly"
              />
            </div>
            <!-- Lending status for a book on the shelf; how badly it's wanted
                 for one on the wish list. The two are never both meaningful. -->
            <div v-if="showStatus" class="field">
              <label class="field__label" for="mb-status">{{ t('manageBook.status') }}</label>
              <BaseSelect id="mb-status" v-model="form.status" :options="statusOptions" :disabled="readOnly" />
            </div>
            <div v-else class="field">
              <label class="field__label" for="mb-priority">{{ t('wishlist.priorityLabel') }}</label>
              <BaseSelect id="mb-priority" v-model="form.wishPriority" :options="priorityOptions" :disabled="readOnly" />
            </div>
          </div>

          <div class="field">
            <label class="field__label" for="mb-language">{{ t('manageBook.language') }}</label>
            <LanguageSelect
              id="mb-language"
              v-model="form.language"
              :disabled="readOnly"
              :placeholder="t('manageBook.languagePlaceholder')"
            />
          </div>

          <label class="checkbox-field">
            <input type="checkbox" v-model="form.isRead" :disabled="readOnly" />
            <span class="material-symbols-outlined">check_circle</span>
            {{ t('manageBook.markRead') }}
          </label>

          <!-- The wish-list switch. Offered from the Books tab too (already
               ticked when the Wish List tab opened the modal), so cataloguing a
               book you want is the same flow as one you have. -->
          <label class="checkbox-field">
            <input type="checkbox" v-model="form.isWished" :disabled="readOnly" />
            <span class="material-symbols-outlined">bookmark_heart</span>
            {{ t('wishlist.markWanted') }}
          </label>

          <div class="field">
            <label class="field__label">{{ t('manageBook.categories') }}</label>
            <CategorySelector v-model="form.categories" :disabled="readOnly" />
          </div>

          </div>
          </div>
        </div>

        <!-- Outside modal__body on purpose: as the last element of a scrolling
             form it rendered below the fold, so Save looked like it did nothing. -->
        <p v-if="errorMsg" class="modal__error" role="alert">
          <span class="material-symbols-outlined">error</span>
          {{ errorMsg }}
        </p>

        <footer class="modal__footer">
          <template v-if="readOnly">
            <div class="modal__footer-actions">
              <button class="btn-primary" type="button" @click="emit('close')">{{ t('common.close') }}</button>
            </div>
          </template>
          <template v-else>
            <button v-if="isEdit" class="btn-delete" type="button" :disabled="busy" @click="onDelete">
              <BaseSpinner v-if="busy && pendingAction === 'delete'" size="sm" />
              <span v-else class="material-symbols-outlined">delete</span>
              {{ busy && pendingAction === 'delete' ? t('manageBook.deleting') : t('common.delete') }}
            </button>
            <div class="modal__footer-actions">
              <!-- The move a wish-list entry exists to make. Edit mode only:
                   there is nothing to acquire until the book is saved. -->
              <button
                v-if="isEdit && book?.isWished"
                class="btn-secondary btn-acquire"
                type="button"
                :disabled="busy"
                @click="onAcquire"
              >
                <BaseSpinner v-if="busy && pendingAction === 'acquire'" size="sm" />
                <span v-else class="material-symbols-outlined">library_add_check</span>
                {{ t('wishlist.acquire') }}
              </button>
              <button class="btn-secondary" type="button" :disabled="busy" @click="emit('close')">
                {{ t('common.cancel') }}
              </button>
              <button v-if="activeTab === 'manual'" class="btn-primary" type="button" :disabled="busy" @click="onSave">
                <BaseSpinner v-if="busy && pendingAction === 'save'" size="sm" />
                {{ busy && pendingAction === 'save' ? t('common.saving') : t('common.save') }}
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
  padding: var(--modal-gutter);
  z-index: 100;
}

.modal {
  background: var(--color-surface-container-lowest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.08);
  width: 100%;
  max-width: var(--modal-w-lg);
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
.form__main { display: flex; flex-direction: column; gap: var(--space-md); }

/* Desktop: cover column beside the fields. Below this the two wrappers are plain
   flex children, so the layout is the original single stack. */
@media (min-width: 768px) {
  .modal__form {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: var(--space-md);
    align-items: start;
  }
  /* The preview becomes a full book-shaped cover with the URL field beneath it. */
  .form__aside .cover-row { flex-direction: column; align-items: stretch; }
  .form__aside .cover-preview { width: 100%; height: auto; aspect-ratio: 2 / 3; }
  .form__aside .cover-preview__icon { font-size: 48px; }
}

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

.checkbox-field {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  cursor: pointer;
}
.checkbox-field input { width: 16px; height: 16px; accent-color: var(--color-primary); cursor: pointer; }
.checkbox-field input:disabled { cursor: not-allowed; }
.checkbox-field .material-symbols-outlined { font-size: 18px; color: var(--color-secondary); }

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
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  color: var(--color-error);
  font-size: var(--text-label-md);
  margin: 0;
  padding: var(--space-sm) var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
  background: color-mix(in srgb, var(--color-error) 8%, transparent);
}
.modal__error .material-symbols-outlined { font-size: 18px; }

.input--invalid { border-color: var(--color-error); }
.input--invalid:focus { border-color: var(--color-error); }

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

/* "I own this now" — a secondary button that leads with its icon, so the
   footer's primary Save stays the obvious default action. */
.btn-acquire { border-color: var(--color-primary); color: var(--color-primary); }
.btn-acquire:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-acquire .material-symbols-outlined { font-size: 18px; }

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
