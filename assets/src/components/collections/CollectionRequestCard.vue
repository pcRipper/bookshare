<script setup>
import { ref, computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import RequestTimeline from '@/components/library/RequestTimeline.vue'

/**
 * A grouped collection-borrow request, badged "Collection" so it never reads as
 * a single-book request. One component, several variants mirroring the per-book
 * cards it sits beside:
 *  - 'incoming'        → owner inbox: approve/decline (pending) or confirm (return-pending)
 *  - 'outgoing-pending'→ borrower: cancel a still-pending request
 *  - 'borrowing'       → borrower: an active loan, mark the whole set returned
 *  - 'history'         → read-only status badge + timeline
 */
const props = defineProps({
  request: { type: Object, required: true },
  variant: { type: String, required: true },
  // Parent-controlled in-flight flag: string action ('approve'|'decline'|
  // 'confirm-return') for incoming; boolean for the single-action variants.
  pending: { type: [String, Boolean], default: null },
})

const emit = defineEmits(['approve', 'decline', 'confirm-return', 'cancel', 'return'])

const req = computed(() => props.request)
const books = computed(() => req.value.books ?? [])
const isReturn = computed(() => req.value.status === 'return_pending')

// Counterpart differs by side: owner sees the requester; borrower sees the owner.
const counterpart = computed(() =>
  props.variant === 'incoming' ? req.value.requester : req.value.collection.owner,
)

/* ── Due date (borrowing variant) ─────────────────────────────────────── */
const due = computed(() => {
  if (!req.value.dueDate) return null
  const date = new Date(req.value.dueDate)
  const overdue = new Date(req.value.dueDate).setHours(23, 59, 59, 999) < Date.now()
  return { label: `Due ${date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}`, overdue }
})

/* ── Owner controls (incoming pending) ────────────────────────────────── */
function plusDaysISO(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}
const todayISO = plusDaysISO(0)
const dueDate = ref(plusDaysISO(14))
const declineMessage = ref('')

function approve() { emit('approve', req.value.id, dueDate.value || null) }
function decline() { emit('decline', req.value.id, declineMessage.value.trim() || null) }

/* ── History status badge ─────────────────────────────────────────────── */
const STATUS_LABELS = {
  pending: 'Pending', approved: 'On loan', return_pending: 'Return pending',
  returned: 'Returned', declined: 'Declined',
}
const statusLabel = computed(() => STATUS_LABELS[req.value.status] ?? req.value.status)
</script>

<template>
  <article class="cr-card" :class="`cr-card--${variant}`">
    <!-- Header: collection identity + counterpart -->
    <div class="cr-card__head">
      <span class="cr-card__badge">
        <span class="material-symbols-outlined">library_books</span>Collection
      </span>
      <span v-if="variant === 'history'" class="cr-card__status" :class="`cr-card__status--${req.status}`">
        {{ statusLabel }}
      </span>
    </div>

    <h3 class="cr-card__name">{{ req.collection.name }}</h3>

    <div class="cr-card__person">
      <BaseAvatar :src="counterpart?.avatarUrl" :name="counterpart?.fullName" size="sm" />
      <span class="cr-card__person-text">
        <template v-if="variant === 'incoming'">
          <strong>{{ counterpart?.fullName }}</strong> · {{ isReturn ? 'wants to return' : `requested ${req.requestedAt ?? ''}` }}
        </template>
        <template v-else>
          from <strong>{{ counterpart?.fullName }}</strong>
        </template>
      </span>
    </div>

    <!-- Member books — a titled list (covers are optional, titles always show) -->
    <p class="cr-card__count">{{ books.length }} {{ books.length === 1 ? 'book' : 'books' }}</p>
    <ul class="cr-card__book-list">
      <li v-for="book in books" :key="book.id" class="cr-card__book">
        <span class="cr-card__book-cover" aria-hidden="true">
          <img v-if="book.coverPath" :src="book.coverPath" :alt="`Cover of ${book.title}`" loading="lazy" />
          <span v-else class="material-symbols-outlined">menu_book</span>
        </span>
        <span class="cr-card__book-text">
          <span class="cr-card__book-title">{{ book.title }}</span>
          <span class="cr-card__book-author">{{ book.author }}</span>
        </span>
      </li>
    </ul>

    <!-- Timeline (history) -->
    <RequestTimeline v-if="variant === 'history'" :events="req.events" class="cr-card__timeline" />

    <!-- Due-date pill (active loan, either side) -->
    <span
      v-if="(variant === 'borrowing' || variant === 'lending') && due"
      class="cr-card__due"
      :class="{ 'cr-card__due--overdue': due.overdue }"
    >
      <span class="material-symbols-outlined">event</span>{{ due.label }}
    </span>

    <!-- ── Actions ──────────────────────────────────────────────────────── -->

    <!-- Incoming: return confirmation -->
    <template v-if="variant === 'incoming' && isReturn">
      <div class="cr-card__actions">
        <button class="btn-primary" :disabled="!!pending" @click="emit('confirm-return', req.id)">
          <BaseSpinner v-if="pending === 'confirm-return'" size="sm" />
          <span v-else class="material-symbols-outlined">inventory</span>
          {{ pending === 'confirm-return' ? 'Confirming…' : 'Confirm received' }}
        </button>
      </div>
    </template>

    <!-- Incoming: approve / decline (pending) -->
    <template v-else-if="variant === 'incoming'">
      <div class="cr-card__due-field">
        <label class="cr-card__field-label" :for="`cdue-${req.id}`">Return by</label>
        <input :id="`cdue-${req.id}`" v-model="dueDate" class="cr-card__input" type="date" :min="todayISO" :disabled="!!pending" />
      </div>
      <div class="cr-card__note">
        <label class="cr-card__field-label" :for="`cnote-${req.id}`">Reason (optional)</label>
        <input :id="`cnote-${req.id}`" v-model="declineMessage" class="cr-card__input" type="text" maxlength="255" placeholder="Shared if you decline" :disabled="!!pending" />
      </div>
      <div class="cr-card__actions">
        <button class="btn-outline" :disabled="!!pending" @click="decline">
          <BaseSpinner v-if="pending === 'decline'" size="sm" />
          {{ pending === 'decline' ? 'Declining…' : 'Decline' }}
        </button>
        <button class="btn-primary" :disabled="!!pending" @click="approve">
          <BaseSpinner v-if="pending === 'approve'" size="sm" />
          {{ pending === 'approve' ? 'Approving…' : 'Approve' }}
        </button>
      </div>
    </template>

    <!-- Outgoing pending: cancel -->
    <template v-else-if="variant === 'outgoing-pending'">
      <span class="cr-card__await"><span class="material-symbols-outlined">hourglass_empty</span> Awaiting approval</span>
      <div class="cr-card__actions">
        <button class="btn-danger" :disabled="!!pending" @click="emit('cancel', req.id)">
          <BaseSpinner v-if="pending" size="sm" />
          <span v-else class="material-symbols-outlined">close</span>
          {{ pending ? 'Cancelling…' : 'Cancel request' }}
        </button>
      </div>
    </template>

    <!-- Active loan overview (owner side): read-only status, no actions -->
    <template v-else-if="variant === 'lending'">
      <span class="cr-card__await">
        <span class="material-symbols-outlined">{{ isReturn ? 'assignment_returned' : 'local_library' }}</span>
        {{ isReturn ? 'Return requested — confirm from Requests' : 'On loan' }}
      </span>
    </template>

    <!-- Active borrow: return whole set -->
    <template v-else-if="variant === 'borrowing'">
      <div class="cr-card__actions">
        <button
          class="btn-primary"
          :class="{ 'btn-primary--awaiting': isReturn }"
          :disabled="isReturn || !!pending"
          @click="emit('return', req.id)"
        >
          <BaseSpinner v-if="pending" size="sm" />
          <span v-else class="material-symbols-outlined">{{ isReturn ? 'hourglass_top' : 'assignment_return' }}</span>
          {{ isReturn ? 'Awaiting owner' : pending ? 'Returning…' : 'Return collection' }}
        </button>
      </div>
    </template>
  </article>
</template>

<style scoped>
.cr-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  transition: border-color 0.2s;
}
.cr-card:hover { border-color: var(--color-outline-variant); }

.cr-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}
.cr-card__badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px 3px 7px;
  border-radius: var(--radius-full);
  background: var(--color-primary-container);
  color: var(--color-on-primary-container);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  align-self: flex-start;
}
.cr-card__badge .material-symbols-outlined { font-size: 13px; }

.cr-card__status {
  font-size: var(--text-label-sm);
  font-weight: 600;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  white-space: nowrap;
}
.cr-card__status--approved,
.cr-card__status--returned { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
.cr-card__status--pending,
.cr-card__status--return_pending { background: var(--color-surface-container-high); color: var(--color-on-surface-variant); }
.cr-card__status--declined { background: var(--color-error-container); color: var(--color-error); }

.cr-card__name {
  font-family: var(--font-display);
  font-size: 18px;
  line-height: 1.25;
  color: var(--color-on-background);
  margin: 0;
}

.cr-card__person {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  color: var(--color-secondary);
}
.cr-card__person-text {
  font-size: var(--text-label-md);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cr-card__person-text strong { color: var(--color-on-background); font-weight: 600; }

.cr-card__count {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
  margin: 0;
}

.cr-card__book-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  max-height: 156px;
  overflow-y: auto;
}
.cr-card__book {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.cr-card__book-cover {
  width: 28px;
  height: 40px;
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
.cr-card__book-cover img { width: 100%; height: 100%; object-fit: cover; }
.cr-card__book-cover .material-symbols-outlined { font-size: 16px; }
.cr-card__book-text { display: flex; flex-direction: column; min-width: 0; }
.cr-card__book-title {
  font-size: var(--text-label-md);
  font-weight: 600;
  color: var(--color-on-background);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cr-card__book-author {
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cr-card__timeline {
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}

.cr-card__due {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  align-self: flex-start;
  padding: 3px 10px 3px 7px;
  border-radius: var(--radius-full);
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
  font-size: 11px;
  font-weight: 700;
}
.cr-card__due .material-symbols-outlined { font-size: 13px; }
.cr-card__due--overdue { background: var(--color-error); color: #fff; }

.cr-card__await {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--text-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
}
.cr-card__await .material-symbols-outlined { font-size: 15px; }

/* Owner pending controls */
.cr-card__due-field { display: flex; align-items: center; justify-content: space-between; gap: var(--space-sm); }
.cr-card__note { display: flex; flex-direction: column; gap: var(--space-xs); }
.cr-card__field-label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.cr-card__input {
  padding: 8px 10px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  color: var(--color-on-background);
}
.cr-card__note .cr-card__input { width: 100%; }
.cr-card__input:focus { outline: none; border-color: var(--color-primary); }
.cr-card__input:disabled { opacity: 0.6; }

.cr-card__actions {
  display: flex;
  gap: var(--space-sm);
  margin-top: auto;
}

.btn-outline, .btn-primary, .btn-danger {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  border: 1px solid transparent;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.btn-outline .material-symbols-outlined,
.btn-primary .material-symbols-outlined,
.btn-danger .material-symbols-outlined { font-size: 18px; }
.btn-outline:disabled, .btn-primary:disabled, .btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-outline {
  border-color: var(--color-secondary);
  color: var(--color-on-surface-variant);
  background: var(--color-surface-container-lowest);
}
.btn-outline:hover:not(:disabled) { background: var(--color-surface-container-low); }

.btn-primary { background: var(--color-primary); color: var(--color-on-primary); cursor: pointer; }
.btn-primary:hover:not(:disabled) { background: var(--color-primary-container); }
.btn-primary--awaiting { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); cursor: default; }

.btn-danger {
  border-color: var(--color-error);
  color: var(--color-error);
  background: transparent;
  cursor: pointer;
}
.btn-danger:hover:not(:disabled) { background: var(--color-error-container); }
</style>
