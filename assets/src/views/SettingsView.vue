<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'

const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()

/* ── Section nav ──────────────────────────────────────────────────────── */
const section = ref('account')
const sections = computed(() => [
  { key: 'account',       label: t('settings.nav.account'),       icon: 'person' },
  { key: 'privacy',       label: t('settings.nav.privacy'),       icon: 'lock' },
  { key: 'notifications', label: t('settings.nav.notifications'), icon: 'notifications' },
])

/*
 * The whole page is edited locally and committed by one Save button that spans
 * all three tabs. Profile fields + visibility go to PATCH /me; the preference
 * toggles go to PATCH /me/settings — each endpoint is only called if its slice
 * actually changed.
 */
const BIO_MAX = 300
const form = reactive({ fullName: '', avatarUrl: '', bio: '', location: '', isPrivate: false })
// A ref, not a plain object: `profileDirty` reads it, so re-hydrating after a
// save has to invalidate that computed. As a bare `let` it never did, and the
// Save/Cancel buttons stayed enabled with nothing left to save.
const original = ref({})

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const saved = ref(false)

const bioRemaining = computed(() => BIO_MAX - form.bio.length)
const profileDirty = computed(() => JSON.stringify(form) !== JSON.stringify(original.value))

function hydrate(data) {
  form.fullName = data.fullName ?? ''
  form.avatarUrl = data.avatarUrl ?? ''
  form.bio = data.bio ?? ''
  form.location = data.location ?? ''
  form.isPrivate = !!data.isPrivate
  original.value = { ...form }
}

onMounted(async () => {
  try {
    const [me, settings] = await Promise.all([api.get('/me'), api.get('/me/settings')])
    hydrate(me.data)
    hydratePrefs(settings.data)
  } catch {
    error.value = t('settings.errors.load')
  } finally {
    loading.value = false
  }
})

async function save() {
  if (!form.fullName.trim()) {
    error.value = t('settings.errors.emptyName')
    return
  }
  saving.value = true
  error.value = null
  saved.value = false
  try {
    const requests = []
    if (profileDirty.value) {
      requests.push(
        api.patch('/me', {
          fullName: form.fullName.trim(),
          avatarUrl: form.avatarUrl.trim() || null,
          bio: form.bio.trim() || null,
          location: form.location.trim() || null,
          isPrivate: form.isPrivate,
        }).then(({ data }) => {
          hydrate(data)
          // Keep the shared auth user (header avatar + name) in sync.
          auth.setAuth(auth.token, {
            ...auth.user,
            fullName: data.fullName,
            avatarUrl: data.avatarUrl,
          })
        }),
      )
    }
    if (prefsDirty.value) {
      requests.push(api.patch('/me/settings', { ...prefs }).then(({ data }) => hydratePrefs(data)))
    }

    await Promise.all(requests)
    saved.value = true
    setTimeout(() => { saved.value = false }, 2500)
  } catch (e) {
    error.value = e.response?.data?.error ?? t('settings.errors.save')
  } finally {
    saving.value = false
  }
}

function cancel() {
  Object.assign(form, original.value)
  Object.assign(prefs, originalPrefs.value)
  error.value = null
}

function removeAvatar() {
  form.avatarUrl = ''
}

/*
 * Privacy and notification preferences (/api/me/settings). The `locale` this
 * resource also carries is deliberately absent: the header's LocaleSwitcher owns
 * the UI language end to end — it applies and saves it on the spot — so mirroring
 * it here would give one setting two sources of truth and let a stale Cancel
 * throw the language back.
 */
const prefs = reactive({
  allowRequests: true,
  showLocation: true,
  notifyBorrowRequests: true,
  notifyRequestUpdates: true,
  notifyActivity: false,
  notifyNewsletter: false,
})
const originalPrefs = ref({})   // see `original` above — same reactivity reason

const prefsDirty = computed(() => JSON.stringify(prefs) !== JSON.stringify(originalPrefs.value))
// Either slice being edited enables the page-wide Save button.
const dirty = computed(() => profileDirty.value || prefsDirty.value)

function hydratePrefs(data) {
  // Key by key rather than Object.assign(prefs, data): the response also carries
  // `locale`, and copying it in would both resurrect it in this form's payload
  // and let a Save commit whatever language was current when the page loaded,
  // silently undoing a switch made in the header since.
  for (const key of Object.keys(prefs)) {
    if (key in data) prefs[key] = data[key]
  }
  originalPrefs.value = { ...prefs }
}

const privacyOptions = computed(() => [
  { key: 'allowRequests', label: t('settings.privacy.allowRequests'), hint: t('settings.privacy.allowRequestsHint') },
  { key: 'showLocation',  label: t('settings.privacy.showLocation'),  hint: t('settings.privacy.showLocationHint') },
])
const notificationOptions = computed(() => [
  { key: 'notifyBorrowRequests', label: t('settings.notifications.borrowRequests'), hint: t('settings.notifications.borrowRequestsHint') },
  { key: 'notifyRequestUpdates', label: t('settings.notifications.requestUpdates'), hint: t('settings.notifications.requestUpdatesHint') },
  { key: 'notifyActivity',       label: t('settings.notifications.activity'),       hint: t('settings.notifications.activityHint') },
  { key: 'notifyNewsletter',     label: t('settings.notifications.newsletter'),     hint: t('settings.notifications.newsletterHint') },
])

/* ── Sign out ─────────────────────────────────────────────────────────── */
function signOut() {
  auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <AppLayout>
    <div class="settings-page">
      <header class="settings-page__intro">
        <h1 class="settings-page__title">{{ t('settings.title') }}</h1>
        <p class="settings-page__subtitle">{{ t('settings.subtitle') }}</p>
      </header>

      <div class="settings-layout">
        <!-- Section nav -->
        <aside class="settings-nav">
          <button
            v-for="s in sections"
            :key="s.key"
            class="settings-nav__item"
            :class="{ 'settings-nav__item--active': section === s.key }"
            @click="section = s.key"
          >
            <span class="material-symbols-outlined">{{ s.icon }}</span>
            <span class="settings-nav__label">{{ s.label }}</span>
            <span class="material-symbols-outlined settings-nav__chevron">chevron_right</span>
          </button>
          <button class="settings-nav__item settings-nav__item--danger" @click="signOut">
            <span class="material-symbols-outlined">logout</span>
            <span class="settings-nav__label">{{ t('settings.signOut') }}</span>
          </button>
        </aside>

        <!-- Panel -->
        <div class="settings-panel">
          <!-- ── Account Profile ──────────────────────────────────────── -->
          <template v-if="section === 'account'">
            <h2 class="settings-panel__heading">{{ t('settings.account.heading') }}</h2>

            <div v-if="loading" class="settings-skeleton">
              <section class="card photo-card">
                <BaseSkeleton width="96px" height="96px" circle />
                <div class="photo-card__body">
                  <BaseSkeleton width="40%" height="16px" />
                  <BaseSkeleton width="80%" height="12px" />
                  <BaseSkeleton width="100%" height="40px" />
                </div>
              </section>
              <section class="card">
                <BaseSkeleton width="30%" height="12px" />
                <BaseSkeleton width="100%" height="40px" />
                <BaseSkeleton width="30%" height="12px" />
                <BaseSkeleton width="100%" height="92px" />
                <BaseSkeleton width="30%" height="12px" />
                <BaseSkeleton width="100%" height="40px" />
              </section>
            </div>

            <template v-else>
              <!-- Profile photo -->
              <section class="card photo-card">
                <BaseAvatar :src="form.avatarUrl" :name="form.fullName" size="xl" class="photo-card__avatar" />
                <div class="photo-card__body">
                  <h3 class="photo-card__title">{{ t('settings.account.photoTitle') }}</h3>
                  <p class="photo-card__hint">{{ t('settings.account.photoHint') }}</p>
                  <div class="field photo-card__field">
                    <input
                      v-model="form.avatarUrl"
                      class="input"
                      type="url"
                      placeholder="https://…"
                      :aria-label="t('settings.account.photoUrlLabel')"
                    />
                  </div>
                  <button class="btn-outline photo-card__remove" type="button" :disabled="!form.avatarUrl" @click="removeAvatar">
                    {{ t('common.remove') }}
                  </button>
                </div>
              </section>

              <!-- Personal info -->
              <section class="card">
                <div class="field">
                  <label class="field__label" for="set-name">{{ t('settings.account.fullName') }}</label>
                  <input
                    id="set-name"
                    v-model="form.fullName"
                    class="input"
                    type="text"
                    :placeholder="t('settings.account.fullNamePlaceholder')"
                  />
                </div>

                <div class="field">
                  <label class="field__label" for="set-bio">{{ t('settings.account.bio') }}</label>
                  <textarea
                    id="set-bio"
                    v-model="form.bio"
                    class="input textarea"
                    rows="4"
                    :maxlength="BIO_MAX"
                    :placeholder="t('settings.account.bioPlaceholder')"
                  />
                  <span class="field__counter" :class="{ 'field__counter--warn': bioRemaining < 0 }">
                    {{ form.bio.length }} / {{ BIO_MAX }}
                  </span>
                </div>

                <div class="field">
                  <label class="field__label" for="set-location">{{ t('settings.account.location') }}</label>
                  <div class="input input--with-icon">
                    <span class="material-symbols-outlined">location_on</span>
                    <input
                      id="set-location"
                      v-model="form.location"
                      type="text"
                      :placeholder="t('settings.account.locationPlaceholder')"
                    />
                  </div>
                </div>
              </section>
            </template>
          </template>

          <!-- ── Privacy & Security ───────────────────────────────────── -->
          <template v-else-if="section === 'privacy'">
            <h2 class="settings-panel__heading">{{ t('settings.privacy.heading') }}</h2>

            <!-- Profile visibility -->
            <section class="card toggle-card">
              <label class="toggle-row">
                <span class="toggle-row__text">
                  <span class="toggle-row__label">{{ t('settings.privacy.privateProfile') }}</span>
                  <span class="toggle-row__hint">{{ t('settings.privacy.privateProfileHint') }}</span>
                </span>
                <input v-model="form.isPrivate" type="checkbox" class="switch" :disabled="loading" />
              </label>
            </section>

            <!-- Account preferences -->
            <section class="card toggle-card">
              <label v-for="opt in privacyOptions" :key="opt.key" class="toggle-row">
                <span class="toggle-row__text">
                  <span class="toggle-row__label">{{ opt.label }}</span>
                  <span class="toggle-row__hint">{{ opt.hint }}</span>
                </span>
                <input v-model="prefs[opt.key]" type="checkbox" class="switch" :disabled="loading" />
              </label>
            </section>
          </template>

          <!-- ── Notifications ────────────────────────────────────────── -->
          <template v-else>
            <h2 class="settings-panel__heading">{{ t('settings.notifications.heading') }}</h2>
            <section class="card toggle-card">
              <label v-for="opt in notificationOptions" :key="opt.key" class="toggle-row">
                <span class="toggle-row__text">
                  <span class="toggle-row__label">{{ opt.label }}</span>
                  <span class="toggle-row__hint">{{ opt.hint }}</span>
                </span>
                <input v-model="prefs[opt.key]" type="checkbox" class="switch" :disabled="loading" />
              </label>
            </section>
          </template>

          <!-- Page-wide save bar: one Save commits edits across all three tabs. -->
          <footer v-if="!loading" class="settings-actions">
            <p v-if="error" class="form-error settings-actions__error">{{ error }}</p>
            <transition name="fade">
              <span v-if="saved" class="save-flash">
                <span class="material-symbols-outlined">check_circle</span> {{ t('common.saved') }}
              </span>
            </transition>
            <button class="btn-text" type="button" :disabled="!dirty || saving" @click="cancel">
              {{ t('common.cancel') }}
            </button>
            <button class="btn-primary" type="button" :disabled="!dirty || saving" @click="save">
              <BaseSpinner v-if="saving" size="sm" />
              {{ saving ? t('common.saving') : t('common.saveChanges') }}
            </button>
          </footer>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
.settings-page {
  /* Capped to the nav (256) + gap (48) + panel (768) block plus gutters, so
     the layout centres with even margins instead of stranding ~40% empty
     space to the right of the form on wide screens. */
  max-width: 1120px;
  margin: 0 auto;
  padding: var(--space-lg) var(--space-gutter) var(--space-xl);
}
.settings-page__intro { margin-bottom: var(--space-lg); }
.settings-page__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-background);
  margin: 0 0 var(--space-xs);
}
@media (min-width: 768px) { .settings-page__title { font-size: var(--text-headline-lg); } }
.settings-page__subtitle { color: var(--color-on-surface-variant); margin: 0; }
@media (min-width: 768px) { .settings-page__subtitle { display: none; } }

.settings-layout { display: flex; flex-direction: column; gap: var(--space-lg); }
@media (min-width: 768px) { .settings-layout { flex-direction: row; align-items: flex-start; } }

/* ── Section nav ──────────────────────────────────────────────────────── */
.settings-nav {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  background: var(--color-surface-container);
  border-radius: var(--radius-lg);
  padding: var(--space-base);
}
@media (min-width: 768px) {
  .settings-nav {
    width: 256px;
    flex-shrink: 0;
    background: transparent;
    padding: 0;
    gap: 6px;
  }
}

.settings-nav__item {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  width: 100%;
  padding: 12px 16px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  text-align: left;
  transition: background 0.2s, color 0.2s;
}
.settings-nav__item:hover { background: var(--color-surface-container-low); color: var(--color-primary); }
.settings-nav__label { flex: 1; }
.settings-nav__chevron { font-size: 20px; opacity: 0.6; }
@media (min-width: 768px) { .settings-nav__chevron { display: none; } }

.settings-nav__item--active {
  background: var(--color-surface-container-high);
  color: var(--color-primary);
  font-weight: 600;
  border-left: 2px solid var(--color-primary);
}

.settings-nav__item--danger { color: var(--color-error); }
.settings-nav__item--danger:hover { background: var(--color-error-container); color: var(--color-error); }
@media (min-width: 768px) {
  .settings-nav__item--danger { margin-top: var(--space-md); border-top: 1px solid var(--color-surface-container-highest); border-radius: 0; padding-top: var(--space-md); }
}

/* ── Panel ────────────────────────────────────────────────────────────── */
.settings-panel { flex: 1; min-width: 0; max-width: 768px; display: flex; flex-direction: column; gap: var(--space-md); }
.settings-panel__heading {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  color: var(--color-on-background);
  margin: 0;
  padding-bottom: var(--space-sm);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.settings-skeleton { display: flex; flex-direction: column; gap: var(--space-md); }

.card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-lg);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

/* Photo card */
.photo-card { flex-direction: column; align-items: center; text-align: center; gap: var(--space-md); }
@media (min-width: 600px) { .photo-card { flex-direction: row; align-items: center; text-align: left; gap: var(--space-lg); } }
.photo-card__avatar { width: 96px !important; height: 96px !important; }
.photo-card__body { display: flex; flex-direction: column; gap: var(--space-xs); flex: 1; width: 100%; }
.photo-card__title { font-size: var(--text-label-md); font-weight: 600; color: var(--color-on-surface); margin: 0; }
.photo-card__hint { font-size: var(--text-label-md); color: var(--color-secondary); margin: 0; }
.photo-card__field { margin-top: var(--space-xs); }
.photo-card__remove { align-self: center; }
@media (min-width: 600px) { .photo-card__remove { align-self: flex-start; } }

/* Fields */
.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
}
.field__counter { font-size: var(--text-label-sm); color: var(--color-secondary); align-self: flex-end; }
.field__counter--warn { color: var(--color-error); }

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
.input:focus, .input:focus-within { outline: none; border-color: var(--color-primary); }
.textarea { resize: vertical; line-height: 1.6; }
.input--with-icon { display: flex; align-items: center; gap: var(--space-xs); padding: 0 12px; }
.input--with-icon .material-symbols-outlined { font-size: 18px; color: var(--color-secondary); }
.input--with-icon input { flex: 1; border: none; background: transparent; padding: 10px 0; font-size: var(--text-body-md); color: var(--color-on-background); outline: none; }

.form-error { color: var(--color-error); font-size: var(--text-label-md); margin: 0; }

.settings-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: var(--space-sm) var(--space-md);
  padding-top: var(--space-md);
  margin-top: var(--space-xs);
  border-top: 1px solid var(--color-surface-container-highest);
}
.settings-actions__error { margin-right: auto; }
.save-flash { display: inline-flex; align-items: center; gap: 4px; color: var(--color-primary); font-size: var(--text-label-md); font-weight: 500; margin-right: auto; }
.save-flash .material-symbols-outlined { font-size: 18px; }

/* Buttons */
.btn-primary, .btn-outline, .btn-text {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: 10px 20px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.btn-primary { background: var(--color-primary); color: var(--color-on-primary); }
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-outline { border: 1px solid var(--color-outline); color: var(--color-secondary); background: transparent; }
.btn-outline:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-text { color: var(--color-secondary); }
.btn-text:hover:not(:disabled) { color: var(--color-on-background); }
.btn-text:disabled { opacity: 0.5; cursor: not-allowed; }

/* Toggle rows */
.toggle-card { gap: 0; padding: 0; }
.toggle-row {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-md);
  cursor: pointer;
}
.toggle-row + .toggle-row { border-top: 1px solid var(--color-surface-container-highest); }
.toggle-row__text { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.toggle-row__label { font-size: var(--text-body-md); color: var(--color-on-background); font-weight: 500; }
.toggle-row__saving { margin-left: var(--space-xs); font-size: var(--text-label-sm); font-weight: 400; color: var(--color-secondary); }
.toggle-row__hint { font-size: var(--text-label-sm); color: var(--color-secondary); }

.switch {
  appearance: none;
  width: 40px;
  height: 22px;
  border-radius: var(--radius-full);
  background: var(--color-outline-variant);
  position: relative;
  flex-shrink: 0;
  cursor: pointer;
  transition: background 0.2s;
}
.switch::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--color-surface-container-lowest);
  transition: transform 0.2s;
}
.switch:checked { background: var(--color-primary); }
.switch:checked::after { transform: translateX(18px); }


/* transitions */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
