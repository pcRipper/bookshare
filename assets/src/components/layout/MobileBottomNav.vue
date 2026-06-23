<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const items = computed(() => [
  { label: 'Library',   to: '/library',  icon: 'book_2' },
  { label: 'Discover',  to: '/discover', icon: 'explore' },
  // The subscription feed replaces the retired Activity feed.
  { label: 'Following', to: '/subscriptions', icon: 'group' },
  { label: 'Profile',   to: auth.user?.id != null ? `/profile/${auth.user.id}` : '/library', icon: 'account_circle' },
  { label: 'Settings',  to: '/settings', icon: 'settings' },
])

function isActive(to) {
  return route.path.startsWith(to)
}
</script>

<template>
  <nav class="mobile-bottom-nav" aria-label="Main navigation">
    <RouterLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="mobile-nav-item"
      :class="{ 'mobile-nav-item--active': isActive(item.to) }"
    >
      <span class="material-symbols-outlined mobile-nav-item__icon">{{ item.icon }}</span>
      <span class="mobile-nav-item__label">{{ item.label }}</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.mobile-bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 64px;
  /* Sit above the iOS home indicator without the labels being clipped. */
  padding-bottom: env(safe-area-inset-bottom);
  background: var(--color-surface-container);
  border-top: 1px solid var(--color-outline-variant);
  display: flex;
  align-items: center;
  justify-content: space-around;
  z-index: 50;
}
@media (min-width: 768px) {
  .mobile-bottom-nav { display: none; }
}

.mobile-nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  flex: 1;
  color: var(--color-on-surface-variant);
  transition: color 0.15s;
}
.mobile-nav-item--active { color: var(--color-primary); }

.mobile-nav-item__icon {
  font-size: 24px;
}
/* Filled icon for active state */
.mobile-nav-item--active .mobile-nav-item__icon {
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

.mobile-nav-item__label {
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
}
</style>
