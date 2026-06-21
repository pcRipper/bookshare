<script setup>
import { computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import RequestTimeline from '@/components/library/RequestTimeline.vue'

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
        <p v-if="isBorrowing" class="history-card__main">
          You requested <em>{{ request.book.title }}</em>
          from <strong>{{ counterpart?.fullName }}</strong>
        </p>
        <p v-else class="history-card__main">
          <strong>{{ counterpart?.fullName }}</strong> requested
          <em>{{ request.book.title }}</em>
        </p>
        <span class="history-card__author">{{ request.book.author }}</span>
      </div>
      <span class="history-badge" :class="`history-badge--${request.status}`">{{ request.status }}</span>
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
  text-transform: capitalize;
  font-size: var(--text-label-sm);
  font-weight: 600;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  flex-shrink: 0;
  align-self: flex-start;
}
.history-badge--approved,
.history-badge--returned { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
.history-badge--declined { background: var(--color-error-container); color: var(--color-error); }
</style>
