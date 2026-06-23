<script setup>
import { computed } from 'vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  user: {
    type: Object,
    required: true,
    /* shape: { id, fullName, avatarUrl, bio, stats: { totalBooks, shared, loaned }, isSubscribed } */
  },
  // Parent-controlled: true while this card's follow/unfollow is in flight.
  pending: { type: Boolean, default: false },
})

const emit = defineEmits(['follow', 'unfollow'])

const stats = computed(() => props.user.stats ?? { totalBooks: 0, shared: 0 })

function onToggle() {
  if (props.pending) return
  emit(props.user.isSubscribed ? 'unfollow' : 'follow', props.user.id)
}
</script>

<template>
  <article class="user-card">
    <RouterLink :to="`/profile/${user.id}`" class="user-card__main">
      <BaseAvatar :src="user.avatarUrl" :name="user.fullName" size="lg" />
      <h3 class="user-card__name">{{ user.fullName }}</h3>
      <p v-if="user.bio" class="user-card__bio">{{ user.bio }}</p>
      <p class="user-card__stats">
        {{ stats.totalBooks }} {{ stats.totalBooks === 1 ? 'book' : 'books' }}
        · {{ stats.shared }} shared
      </p>
    </RouterLink>

    <button
      class="user-card__action"
      :class="user.isSubscribed ? 'user-card__action--following' : 'user-card__action--follow'"
      :disabled="pending"
      @click="onToggle"
    >
      <BaseSpinner v-if="pending" size="sm" />
      <span v-else class="material-symbols-outlined">
        {{ user.isSubscribed ? 'check' : 'person_add' }}
      </span>
      {{ user.isSubscribed ? 'Following' : 'Follow' }}
    </button>
  </article>
</template>

<style scoped>
.user-card {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: var(--space-md);
  transition: border-color 0.2s, box-shadow 0.2s;
}
.user-card:hover {
  border-color: var(--color-outline-variant);
  box-shadow: 0 6px 20px rgba(35, 44, 51, 0.08);
}

.user-card__main {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-xs);
  flex: 1;
  min-width: 0;
}

.user-card__name {
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.3;
  color: var(--color-on-background);
  margin: var(--space-xs) 0 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (min-width: 768px) { .user-card__name { font-size: 18px; } }
.user-card__main:hover .user-card__name { color: var(--color-primary); }

.user-card__bio {
  font-size: 13px;
  color: var(--color-on-surface-variant);
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.user-card__stats {
  font-size: var(--text-label-sm);
  font-weight: 500;
  color: var(--color-secondary);
  margin: 0;
}

.user-card__action {
  margin-top: var(--space-md);
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
  cursor: pointer;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.user-card__action .material-symbols-outlined { font-size: 18px; }
.user-card__action:disabled { opacity: 0.7; cursor: default; }

.user-card__action--follow {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
.user-card__action--follow:hover:not(:disabled) { background: var(--color-primary-container); }
.user-card__action--follow:active { transform: scale(0.98); }

.user-card__action--following {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  border-color: var(--color-outline-variant);
}
.user-card__action--following:hover:not(:disabled) {
  color: var(--color-error);
  border-color: var(--color-error);
}
</style>
