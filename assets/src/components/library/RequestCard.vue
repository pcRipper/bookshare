<script setup>
import BaseAvatar from '@/components/ui/BaseAvatar.vue'

defineProps({
  request: {
    type: Object,
    required: true,
    /* shape: {
         id,
         requester: { fullName, avatarUrl },
         book:      { title, author, coverPath },
         requestedAt: string,
       }
    */
  },
})

defineEmits(['approve', 'decline'])
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
        <p class="request-card__date">Requested {{ request.requestedAt }}</p>
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

    <!-- Actions -->
    <div class="request-card__actions">
      <button
        class="btn-outline"
        @click="$emit('decline', request.id)"
      >
        Decline
      </button>
      <button
        class="btn-primary"
        @click="$emit('approve', request.id)"
      >
        Approve
      </button>
    </div>
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

/* Actions */
.request-card__actions {
  display: flex;
  gap: var(--space-sm);
  margin-top: auto;
}

.btn-outline,
.btn-primary {
  flex: 1;
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  text-align: center;
  transition: background 0.2s, color 0.2s;
}

.btn-outline {
  border: 1px solid var(--color-secondary);
  color: var(--color-on-surface-variant);
  background: var(--color-surface-container-lowest);
}
.btn-outline:hover { background: var(--color-surface-container-low); }

.btn-primary {
  background: var(--color-primary);
  color: var(--color-on-primary);
  border: 1px solid transparent;
}
.btn-primary:hover { background: var(--color-surface-tint); }
</style>
