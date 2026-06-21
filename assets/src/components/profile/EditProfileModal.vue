<script setup>
import { ref, watch, computed } from 'vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  open:    { type: Boolean, default: false },
  profile: { type: Object, default: null }, // { bio, location }
  // Parent-controlled: true while the save request is in flight.
  busy:    { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'close'])

const BIO_MAX = 300
const LOCATION_MAX = 255

const form = ref({ bio: '', location: '' })
const errorMsg = ref(null)

watch(
  () => [props.open, props.profile],
  () => {
    if (!props.open) return
    errorMsg.value = null
    form.value = {
      bio: props.profile?.bio ?? '',
      location: props.profile?.location ?? '',
    }
  },
  { immediate: true },
)

const bioRemaining = computed(() => BIO_MAX - form.value.bio.length)

function onSave() {
  if (form.value.bio.length > BIO_MAX || form.value.location.length > LOCATION_MAX) {
    errorMsg.value = 'Please shorten the highlighted fields.'
    return
  }
  errorMsg.value = null
  // The parent performs the request and toggles `busy`, closing the modal on success.
  emit('save', {
    bio: form.value.bio.trim() || null,
    location: form.value.location.trim() || null,
  })
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-overlay" @click.self="emit('close')">
      <div class="modal" role="dialog" aria-modal="true">
        <header class="modal__header">
          <h2 class="modal__title">Edit Profile</h2>
          <button class="modal__close" aria-label="Close" @click="emit('close')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <div class="field">
            <label class="field__label" for="ep-location">Location</label>
            <input
              id="ep-location"
              v-model="form.location"
              class="input"
              type="text"
              :maxlength="LOCATION_MAX"
              placeholder="e.g. Berlin, Germany"
            />
          </div>

          <div class="field">
            <label class="field__label" for="ep-bio">Bio</label>
            <textarea
              id="ep-bio"
              v-model="form.bio"
              class="input textarea"
              rows="4"
              :maxlength="BIO_MAX"
              placeholder="Tell the community what you love to read…"
            />
            <span class="field__hint" :class="{ 'field__hint--warn': bioRemaining < 0 }">
              {{ bioRemaining }} characters left
            </span>
          </div>

          <p v-if="errorMsg" class="modal__error">{{ errorMsg }}</p>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
          <button class="btn-primary" type="button" :disabled="busy" @click="onSave">
            <BaseSpinner v-if="busy" size="sm" />
            {{ busy ? 'Saving…' : 'Save Changes' }}
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
.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
}
.field__hint { font-size: var(--text-label-sm); color: var(--color-secondary); align-self: flex-end; }
.field__hint--warn { color: var(--color-error); }

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
.input:focus { outline: none; border-color: var(--color-primary); }
.textarea { resize: vertical; line-height: 1.6; }

.modal__error { color: var(--color-error); font-size: var(--text-label-md); margin: 0; }

.modal__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}
.btn-primary, .btn-secondary {
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
</style>
