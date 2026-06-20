<script setup>
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'

const route = useRoute()
const auth = useAuthStore()

const navLinks = [
  { label: 'My Library', to: '/library' },
  { label: 'Discover',   to: '/discover' },
  { label: 'Activity',   to: '/activity' },
]

function isActive(to) {
  return route.path.startsWith(to)
}
</script>

<template>
  <header class="app-header">
    <div class="app-header__inner">
      <!-- Brand + desktop nav -->
      <div class="app-header__left">
        <RouterLink to="/library" class="app-header__brand">FolioShare</RouterLink>

        <nav class="app-header__nav">
          <RouterLink
            v-for="link in navLinks"
            :key="link.to"
            :to="link.to"
            class="nav-link"
            :class="{ 'nav-link--active': isActive(link.to) }"
          >
            {{ link.label }}
          </RouterLink>
        </nav>
      </div>

      <!-- Actions + avatar -->
      <div class="app-header__actions">
        <button class="icon-btn" aria-label="Notifications">
          <span class="material-symbols-outlined">notifications</span>
        </button>
        <button class="icon-btn" aria-label="Bookmarks">
          <span class="material-symbols-outlined">bookmark</span>
        </button>
        <RouterLink to="/settings">
          <BaseAvatar
            :src="auth.user?.avatarUrl"
            :name="auth.user?.fullName"
            size="sm"
          />
        </RouterLink>
      </div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  position: sticky;
  top: 0;
  z-index: 50;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-surface-container-highest);
}

.app-header__inner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: var(--container-max);
  margin: 0 auto;
  padding: 0 var(--space-gutter);
  height: 64px;
}

.app-header__left {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}

.app-header__brand {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-primary);
  white-space: nowrap;
}

.app-header__nav {
  display: none;
  gap: var(--space-md);
  align-items: center;
}
@media (min-width: 768px) {
  .app-header__nav { display: flex; }
}

.nav-link {
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  color: var(--color-secondary);
  padding-bottom: 2px;
  border-bottom: 2px solid transparent;
  transition: color 0.2s, border-color 0.2s;
}
.nav-link:hover { color: var(--color-primary); }
.nav-link--active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}

.app-header__actions {
  display: flex;
  align-items: center;
  gap: var(--space-base);
}

.icon-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-primary);
  padding: 4px;
  border-radius: var(--radius-default);
  transition: opacity 0.2s;
}
.icon-btn:hover { opacity: 0.7; }
</style>
