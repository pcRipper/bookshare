<script setup>
/**
 * Deletes a member's account, behind a typed confirmation.
 *
 * The typing gate is not ceremony. This is the only irreversible action in the
 * product: it destroys somebody's entire library, both shelves, every collection
 * and their whole loan history, and no undo exists at any layer. A plain
 * "Are you sure?" is dismissed by the same reflex that produced the misclick,
 * whereas copying an address forces the operator to look at *which* row they are
 * about to act on — which is the mistake actually worth preventing in a table of
 * near-identical rows.
 *
 * The address is shown right above the field on purpose: this is a
 * deliberateness check, not a memory test.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  member: { type: Object, default: null },
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'close'])

const { t } = useI18n()

const typed = ref('')

watch(() => props.open, open => { if (open) typed.value = '' })

// Case- and whitespace-insensitive: a pasted address can arrive with a trailing
// space or a capitalised domain, and neither is the mistake this guards against.
const confirmed = computed(() =>
  !!props.member && typed.value.trim().toLowerCase() === props.member.email.toLowerCase(),
)

function close() {
  if (props.busy) return
  emit('close')
}

function confirm() {
  if (!confirmed.value || props.busy) return
  emit('confirm')
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
      <div class="modal" role="dialog" aria-modal="true" :aria-label="t('admin.members.deleteTitle')">
        <header class="modal__header">
          <h2 class="modal__title">{{ t('admin.members.deleteTitle') }}</h2>
          <button class="modal__close" type="button" :aria-label="t('common.close')" @click="close">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <i18n-t keypath="admin.members.deleteIntro" tag="p" class="modal__lead" scope="global">
            <template #name><strong>{{ member.fullName }}</strong></template>
          </i18n-t>

          <ul class="delete-effects">
            <li>{{ t('admin.members.deleteEffectLibrary', { count: member.stats.totalBooks + member.stats.wished }) }}</li>
            <li>{{ t('admin.members.deleteEffectCollections', { count: member.stats.collections }) }}</li>
            <li>{{ t('admin.members.deleteEffectIdentity') }}</li>
            <li class="delete-effects__final">{{ t('admin.members.deleteEffectFinal') }}</li>
          </ul>

          <label class="delete-field">
            <i18n-t keypath="admin.members.deleteTypeLabel" tag="span" class="delete-field__label" scope="global">
              <template #email><code>{{ member.email }}</code></template>
            </i18n-t>
            <input
              v-model="typed"
              class="delete-field__input"
              type="text"
              autocomplete="off"
              spellcheck="false"
              @keydown.enter="confirm"
            >
          </label>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" :disabled="busy" @click="close">
            {{ t('common.cancel') }}
          </button>
          <button class="btn-danger-solid" type="button" :disabled="!confirmed || busy" @click="confirm">
            <BaseSpinner v-if="busy" size="sm" />
            {{ t('admin.members.deleteConfirm') }}
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
  color: var(--color-error);
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

.delete-effects {
  margin: 0;
  padding-left: 1.1em;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: var(--text-body-sm);
  color: var(--color-on-surface-variant);
}
.delete-effects__final {
  color: var(--color-error);
  font-weight: 600;
}

.delete-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.delete-field__label {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}
.delete-field__label code {
  font-family: var(--font-mono, monospace);
  font-size: var(--text-label-md);
  color: var(--color-on-surface);
  background: var(--color-surface-container-low);
  padding: 1px 5px;
  border-radius: var(--radius-default);
  /* The address can be long and the sheet is narrow — wrap rather than pushing
     the dialog sideways. */
  overflow-wrap: anywhere;
}
.delete-field__input {
  width: 100%;
  padding: var(--space-sm);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
  font: inherit;
  color: var(--color-on-surface);
}
.delete-field__input:focus-visible {
  outline: 2px solid var(--color-error);
  outline-offset: -1px;
}

.btn-secondary,
.btn-danger-solid {
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
.btn-danger-solid:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary {
  background: none;
  border: 1px solid var(--color-outline);
  color: var(--color-on-surface-variant);
}
.btn-secondary:hover:not(:disabled) { background: var(--color-surface-container-low); }
/* Solid rather than the outlined .btn-danger the reversible actions use: this
   one does not come back, and the two should not look interchangeable. */
.btn-danger-solid {
  background: var(--color-error);
  border: 1px solid var(--color-error);
  color: #ffffff;
}
.btn-danger-solid:hover:not(:disabled) { filter: brightness(0.92); }
</style>
