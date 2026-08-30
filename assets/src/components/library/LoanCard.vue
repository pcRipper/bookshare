<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import RequestTimeline from '@/components/library/RequestTimeline.vue'
import { currentLocale } from '@/i18n'
import { relativeTime } from '@/utils/time'
import { isFinished } from '@/utils/loans'
import { useCoverFallback } from '@/composables/useCoverFallback'

/**
 * One loan, one card — whichever end of it you are on, for a single book or a
 * whole collection, at any point in its life.
 *
 * This replaces four per-book cards and the collection one. The Sharing panel
 * lists every loan together, so a card per state would switch layout three
 * times down a single column; here the state is a pill and an action row, and
 * the frame never changes. The variant is *derived* from perspective + status
 * rather than passed in, so a caller can never label a card wrongly.
 */
const props = defineProps({
  // Normalised by utils/loans.js — never a raw API payload.
  loan: { type: Object, required: true },
  perspective: { type: String, required: true }, // 'borrowing' | 'lending'
  // In-flight action: a string ('approve' | 'decline' | 'confirm-return' |
  // 'cancel' | 'return') while that action is running, else null.
  pending: { type: String, default: null },
})

// Every event carries the whole normalised loan, not just its id: ids collide
// between the two request tables, and the parent needs `kind` to know which
// store action to call.
const emit = defineEmits(['approve', 'decline', 'confirm-return', 'cancel', 'return'])

const { hasCover, onCoverError } = useCoverFallback()
const { t } = useI18n()

const loan = computed(() => props.loan)
const isLending = computed(() => props.perspective === 'lending')
const isCollection = computed(() => loan.value.kind === 'collection')

/* ── State ────────────────────────────────────────────────────────────── */
const STATUS_KEYS = {
  pending: 'pending', approved: 'approved', return_pending: 'returnPending',
  returned: 'returned', declined: 'declined',
}
const statusLabel = computed(() => {
  const key = STATUS_KEYS[loan.value.status]
  return key ? t(`requests.status.${key}`) : loan.value.status
})

// Only the owner of a pending request is asked for anything up front — that is
// the single state that needs a form, so it is the only one that grows.
const needsDecision = computed(() => isLending.value && loan.value.status === 'pending')
const awaitingConfirm = computed(() => loan.value.status === 'return_pending')

/* ── Meta line ────────────────────────────────────────────────────────── */
// The stores keep the ISO timestamp (it is the list's sort key), so the human
// phrasing is derived here rather than baked into the payload.
const requestedLabel = computed(() =>
  loan.value.requestedAt ? relativeTime(loan.value.requestedAt) : '',
)

// A due date on a settled loan is noise — the date it was due stopped mattering
// the moment it came back.
const due = computed(() => {
  if (!loan.value.dueDate || isFinished(loan.value)) return null
  const date = new Date(loan.value.dueDate)
  return {
    label: t('requests.due', {
      date: date.toLocaleDateString(currentLocale(), { day: 'numeric', month: 'short' }),
    }),
    overdue: new Date(loan.value.dueDate).setHours(23, 59, 59, 999) < Date.now(),
  }
})

/* ── Owner's approval form (lending · pending only) ───────────────────── */
function plusDaysISO(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}
const todayISO = plusDaysISO(0)
const dueDate = ref(plusDaysISO(14)) // sensible default — the owner may change it
const declineMessage = ref('')

function approve() { emit('approve', loan.value, dueDate.value || null) }
function decline() { emit('decline', loan.value, declineMessage.value.trim() || null) }

/* ── Timeline disclosure ──────────────────────────────────────────────── */
// Collapsed by default: the full step-by-step used to be visible only on
// finished loans, and showing it on every card would undo the compact list.
const detailsOpen = ref(false)
</script>

<template>
  <article class="loan-card" :class="{ 'loan-card--decision': needsDecision }">
    <div class="loan-card__main">
      <!-- Cover: the book's, or the collection's own image / its first member -->
      <div class="loan-card__cover" aria-hidden="true">
        <img
          v-if="hasCover(loan, loan.key)"
          :src="loan.coverPath"
          :alt="t('book.coverAlt', { title: loan.title })"
          loading="lazy"
          @error="onCoverError(loan.key)"
        />
        <span v-else class="material-symbols-outlined">
          {{ isCollection ? 'library_books' : 'menu_book' }}
        </span>
      </div>

      <div class="loan-card__body">
        <div class="loan-card__top">
          <span v-if="isCollection" class="loan-card__kind">
            <span class="material-symbols-outlined">library_books</span>{{ t('collections.badge') }}
          </span>
          <span class="loan-card__status" :class="`loan-card__status--${loan.status}`">{{ statusLabel }}</span>
        </div>

        <h3 class="loan-card__title">{{ loan.title }}</h3>
        <p v-if="loan.author" class="loan-card__author">{{ loan.author }}</p>
        <p v-else-if="isCollection" class="loan-card__author">
          {{ t('collections.bookCount', loan.books.length, { named: { count: loan.books.length } }) }}
        </p>

        <div class="loan-card__meta">
          <span class="loan-card__person">
            <BaseAvatar :src="loan.counterpart?.avatarUrl" :name="loan.counterpart?.fullName" size="sm" />
            <span class="loan-card__person-name">{{ loan.counterpart?.fullName }}</span>
          </span>
          <span v-if="awaitingConfirm" class="loan-card__note">{{ t('requests.wantsToReturnShort') }}</span>
          <span v-else-if="requestedLabel" class="loan-card__note">
            {{ t('requests.requestedShort', { when: requestedLabel }) }}
          </span>
          <span v-if="due" class="loan-card__due" :class="{ 'loan-card__due--overdue': due.overdue }">
            <span class="material-symbols-outlined">event</span>{{ due.label }}
          </span>
        </div>

        <!-- A collection's members, so the card says what is actually moving -->
        <ul v-if="isCollection && loan.books.length" class="loan-card__books">
          <li v-for="book in loan.books" :key="book.id" class="loan-card__book">
            <span class="loan-card__book-title">{{ book.title }}</span>
            <span class="loan-card__book-author">{{ book.author }}</span>
          </li>
        </ul>
      </div>

      <!-- Actions: exactly one state offers each of these -->
      <div v-if="!needsDecision" class="loan-card__actions">
        <!-- Owner: the borrower says the book is back -->
        <button
          v-if="isLending && awaitingConfirm"
          class="btn-primary"
          :disabled="!!pending"
          @click="emit('confirm-return', loan)"
        >
          <BaseSpinner v-if="pending === 'confirm-return'" size="sm" />
          <span v-else class="material-symbols-outlined">inventory</span>
          {{ pending === 'confirm-return' ? t('requests.confirming') : t('requests.confirmReceived') }}
        </button>

        <!-- Borrower: withdraw a request nobody has answered yet -->
        <button
          v-else-if="!isLending && loan.status === 'pending'"
          class="btn-danger"
          :disabled="!!pending"
          @click="emit('cancel', loan)"
        >
          <BaseSpinner v-if="pending === 'cancel'" size="sm" />
          <span v-else class="material-symbols-outlined">close</span>
          {{ pending === 'cancel' ? t('requests.cancelling') : t('requests.cancelRequest') }}
        </button>

        <!-- Borrower: hand it back -->
        <button
          v-else-if="!isLending && loan.status === 'approved'"
          class="btn-primary"
          :disabled="!!pending"
          @click="emit('return', loan)"
        >
          <BaseSpinner v-if="pending === 'return'" size="sm" />
          <span v-else class="material-symbols-outlined">assignment_return</span>
          {{ pending === 'return' ? t('requests.returning') : t('requests.markReturned') }}
        </button>

        <!-- Borrower: already handed back, waiting on the owner. The lender's
             approved state gets nothing here — the status pill and the due date
             already say the book is out, and repeating "On loan" beside them
             read as two different facts. -->
        <span v-else-if="!isLending && awaitingConfirm" class="loan-card__waiting">
          <span class="material-symbols-outlined">hourglass_top</span>{{ t('requests.awaitingOwner') }}
        </span>
      </div>
    </div>

    <!-- Owner's decision form. The one state with inputs, so it sits on its own
         row rather than squeezing beside the summary. -->
    <div v-if="needsDecision" class="loan-card__decision">
      <div class="loan-card__field">
        <label class="loan-card__label" :for="`due-${loan.key}`">{{ t('requests.returnBy') }}</label>
        <input
          :id="`due-${loan.key}`"
          v-model="dueDate"
          class="loan-card__input"
          type="date"
          :min="todayISO"
          :disabled="!!pending"
        />
      </div>
      <div class="loan-card__field loan-card__field--grow">
        <label class="loan-card__label" :for="`note-${loan.key}`">{{ t('requests.reasonLabel') }}</label>
        <input
          :id="`note-${loan.key}`"
          v-model="declineMessage"
          class="loan-card__input"
          type="text"
          maxlength="255"
          :placeholder="t('requests.reasonShortPlaceholder')"
          :disabled="!!pending"
        />
      </div>
      <div class="loan-card__decision-actions">
        <button class="btn-outline" :disabled="!!pending" @click="decline">
          <BaseSpinner v-if="pending === 'decline'" size="sm" />
          {{ pending === 'decline' ? t('requests.declining') : t('requests.decline') }}
        </button>
        <button class="btn-primary" :disabled="!!pending" @click="approve">
          <BaseSpinner v-if="pending === 'approve'" size="sm" />
          {{ pending === 'approve' ? t('requests.approving') : t('requests.approve') }}
        </button>
      </div>
    </div>

    <!-- Step-by-step history, on every loan rather than only finished ones -->
    <button
      v-if="loan.events.length"
      class="loan-card__details-toggle"
      :aria-expanded="detailsOpen"
      @click="detailsOpen = !detailsOpen"
    >
      <span class="material-symbols-outlined">{{ detailsOpen ? 'expand_less' : 'expand_more' }}</span>
      {{ t('library.loans.details') }}
    </button>
    <RequestTimeline v-if="detailsOpen" :events="loan.events" class="loan-card__timeline" />
  </article>
</template>

<style scoped>
.loan-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-variant);
  border-radius: var(--radius-default);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  transition: border-color 0.2s;
}
.loan-card:hover { border-color: var(--color-outline-variant); }

/* Cover | body | action — the row that never changes shape. Below 600px it
   stacks, because a 100px action button beside a wrapping title is unreadable. */
.loan-card__main {
  display: flex;
  gap: var(--space-md);
  align-items: flex-start;
}
@media (max-width: 599px) {
  .loan-card__main { flex-wrap: wrap; }
  .loan-card__actions { width: 100%; }
}

.loan-card__cover {
  width: 48px;
  height: 68px;
  flex-shrink: 0;
  background: var(--color-surface-variant);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-sm);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-outline);
}
.loan-card__cover img { width: 100%; height: 100%; object-fit: cover; }

.loan-card__body { flex: 1; min-width: 0; }

.loan-card__top {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: 4px;
}
.loan-card__kind,
.loan-card__status {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}
.loan-card__kind {
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
}
.loan-card__kind .material-symbols-outlined { font-size: 12px; }

/* One tone per state, so the pill carries the meaning the heading used to. */
.loan-card__status--pending { background: var(--color-tertiary); color: #ffffff; }
.loan-card__status--approved { background: var(--color-primary); color: var(--color-on-primary); }
.loan-card__status--return_pending { background: var(--color-secondary); color: var(--color-on-primary); }
.loan-card__status--returned { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
.loan-card__status--declined { background: var(--color-surface-container-high); color: var(--color-on-surface-variant); }

.loan-card__title {
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0;
}
.loan-card__author {
  font-size: var(--text-label-md);
  color: var(--color-secondary);
  margin: 2px 0 0;
}

.loan-card__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-xs) var(--space-sm);
  margin-top: var(--space-xs);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.loan-card__person { display: inline-flex; align-items: center; gap: 6px; }
.loan-card__person-name { font-weight: 600; color: var(--color-on-background); }
.loan-card__note { color: var(--color-on-surface-variant); }

.loan-card__due {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
}
.loan-card__due .material-symbols-outlined { font-size: 14px; }
.loan-card__due--overdue {
  background: var(--color-error-container, #ffdad6);
  border-color: var(--color-error);
  color: var(--color-error);
  font-weight: 600;
}

.loan-card__books {
  list-style: none;
  margin: var(--space-sm) 0 0;
  padding: var(--space-xs) var(--space-sm);
  background: var(--color-surface-container-low);
  border-radius: var(--radius-sm);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.loan-card__book { display: flex; gap: 6px; font-size: var(--text-label-sm); min-width: 0; }
.loan-card__book-title { color: var(--color-on-background); font-weight: 500; }
.loan-card__book-author { color: var(--color-on-surface-variant); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.loan-card__actions {
  display: flex;
  flex-direction: column;
  justify-content: center;
  flex-shrink: 0;
}
.loan-card__waiting {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
  white-space: nowrap;
}
.loan-card__waiting .material-symbols-outlined { font-size: 16px; }

/* Owner's decision row */
.loan-card__decision {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-sm);
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-variant);
}
.loan-card__field { display: flex; flex-direction: column; gap: var(--space-xs); min-width: 0; }
.loan-card__field--grow { flex: 1; min-width: 180px; }
.loan-card__label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.loan-card__input {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  color: var(--color-on-background);
}
.loan-card__input:focus { outline: none; border-color: var(--color-primary); }
.loan-card__input:disabled { opacity: 0.6; }
.loan-card__decision-actions { display: flex; gap: var(--space-sm); }
@media (max-width: 599px) {
  .loan-card__decision-actions { width: 100%; }
  .loan-card__decision-actions > * { flex: 1; }
}

/* Details disclosure */
.loan-card__details-toggle {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 2px 0;
  font-size: var(--text-label-sm);
  font-weight: 500;
  color: var(--color-secondary);
  transition: color 0.2s;
}
.loan-card__details-toggle:hover { color: var(--color-on-background); }
.loan-card__details-toggle .material-symbols-outlined { font-size: 18px; }
.loan-card__timeline { margin-top: var(--space-xs); }

/* Buttons — same three shapes the loan cards have always used. */
.btn-outline,
.btn-primary,
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
.btn-outline:disabled,
.btn-primary:disabled,
.btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-outline {
  border: 1px solid var(--color-secondary);
  color: var(--color-on-surface-variant);
  background: var(--color-surface-container-lowest);
}
.btn-outline:hover:not(:disabled) { background: var(--color-surface-container-low); }
.btn-primary {
  background: var(--color-primary);
  color: var(--color-on-primary);
  border: 1px solid transparent;
}
.btn-primary:hover:not(:disabled) { background: var(--color-surface-tint); }
.btn-danger {
  border: 1px solid var(--color-error);
  color: var(--color-error);
  background: var(--color-surface-container-lowest);
}
.btn-danger:hover:not(:disabled) { background: var(--color-error); color: #ffffff; }
.btn-primary .material-symbols-outlined,
.btn-danger .material-symbols-outlined { font-size: 18px; }
</style>
