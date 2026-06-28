<script setup>
import { ref, watch } from 'vue'
import { useLibraryStore } from '@/stores/library'
import { apiErrorMessage } from '@/utils/apiError'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'imported'])

const store = useLibraryStore()

const mode = ref('append')      // 'append' | 'replace'
const onError = ref('skip')     // 'skip' | 'abort'
const file = ref(null)
const busy = ref(false)
const result = ref(null)        // server summary once a run finishes
const errorMsg = ref(null)

// Reset the form each time the modal opens.
watch(
  () => props.open,
  open => {
    if (open) {
      mode.value = 'append'
      onError.value = 'skip'
      file.value = null
      busy.value = false
      result.value = null
      errorMsg.value = null
    }
  },
)

function onFile(e) {
  file.value = e.target.files?.[0] ?? null
  result.value = null
  errorMsg.value = null
}

async function run() {
  if (!file.value || busy.value) return
  busy.value = true
  errorMsg.value = null
  result.value = null
  try {
    result.value = await store.importBooks(file.value, { mode: mode.value, onError: onError.value })
    emit('imported')
  } catch (e) {
    // An aborted run comes back as 422 with the same summary shape — show it.
    if (e.response?.status === 422 && e.response.data?.aborted) {
      result.value = e.response.data
    } else {
      errorMsg.value = apiErrorMessage(e, 'Could not import the file.')
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-overlay" @click.self="emit('close')">
      <div class="modal" role="dialog" aria-modal="true">
        <header class="modal__header">
          <h2 class="modal__title">Import Books</h2>
          <button class="modal__close" aria-label="Close" @click="emit('close')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <p class="modal__hint">
            Upload a CSV with columns <code>title, author, isbn, cover, language, status, categories</code>.
            Category names must already exist; unknown ones are ignored. Books that match one you
            already have (same title &amp; author) are skipped, never duplicated.
          </p>

          <!-- Mode -->
          <div class="field">
            <span class="field__label">When importing</span>
            <label class="radio">
              <input v-model="mode" type="radio" value="append" :disabled="busy" />
              <span><strong>Add</strong> to my collection</span>
            </label>
            <label class="radio">
              <input v-model="mode" type="radio" value="replace" :disabled="busy" />
              <span><strong>Replace</strong> my collection (books on loan are kept)</span>
            </label>
          </div>

          <!-- On error -->
          <div class="field">
            <span class="field__label">If a row is invalid</span>
            <label class="radio">
              <input v-model="onError" type="radio" value="skip" :disabled="busy" />
              <span>Skip it and import the rest</span>
            </label>
            <label class="radio">
              <input v-model="onError" type="radio" value="abort" :disabled="busy" />
              <span>Cancel the whole import</span>
            </label>
          </div>

          <!-- File -->
          <div class="field">
            <label class="field__label" for="import-file">CSV file</label>
            <input id="import-file" type="file" accept=".csv,text/csv" :disabled="busy" @change="onFile" />
          </div>

          <!-- Result summary -->
          <div v-if="result" class="result" :class="{ 'result--aborted': result.aborted }">
            <p v-if="result.aborted" class="result__head">
              <span class="material-symbols-outlined">error</span>
              Import cancelled — fix the rows below and try again.
            </p>
            <p v-else class="result__head">
              <span class="material-symbols-outlined">check_circle</span>
              Imported {{ result.imported }} book{{ result.imported === 1 ? '' : 's' }}<template v-if="result.skipped">, skipped {{ result.skipped }}</template>.
            </p>
            <ul v-if="result.errors?.length" class="result__errors">
              <li v-for="(row, i) in result.errors" :key="i">
                Line {{ row.line }}: {{ row.errors.join('; ') }}
              </li>
            </ul>
          </div>

          <p v-if="errorMsg" class="modal__error">{{ errorMsg }}</p>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" :disabled="busy" @click="emit('close')">
            {{ result && !result.aborted ? 'Done' : 'Cancel' }}
          </button>
          <button class="btn-primary" type="button" :disabled="!file || busy" @click="run">
            <BaseSpinner v-if="busy" size="sm" />
            {{ busy ? 'Importing…' : 'Import' }}
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
  background: rgba(48, 49, 46, 0.4);
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
.modal__close { display: flex; color: var(--color-secondary); transition: color 0.2s; }
.modal__close:hover { color: var(--color-on-background); }

.modal__body {
  padding: var(--space-md);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.modal__hint { margin: 0; font-size: var(--text-label-md); color: var(--color-on-surface-variant); }
.modal__hint code {
  font-size: 0.85em;
  background: var(--color-surface-container-high);
  padding: 1px 4px;
  border-radius: 4px;
}

.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
}
.radio {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  cursor: pointer;
}
.radio input { accent-color: var(--color-primary); }

input[type='file'] { font-size: var(--text-body-md); color: var(--color-on-background); }

.result {
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  padding: var(--space-sm) var(--space-base);
  background: var(--color-surface-container-low);
  font-size: var(--text-label-md);
}
.result--aborted { border-color: var(--color-error); }
.result__head {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin: 0;
  font-weight: 600;
  color: var(--color-on-background);
}
.result__head .material-symbols-outlined { font-size: 18px; }
.result--aborted .result__head { color: var(--color-error); }
.result__errors {
  margin: var(--space-xs) 0 0;
  padding-left: var(--space-md);
  color: var(--color-on-surface-variant);
  max-height: 140px;
  overflow-y: auto;
}

.modal__error { color: var(--color-error); font-size: var(--text-label-md); margin: 0; }

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}
.btn-primary,
.btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 20px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s;
}
.btn-primary { background: var(--color-primary); color: var(--color-on-primary); }
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-secondary {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-secondary);
  color: var(--color-on-surface-variant);
}
.btn-secondary:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 520px) {
  .modal { max-width: 100%; }
}
</style>
