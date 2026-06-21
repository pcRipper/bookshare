<script setup>
import { computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  loan: {
    type: Object,
    required: true,
    /* shape: { id, status, dueDate, book: { id, title, author, coverPath, owner } } */
  },
  // Parent-controlled: true while this loan's return request is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['return'])

const book = computed(() => props.loan.book)
const owner = computed(() => props.loan.book?.owner ?? null)
const returnRequested = computed(() => props.loan.status === 'return_pending')

/* ── Due date ─────────────────────────────────────────────────────────── */
const due = computed(() => {
  if (!props.loan.dueDate) return { label: 'No due date', overdue: false }
  const date = new Date(props.loan.dueDate)
  const today = new Date()
  const overdue = date.setHours(23, 59, 59, 999) < today.getTime()
  return {
    label: `Due ${new Date(props.loan.dueDate).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}`,
    overdue,
  }
})

function onReturn() {
  if (!returnRequested.value && !props.pending) emit('return', props.loan.id)
}
</script>

<template>
  <article class="borrowing-card">
    <div class="borrowing-card__cover">
      <img
        v-if="book.coverPath"
        :src="book.coverPath"
        :alt="`Cover of ${book.title}`"
        class="borrowing-card__img"
        loading="lazy"
      />
      <div v-else class="borrowing-card__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">menu_book</span>
      </div>
      <span
        class="borrowing-card__due"
        :class="{ 'borrowing-card__due--overdue': due.overdue }"
      >
        <span class="material-symbols-outlined">event</span>{{ due.label }}
      </span>
    </div>

    <div class="borrowing-card__body">
      <h3 class="borrowing-card__title">{{ book.title }}</h3>
      <p class="borrowing-card__author">{{ book.author }}</p>

      <RouterLink
        v-if="owner"
        :to="`/profile/${owner.id}`"
        class="borrowing-card__owner"
      >
        <BaseAvatar :src="owner.avatarUrl" :name="owner.fullName" size="sm" />
        <span class="borrowing-card__owner-name">from {{ owner.fullName }}</span>
      </RouterLink>

      <button
        class="borrowing-card__action"
        :class="returnRequested ? 'borrowing-card__action--awaiting' : 'borrowing-card__action--return'"
        :disabled="returnRequested || pending"
        @click="onReturn"
      >
        <BaseSpinner v-if="pending" size="sm" />
        <span v-else class="material-symbols-outlined">{{ returnRequested ? 'hourglass_top' : 'assignment_return' }}</span>
        {{ returnRequested ? 'Awaiting owner' : pending ? 'Returning…' : 'Mark as Returned' }}
      </button>
    </div>
  </article>
</template>

<style scoped>
.borrowing-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.borrowing-card:hover {
  border-color: var(--color-outline-variant);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.08);
}

.borrowing-card__cover {
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: var(--color-surface-container-low);
  position: relative;
}
.borrowing-card__img { width: 100%; height: 100%; object-fit: cover; }
.borrowing-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.borrowing-card__placeholder .material-symbols-outlined { font-size: 48px; color: var(--color-outline); opacity: 0.5; }

.borrowing-card__due {
  position: absolute;
  top: var(--space-base);
  left: var(--space-base);
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px 3px 6px;
  border-radius: var(--radius-full);
  background: var(--color-inverse-surface);
  color: var(--color-inverse-on-surface);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  box-shadow: 0 1px 4px rgba(35, 44, 51, 0.18);
}
.borrowing-card__due .material-symbols-outlined { font-size: 13px; }
.borrowing-card__due--overdue { background: var(--color-error); color: var(--color-on-error, #fff); }

.borrowing-card__body {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  flex: 1;
}
@media (min-width: 768px) { .borrowing-card__body { padding: 16px; } }

.borrowing-card__title {
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: 0 0 2px;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
@media (min-width: 768px) { .borrowing-card__title { font-size: 18px; } }

.borrowing-card__author { font-size: 13px; color: var(--color-on-surface-variant); margin: 0 0 var(--space-sm); }

.borrowing-card__owner {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
  color: var(--color-secondary);
  min-width: 0;
}
.borrowing-card__owner:hover .borrowing-card__owner-name { color: var(--color-primary); }
.borrowing-card__owner-name {
  font-size: var(--text-label-sm);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.15s;
}

.borrowing-card__action {
  margin-top: auto;
  width: 100%;
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
.borrowing-card__action .material-symbols-outlined { font-size: 18px; }
.borrowing-card__action--return {
  background: var(--color-primary);
  color: var(--color-on-primary);
  cursor: pointer;
}
.borrowing-card__action--return:hover:not(:disabled) { background: var(--color-primary-container); }
.borrowing-card__action--return:active:not(:disabled) { transform: scale(0.98); }
.borrowing-card__action--awaiting {
  background: var(--color-primary-fixed);
  color: var(--color-on-primary-fixed-variant);
  cursor: default;
}
.borrowing-card__action:disabled { cursor: not-allowed; }
</style>
