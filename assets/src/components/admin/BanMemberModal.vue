<script setup>
/**
 * Suspends a member, with an optional note on why.
 *
 * The note is optional on purpose. Requiring one would mean an operator dealing
 * with something obvious types "spam" to get past a field, which is worse than
 * an empty reason: it looks like a considered record and isn't. It is never
 * shown to the member — only in this panel, beside their row.
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  member: { type: Object, default: null },
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'close'])

const { t } = useI18n()

const reason = ref('')
const MAX = 255

watch(() => props.open, open => { if (open) reason.value = '' })

function close() {
  if (props.busy) return
  emit('close')
}

function confirm() {
  emit('confirm', reason.value.trim())
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) close()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open && member" class="modal-overlay" @click.self="close">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="t('admin.members.banTitle')">
        <header class="modal__header">
          <h2 class="modal__title">{{ t('admin.members.banTitle') }}</h2>
          <button class="modal__close" type="button" :aria-label="t('common.close')" @click="close">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <i18n-t keypath="admin.members.banIntro" tag="p" class="modal__lead" scope="global">
            <template #name><strong>{{ member.fullName }}</strong></template>
          </i18n-t>

          <ul class="ban-effects">
            <li>{{ t('admin.members.banEffectSignIn') }}</li>
            <li>{{ t('admin.members.banEffectContent') }}</li>
            <li>{{ t('admin.members.banEffectReversible') }}</li>
          </ul>

          <label class="ban-field">
            <span class="ban-field__label">{{ t('admin.members.banReasonLabel') }}</span>
            <textarea
              v-model="reason"
              class="ban-field__input"
              rows="3"
              :maxlength="MAX"
              :placeholder="t('admin.members.banReasonPlaceholder')"
            />
            <span class="ban-field__count">{{ reason.length }} / {{ MAX }}</span>
          </label>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" :disabled="busy" @click="close">
            {{ t('common.cancel') }}
          </button>
          <button class="btn-danger" type="button" :disabled="busy" @click="confirm">
            <BaseSpinner v-if="busy" size="sm" />
            {{ t('admin.members.banConfirm') }}
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
  padding: var(--modal-gutter);
  z-index: 100;
}
.modal {
  background: var(--color-surface);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: var(--modal-w-sm);
  max-height: var(--modal-max-h);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
  padding: var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.modal__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-sm);
  font-weight: 700;
}
.modal__close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-on-surface-variant);
  display: flex;
}
.modal__body {
  padding: var(--space-md);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.modal__lead { font-size: var(--text-body-md); }
.modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}

/* The same two button shapes the loan cards and the other modals use. */
.btn-secondary,
.btn-danger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.btn-secondary:disabled,
.btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-secondary {
  background: none;
  border: 1px solid var(--color-outline);
  color: var(--color-on-surface-variant);
}
.btn-secondary:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-danger {
  border: 1px solid var(--color-error);
  color: var(--color-error);
  background: var(--color-surface-container-lowest);
}
.btn-danger:hover:not(:disabled) { background: var(--color-error); color: #ffffff; }

.ban-effects {
  margin: 0;
  padding-left: 1.1em;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: var(--text-body-sm);
  color: var(--color-on-surface-variant);
}

.ban-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.ban-field__label {
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
}
.ban-field__input {
  width: 100%;
  padding: var(--space-sm);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
  font: inherit;
  color: var(--color-on-surface);
  resize: vertical;
}
.ban-field__input:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: -1px;
}
.ban-field__count {
  align-self: flex-end;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
</style>
