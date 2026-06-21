<script setup>
import { ref, computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  request: {
    type: Object,
    required: true,
    /* shape: {
         id, status,
         requester: { fullName, avatarUrl },
         book:      { title, author, coverPath },
         requestedAt: string,
       }
    */
  },
  // Parent-controlled: 'approve' | 'decline' | 'confirm-return' while in flight.
  pending: { type: String, default: null },
})

const emit = defineEmits(['approve', 'decline', 'confirm-return'])

// A return-pending request is the borrower asking the owner to confirm receipt.
const isReturn = computed(() => props.request.status === 'return_pending')

/* ── Lender-set due date (pending requests only) ──────────────────────── */
function plusDaysISO(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}
const todayISO = plusDaysISO(0)
const dueDate = ref(plusDaysISO(14)) // sensible default — owner can change/clear

function approve() {
  emit('approve', props.request.id, dueDate.value || null)
}
</script>

<template>
  <article class="request-card">
    <!-- Requester -->
    <div class="request-card__requester">
      <BaseAvatar
        :src="request.requester.avatarUrl"
        :name="request.requester.fullName"
        size="md"
      />
      <div>
        <h3 class="request-card__name">{{ request.requester.fullName }}</h3>
        <p class="request-card__date">
          {{ isReturn ? 'Wants to return this book' : `Requested ${request.requestedAt}` }}
        </p>
      </div>
    </div>

    <!-- Book preview -->
    <div class="request-card__book">
      <div class="request-card__book-cover">
        <img
          v-if="request.book.coverPath"
          :src="request.book.coverPath"
          :alt="`Cover of ${request.book.title}`"
        />
        <span v-else class="material-symbols-outlined">menu_book</span>
      </div>
      <div class="request-card__book-info">
        <h4 class="request-card__book-title">{{ request.book.title }}</h4>
        <p class="request-card__book-author">{{ request.book.author }}</p>
      </div>
    </div>

    <!-- Return confirmation (return-pending) -->
    <template v-if="isReturn">
      <div class="request-card__actions">
        <button
          class="btn-primary"
          :disabled="!!pending"
          @click="$emit('confirm-return', request.id)"
        >
          <BaseSpinner v-if="pending === 'confirm-return'" size="sm" />
          <span v-else class="material-symbols-outlined">inventory</span>
          {{ pending === 'confirm-return' ? 'Confirming…' : 'Confirm received' }}
        </button>
      </div>
    </template>

    <!-- Borrow request (pending): due-date picker + approve/decline -->
    <template v-else>
      <div class="request-card__due">
        <label class="request-card__due-label" :for="`due-${request.id}`">Return by</label>
        <input
          :id="`due-${request.id}`"
          v-model="dueDate"
          class="request-card__due-input"
          type="date"
          :min="todayISO"
          :disabled="!!pending"
        />
      </div>

      <div class="request-card__actions">
        <button class="btn-outline" :disabled="!!pending" @click="$emit('decline', request.id)">
          <BaseSpinner v-if="pending === 'decline'" size="sm" />
          {{ pending === 'decline' ? 'Declining…' : 'Decline' }}
        </button>
        <button class="btn-primary" :disabled="!!pending" @click="approve">
          <BaseSpinner v-if="pending === 'approve'" size="sm" />
          {{ pending === 'approve' ? 'Approving…' : 'Approve' }}
        </button>
      </div>
    </template>
  </article>
</template>

<style scoped>
.request-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-variant);
  border-radius: var(--radius-default);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  transition: border-color 0.2s;
}
.request-card:hover { border-color: var(--color-outline-variant); }

/* Requester row */
.request-card__requester {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.request-card__name {
  font-size: var(--text-label-md);
  font-weight: 600;
  color: var(--color-on-background);
  margin: 0 0 2px;
}
.request-card__date {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
  margin: 0;
}

/* Book preview */
.request-card__book {
  display: flex;
  gap: var(--space-md);
  align-items: center;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-surface-variant);
  border-radius: var(--radius-default);
  padding: var(--space-sm);
  flex: 1;
}

.request-card__book-cover {
  width: 56px;
  height: 80px;
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
.request-card__book-cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.request-card__book-title {
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0 0 4px;
}
.request-card__book-author {
  font-size: var(--text-label-md);
  color: var(--color-secondary);
  margin: 0;
}

/* Due-date picker (pending borrow requests) */
.request-card__due {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}
.request-card__due-label {
  font-size: var(--text-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
.request-card__due-input {
  padding: 8px 10px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  color: var(--color-on-background);
}
.request-card__due-input:focus { outline: none; border-color: var(--color-primary); }
.request-card__due-input:disabled { opacity: 0.6; }

/* Actions */
.request-card__actions {
  display: flex;
  gap: var(--space-sm);
  margin-top: auto;
}

.btn-outline,
.btn-primary {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.btn-outline:disabled,
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

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
</style>
