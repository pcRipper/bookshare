<script setup>
/**
 * The admin panel's Intercom tab: compose a "what's new" letter from the
 * release notes and send it to the members who asked to hear about updates.
 *
 * The composer's whole job is turning prose into a letter. A release note is
 * written for a page — several sentences, often three hundred characters — and
 * a letter wants one line. So selecting a note does not put the note in the
 * letter: it puts an **editable line**, pre-filled with the note's first
 * sentence, which is where these notes carry their substance. What the operator
 * sees in the box is exactly what goes out. Nothing is truncated behind their
 * back and nothing is generated from the changelog at send time.
 *
 * Two things are deliberately prominent, because nobody is subscribed by
 * default and the first letter would otherwise be composed for an audience of
 * nobody: the recipient count, and a test send to yourself.
 */
import { onMounted, computed, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminIntercomStore } from '@/stores/adminIntercom'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import { relativeTime } from '@/utils/time'
import { currentLocale } from '@/i18n'
import { CHANGELOG } from '@/data/changelog'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const store = useAdminIntercomStore()
const toast = useToastStore()
const { t } = useI18n()

const { recipients, letters, loading, sending, testing } = storeToRefs(store)

/** How many recent versions the picker offers. The rest are history. */
const OFFERED_VERSIONS = 8
/** Matches IntercomLetterItem's Assert\Length — the composer never lets you exceed it. */
const LINE_MAX = 200
const SUBJECT_MAX = 120
const INTRO_MAX = 300

onMounted(() => store.init())

/* ── The source: recent release notes ──────────────────────────────────── */

const versions = computed(() => CHANGELOG.slice(0, OFFERED_VERSIONS))

/**
 * The first sentence, capped — the short form a note is pre-filled with.
 *
 * Notes are written headline-first, so the opening sentence is almost always
 * the substance. Falls back to a hard cut only when there is no sentence break
 * to find, and the operator can edit either way.
 */
function shorten(note) {
  const firstSentence = note.match(/^.*?[.!?](?=\s|$)/)
  const line = (firstSentence ? firstSentence[0] : note).trim()

  return line.length <= LINE_MAX ? line : `${line.slice(0, LINE_MAX - 1).trimEnd()}…`
}

/** `${version}:${index}` → the edited line. Presence here means "selected". */
const selected = reactive(new Map())

const keyFor = (version, index) => `${version}:${index}`

function toggle(entry, index) {
  const key = keyFor(entry.version, index)
  if (selected.has(key)) selected.delete(key)
  else selected.set(key, shorten(entry.notes[index]))
}

/* ── The letter ────────────────────────────────────────────────────────── */

const subject = ref(t('admin.intercom.defaultSubject'))
const intro = ref('')

/** Grouped by version, in changelog order — the shape the endpoint takes. */
const items = computed(() =>
  versions.value
    .map(entry => ({
      version: entry.version,
      lines: entry.notes
        .map((_, i) => selected.get(keyFor(entry.version, i)))
        .filter(line => line !== undefined && line.trim() !== ''),
    }))
    .filter(item => item.lines.length > 0),
)

const selectedCount = computed(() => items.value.reduce((n, i) => n + i.lines.length, 0))
const canSend = computed(() => selectedCount.value > 0 && subject.value.trim() !== '')

function letterPayload() {
  return {
    subject: subject.value.trim(),
    intro: intro.value.trim() || null,
    items: items.value,
  }
}

/* ── Sending ───────────────────────────────────────────────────────────── */

async function onTest() {
  try {
    const queued = await store.sendTest(letterPayload())
    // A test obeys the same opt-in as everything else, so "not queued" has a
    // specific and actionable meaning: you are not subscribed yourself.
    if (queued) toast.success(t('admin.intercom.testSent'))
    else toast.error(t('admin.intercom.testSkipped'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.intercom.sendFailed')))
  }
}

const confirming = ref(false)

async function onSend() {
  confirming.value = false
  try {
    const { queued } = await store.send(letterPayload())
    toast.success(t('admin.intercom.sent', { count: queued }))
    selected.clear()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.intercom.sendFailed')))
  }
}

// currentLocale(), never an undefined locale: that resolves to the *browser's*
// language, which is how an English page ended up printing Ukrainian dates.
function absoluteDate(iso) {
  return new Date(iso).toLocaleDateString(currentLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="intercom">
    <p class="intercom__lead">{{ t('admin.intercom.lead') }}</p>

    <!-- Audience. First thing on the screen because nobody is subscribed by
         default, and a letter to nobody is the mistake worth preventing. -->
    <div class="intercom__audience" :class="{ 'intercom__audience--empty': recipients === 0 }">
      <span class="material-symbols-outlined">group</span>
      <span v-if="recipients === null">{{ t('admin.intercom.audienceUnknown') }}</span>
      <span v-else-if="recipients === 0">{{ t('admin.intercom.audienceEmpty') }}</span>
      <span v-else>{{ t('admin.intercom.audience', { count: recipients }) }}</span>
    </div>

    <!-- Pick the updates -->
    <section class="intercom__section">
      <h2 class="intercom__heading">{{ t('admin.intercom.pick') }}</h2>
      <p class="intercom__hint">{{ t('admin.intercom.pickHint') }}</p>

      <article v-for="entry in versions" :key="entry.version" class="version">
        <header class="version__header">
          <span class="version__number">{{ entry.version }}</span>
          <span class="version__date">{{ absoluteDate(entry.date) }}</span>
        </header>

        <div v-for="(note, index) in entry.notes" :key="index" class="note">
          <label class="note__pick">
            <input
              type="checkbox"
              :checked="selected.has(keyFor(entry.version, index))"
              @change="toggle(entry, index)"
            />
            <span class="note__text">{{ note }}</span>
          </label>

          <!-- Selected: the editable short line that actually goes out. -->
          <div v-if="selected.has(keyFor(entry.version, index))" class="note__line">
            <input
              class="note__input"
              type="text"
              :maxlength="LINE_MAX"
              :value="selected.get(keyFor(entry.version, index))"
              :aria-label="t('admin.intercom.lineLabel')"
              @input="selected.set(keyFor(entry.version, index), $event.target.value)"
            />
            <span class="note__counter">{{ LINE_MAX - (selected.get(keyFor(entry.version, index))?.length ?? 0) }}</span>
          </div>
        </div>
      </article>
    </section>

    <!-- Compose -->
    <section class="intercom__section">
      <h2 class="intercom__heading">{{ t('admin.intercom.compose') }}</h2>

      <div class="field">
        <label class="field__label" for="ic-subject">{{ t('admin.intercom.subject') }}</label>
        <input id="ic-subject" v-model="subject" class="field__input" type="text" :maxlength="SUBJECT_MAX" />
      </div>

      <div class="field">
        <label class="field__label" for="ic-intro">{{ t('admin.intercom.intro') }}</label>
        <textarea id="ic-intro" v-model="intro" class="field__input field__textarea" rows="3" :maxlength="INTRO_MAX"></textarea>
        <span class="field__counter">{{ INTRO_MAX - intro.length }}</span>
      </div>

      <div class="intercom__actions">
        <button class="btn-secondary" type="button" :disabled="!canSend || testing" @click="onTest">
          <BaseSpinner v-if="testing" size="sm" />
          <span v-else class="material-symbols-outlined">outgoing_mail</span>
          {{ t('admin.intercom.test') }}
        </button>

        <button class="btn-primary" type="button" :disabled="!canSend || sending" @click="confirming = true">
          <BaseSpinner v-if="sending" size="sm" />
          <span v-else class="material-symbols-outlined">send</span>
          {{ t('admin.intercom.send', { count: selectedCount }) }}
        </button>
      </div>

      <!-- A mail cannot be unsent, so the count is named before it goes. -->
      <div v-if="confirming" class="confirm">
        <p class="confirm__text">
          {{ t('admin.intercom.confirm', { count: recipients ?? 0 }) }}
        </p>
        <div class="confirm__actions">
          <button class="btn-secondary" type="button" @click="confirming = false">{{ t('common.cancel') }}</button>
          <button class="btn-danger" type="button" @click="onSend">{{ t('admin.intercom.confirmSend') }}</button>
        </div>
      </div>
    </section>

    <!-- What already went out -->
    <section class="intercom__section">
      <h2 class="intercom__heading">{{ t('admin.intercom.history') }}</h2>

      <p v-if="loading" class="intercom__hint">{{ t('common.loading') }}</p>
      <p v-else-if="!letters.length" class="intercom__hint">{{ t('admin.intercom.historyEmpty') }}</p>

      <ul v-else class="sent">
        <li v-for="letter in letters" :key="letter.id" class="sent__item">
          <div class="sent__head">
            <span class="sent__subject">{{ letter.subject }}</span>
            <span class="sent__meta">
              {{ t('admin.intercom.sentTo', { count: letter.recipientCount }) }} ·
              {{ relativeTime(letter.sentAt) }}
              <template v-if="letter.sentBy"> · {{ letter.sentBy }}</template>
            </span>
          </div>
          <p class="sent__versions">{{ letter.items.map(i => i.version).join(', ') }}</p>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.intercom { display: flex; flex-direction: column; gap: var(--space-lg); }
.intercom__lead {
  margin: 0;
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
}

.intercom__audience {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  align-self: flex-start;
  padding: 8px 14px;
  border-radius: var(--radius-full);
  background: var(--color-primary-container);
  color: var(--color-on-surface);
  font-size: var(--text-label-md);
  font-weight: 600;
}
.intercom__audience--empty {
  background: var(--color-tertiary-container, var(--color-surface-container-high));
  color: var(--color-on-surface-variant);
}
.intercom__audience .material-symbols-outlined { font-size: 18px; }

.intercom__section { display: flex; flex-direction: column; gap: var(--space-sm); }
.intercom__heading {
  margin: 0;
  font-family: var(--font-headline);
  font-size: var(--text-title-md);
  color: var(--color-on-background);
}
.intercom__hint { margin: 0; font-size: var(--text-label-md); color: var(--color-secondary); }

/* ── The picker ─────────────────────────────────────────────────────────── */
.version {
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  padding: var(--space-sm) var(--space-base);
  background: var(--color-surface-container-lowest);
}
.version__header {
  display: flex;
  align-items: baseline;
  gap: var(--space-sm);
  margin-bottom: var(--space-xs);
}
.version__number { font-weight: 700; color: var(--color-on-background); }
.version__date { font-size: var(--text-label-sm); color: var(--color-secondary); }

.note { padding: 6px 0; border-top: 1px solid var(--color-surface-container-high); }
.note:first-of-type { border-top: 0; }
.note__pick { display: flex; gap: var(--space-sm); align-items: flex-start; cursor: pointer; }
.note__pick input { margin-top: 3px; accent-color: var(--color-primary); flex-shrink: 0; }
.note__text {
  font-size: var(--text-body-sm, 14px);
  line-height: 1.5;
  color: var(--color-on-surface-variant);
}

.note__line { display: flex; align-items: center; gap: var(--space-xs); margin: 6px 0 4px 26px; }
.note__input {
  flex: 1;
  min-width: 0;
  padding: 8px 10px;
  border: 1px solid var(--color-primary);
  border-radius: var(--radius-default);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  background: var(--color-surface-container-lowest);
}
.note__counter { font-size: var(--text-label-sm); color: var(--color-secondary); min-width: 3ch; text-align: right; }

/* ── Compose ────────────────────────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: var(--space-xs); }
.field__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.field__input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  background: var(--color-surface-container-lowest);
}
.field__textarea { resize: vertical; line-height: 1.5; }
.field__counter { align-self: flex-end; font-size: var(--text-label-sm); color: var(--color-secondary); }

.intercom__actions { display: flex; flex-wrap: wrap; gap: var(--space-sm); margin-top: var(--space-xs); }
.btn-primary, .btn-secondary, .btn-danger {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 20px;
  border: 0;
  border-radius: var(--radius-default);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 600;
  cursor: pointer;
}
.btn-primary { background: var(--color-primary); color: var(--color-on-primary); }
.btn-secondary {
  background: var(--color-surface-container-lowest);
  color: var(--color-on-surface);
  border: 1px solid var(--color-outline-variant);
}
.btn-danger { background: var(--color-error); color: #ffffff; }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.55; cursor: not-allowed; }
.intercom__actions .material-symbols-outlined { font-size: 18px; }

.confirm {
  margin-top: var(--space-sm);
  padding: var(--space-base);
  border-radius: var(--radius-default);
  border: 1px solid var(--color-error);
  background: var(--color-error-container);
}
.confirm__text { margin: 0 0 var(--space-sm); font-size: var(--text-body-md); color: var(--color-on-surface); }
.confirm__actions { display: flex; gap: var(--space-sm); }

/* ── History ────────────────────────────────────────────────────────────── */
.sent { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-xs); }
.sent__item {
  padding: var(--space-sm) var(--space-base);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
}
.sent__head { display: flex; flex-wrap: wrap; gap: var(--space-xs) var(--space-sm); align-items: baseline; }
.sent__subject { font-weight: 600; color: var(--color-on-background); }
.sent__meta { font-size: var(--text-label-sm); color: var(--color-secondary); }
.sent__versions { margin: 4px 0 0; font-size: var(--text-label-sm); color: var(--color-on-surface-variant); }
</style>
