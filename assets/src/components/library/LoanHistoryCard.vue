<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import RequestTimeline from '@/components/library/RequestTimeline.vue'

const { t } = useI18n()

const props = defineProps({
  // A resolved LibraryRequest payload, including its `events` array.
  request: { type: Object, required: true },
  // 'lending'  → the viewer owns the book (counterpart = requester).
  // 'borrowing' → the viewer borrowed the book (counterpart = the book's owner).
  perspective: { type: String, default: 'lending' },
})

const isBorrowing = computed(() => props.perspective === 'borrowing')

// The other party in the loan, from the viewer's point of view.
const counterpart = computed(() =>
  isBorrowing.value ? props.request.book.owner : props.request.requester,
)

// Human label for the current status badge (the raw enum has underscores).
const STATUS_KEYS = {
  pending: 'pending',
  approved: 'approved',
  return_pending: 'returnPending',
  returned: 'returned',
  declined: 'declined',
}
const statusLabel = computed(() => {
  const key = STATUS_KEYS[props.request.status]
  return key ? t(`requests.status.${key}`) : props.request.status
})
</script>

<template>
  <li class="history-card">
    <div class="history-card__head">
      <BaseAvatar
        :src="counterpart?.avatarUrl"
        :name="counterpart?.fullName"
        size="md"
      />
      <div class="history-card__text">
        <!-- One translatable sentence each: word order round the book title and
             the reader's name differs by language, so the emphasis markup has to
             travel as slots rather than be spliced between fragments. -->
        <i18n-t v-if="isBorrowing" keypath="requests.history.youRequested" tag="p" class="history-card__main">
          <template #book><em>{{ request.book.title }}</em></template>
          <template #owner><strong>{{ counterpart?.fullName }}</strong></template>
        </i18n-t>
        <i18n-t v-else keypath="requests.history.theyRequested" tag="p" class="history-card__main">
          <template #reader><strong>{{ counterpart?.fullName }}</strong></template>
          <template #book><em>{{ request.book.title }}</em></template>
        </i18n-t>
        <span class="history-card__author">{{ request.book.author }}</span>
      </div>
      <span class="history-badge" :class="`history-badge--${request.status}`">{{ statusLabel }}</span>
    </div>
    <RequestTimeline :events="request.events" class="history-card__timeline" />
  </li>
</template>

<style scoped>
.history-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  padding: var(--space-md);
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
}
.history-card__head {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.history-card__text { flex: 1; min-width: 0; }
.history-card__main { margin: 0; font-size: var(--text-label-md); color: var(--color-on-background); }
.history-card__main em { font-style: italic; color: var(--color-secondary); }
.history-card__author { font-size: var(--text-label-sm); color: var(--color-on-surface-variant); }
.history-card__timeline {
  padding-top: var(--space-sm);
  border-top: 1px solid var(--color-surface-container-highest);
}

.history-badge {
  font-size: var(--text-label-sm);
  font-weight: 600;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  flex-shrink: 0;
  align-self: flex-start;
  white-space: nowrap;
}
/* Finished / active-loan states */
.history-badge--approved,
.history-badge--returned { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
/* In-progress states */
.history-badge--pending,
.history-badge--return_pending {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
.history-badge--declined { background: var(--color-error-container); color: var(--color-error); }
</style>
