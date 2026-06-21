<script setup>
import { storeToRefs } from 'pinia'
import { useToastStore } from '@/stores/toast'

const store = useToastStore()
const { toasts } = storeToRefs(store)

const ICONS = {
  success: 'check_circle',
  error: 'error',
  info: 'info',
}
</script>

<template>
  <Teleport to="body">
    <div class="toast-host" role="region" aria-live="polite" aria-label="Notifications">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="`toast--${t.type}`"
          role="alert"
        >
          <span class="material-symbols-outlined toast__icon">{{ ICONS[t.type] ?? 'info' }}</span>
          <p class="toast__msg">{{ t.message }}</p>
          <button class="toast__close" aria-label="Dismiss" @click="store.dismiss(t.id)">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-host {
  position: fixed;
  top: var(--space-md);
  right: var(--space-md);
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  max-width: min(380px, calc(100vw - 2 * var(--space-md)));
  pointer-events: none;
}

.toast {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-base);
  border-radius: var(--radius-default);
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.18);
  border-left: 4px solid var(--color-outline);
}
.toast--success { border-left-color: var(--color-primary); }
.toast--error { border-left-color: var(--color-error); }
.toast--info { border-left-color: var(--color-outline); }

.toast__icon { font-size: 20px; flex-shrink: 0; }
.toast--success .toast__icon { color: var(--color-primary-fixed, var(--color-primary)); }
.toast--error .toast__icon { color: var(--color-error); }

.toast__msg {
  margin: 0;
  flex: 1;
  min-width: 0;
  font-size: var(--text-label-md);
  line-height: 1.4;
}

.toast__close {
  display: flex;
  flex-shrink: 0;
  color: inherit;
  opacity: 0.7;
  transition: opacity 0.2s;
}
.toast__close:hover { opacity: 1; }
.toast__close .material-symbols-outlined { font-size: 18px; }

/* Enter/leave animation */
.toast-enter-active,
.toast-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.toast-enter-from,
.toast-leave-to { opacity: 0; transform: translateX(16px); }
.toast-leave-active { position: absolute; }
</style>
