import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Lightweight transient notifications. Any component can `push()` a toast;
 * <ToastHost> renders them and they auto-dismiss after `timeout` ms.
 */
export const useToastStore = defineStore('toast', () => {
  const toasts = ref([])
  let seq = 0

  function push({ message, type = 'info', timeout = 5000 }) {
    const id = ++seq
    toasts.value.push({ id, message, type })
    if (timeout > 0) {
      setTimeout(() => dismiss(id), timeout)
    }
    return id
  }

  function dismiss(id) {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  // Convenience helpers.
  const success = (message, opts = {}) => push({ ...opts, message, type: 'success' })
  const error = (message, opts = {}) => push({ ...opts, message, type: 'error' })
  const info = (message, opts = {}) => push({ ...opts, message, type: 'info' })

  return { toasts, push, dismiss, success, error, info }
})
