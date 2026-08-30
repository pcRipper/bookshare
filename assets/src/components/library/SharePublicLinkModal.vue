<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Hands the owner a link (and QR) to their public library page.
 *
 * The link is built from window.location.origin rather than asked of the API:
 * DEFAULT_URI is http://localhost in both .env files, so a server-built URL
 * would be wrong everywhere but the dev box. The QR *is* server-rendered, and
 * derives its host from the request for the same reason.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  // The signed-in user: { id, isPrivate }.
  profile: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const { t } = useI18n()

const copied = ref(false)
const copyFailed = ref(false)

// A private profile 404s its own share link, so there is nothing to hand out.
const isPrivate = computed(() => !!props.profile?.isPrivate)

const shareUrl = computed(() =>
  props.profile ? `${window.location.origin}/public/library/${props.profile.id}` : '',
)
const qrUrl = computed(() =>
  props.profile ? `/api/public/users/${props.profile.id}/qr.svg` : '',
)

watch(
  () => props.open,
  open => {
    if (open) {
      copied.value = false
      copyFailed.value = false
    }
  },
)

async function copy() {
  copyFailed.value = false
  try {
    // navigator.clipboard is undefined outside a secure context — which is
    // exactly the LAN-over-http case someone testing the QR on a phone hits.
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(shareUrl.value)
    } else {
      legacyCopy()
    }
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    copyFailed.value = true
  }
}

function legacyCopy() {
  const field = document.createElement('textarea')
  field.value = shareUrl.value
  field.setAttribute('readonly', '')
  field.style.position = 'fixed'
  field.style.opacity = '0'
  document.body.appendChild(field)
  field.select()
  const ok = document.execCommand('copy')
  document.body.removeChild(field)
  if (!ok) throw new Error('copy rejected')
}

function close() {
  emit('close')
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
      <div class="modal" role="dialog" aria-modal="true" :aria-label="t('share.title')">
        <header class="modal__header">
          <h2 class="modal__title">{{ t('share.title') }}</h2>
          <button class="modal__close" type="button" :aria-label="t('common.close')" @click="close">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <!-- Private profile: the link would 404, so send them to the setting
               instead of handing over something that doesn't work. -->
          <div v-if="isPrivate" class="share-private">
            <span class="material-symbols-outlined share-private__icon">lock</span>
            <p>{{ t('share.privateNotice') }}</p>
            <RouterLink to="/settings" class="btn-primary" @click="close">
              {{ t('share.goToSettings') }}
            </RouterLink>
          </div>

          <template v-else>
            <p class="modal__hint">{{ t('share.hint') }}</p>

            <div class="share-qr">
              <img :src="qrUrl" :alt="t('share.qrAlt')" width="200" height="200" />
              <a :href="qrUrl" :download="`folioshare-library-${profile?.id}.svg`" class="share-qr__download">
                <span class="material-symbols-outlined">download</span>
                {{ t('share.downloadQr') }}
              </a>
            </div>

            <div class="share-link">
              <input class="share-link__input" type="text" :value="shareUrl" readonly @focus="$event.target.select()" />
              <button class="btn-primary share-link__copy" type="button" @click="copy">
                <span class="material-symbols-outlined">{{ copied ? 'check' : 'content_copy' }}</span>
                {{ copied ? t('share.copied') : t('share.copy') }}
              </button>
            </div>
            <p v-if="copyFailed" class="share-link__error">{{ t('share.copyFailed') }}</p>
          </template>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" @click="close">{{ t('common.close') }}</button>
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

.modal__hint {
  font-size: var(--text-body-sm);
  color: var(--color-on-surface-variant);
}

.modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}

/* ── QR ───────────────────────────────────────────────────────────────── */
.share-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-sm);
}
.share-qr img {
  width: 200px;
  height: 200px;
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  background: #fff;
}
.share-qr__download {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-primary);
}
.share-qr__download .material-symbols-outlined { font-size: 18px; }

/* ── Link ─────────────────────────────────────────────────────────────── */
.share-link {
  display: flex;
  gap: var(--space-sm);
  align-items: stretch;
}
.share-link__input {
  flex: 1;
  min-width: 0;
  padding: 10px 12px;
  border: 1px solid var(--color-outline);
  border-radius: var(--radius-default);
  font-size: var(--text-body-sm);
  font-family: var(--font-body);
  color: var(--color-on-surface);
  background: var(--color-surface);
}
.share-link__error {
  font-size: var(--text-label-sm);
  color: var(--color-error);
}

/* ── Private notice ───────────────────────────────────────────────────── */
.share-private {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-sm);
  padding: var(--space-md) 0;
  color: var(--color-on-surface-variant);
}
.share-private__icon { font-size: 40px; opacity: 0.6; }

/* ── Buttons ──────────────────────────────────────────────────────────── */
.btn-primary,
.btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 16px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
}
.btn-primary {
  background: var(--color-primary);
  color: var(--color-on-primary);
  border: none;
}
.btn-primary:hover { background: var(--color-primary-container); }
.btn-primary .material-symbols-outlined { font-size: 18px; }
.btn-secondary {
  background: none;
  border: 1px solid var(--color-outline);
  color: var(--color-on-surface-variant);
}
.btn-secondary:hover { background: var(--color-surface-container-low); }
</style>
