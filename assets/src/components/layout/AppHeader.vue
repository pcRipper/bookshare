<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const navLinks = [
  { label: 'My Library', to: '/library' },
  { label: 'Discover',   to: '/discover' },
  { label: 'Activity',   to: '/activity' },
]

function isActive(to) {
  return route.path.startsWith(to)
}

/* ── Account dropdown ─────────────────────────────────────────────────── */
const menuOpen = ref(false)
const menuRef = ref(null)

const profileTo = computed(() => (auth.user?.id != null ? `/profile/${auth.user.id}` : '/library'))

function onDocClick(e) {
  if (menuRef.value && !menuRef.value.contains(e.target)) menuOpen.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
// Close on navigation.
watch(() => route.fullPath, () => { menuOpen.value = false })

function signOut() {
  menuOpen.value = false
  auth.logout()
  router.push({ name: 'login' })
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
        <div ref="menuRef" class="account-menu">
          <button
            class="account-menu__trigger"
            aria-label="Account menu"
            :aria-expanded="menuOpen"
            @click="menuOpen = !menuOpen"
          >
            <BaseAvatar
              :src="auth.user?.avatarUrl"
              :name="auth.user?.fullName"
              size="sm"
            />
          </button>

          <transition name="menu">
            <div v-if="menuOpen" class="account-menu__dropdown" role="menu">
              <div class="account-menu__header">
                <p class="account-menu__name">{{ auth.user?.fullName }}</p>
                <p class="account-menu__email">{{ auth.user?.email }}</p>
              </div>
              <RouterLink :to="profileTo" class="account-menu__item" role="menuitem">
                <span class="material-symbols-outlined">person</span> Profile
              </RouterLink>
              <RouterLink to="/settings" class="account-menu__item" role="menuitem">
                <span class="material-symbols-outlined">settings</span> Settings
              </RouterLink>
              <button class="account-menu__item account-menu__item--danger" role="menuitem" @click="signOut">
                <span class="material-symbols-outlined">logout</span> Sign Out
              </button>
            </div>
          </transition>
        </div>
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

/* ── Account dropdown ─────────────────────────────────────────────────── */
.account-menu { position: relative; }
.account-menu__trigger {
  display: flex;
  border-radius: var(--radius-full);
  transition: box-shadow 0.2s;
}
.account-menu__trigger:hover,
.account-menu__trigger[aria-expanded='true'] {
  box-shadow: 0 0 0 2px var(--color-primary-fixed);
}

.account-menu__dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 220px;
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.12);
  padding: var(--space-xs);
  z-index: 60;
}

.account-menu__header {
  padding: var(--space-sm);
  border-bottom: 1px solid var(--color-surface-container-highest);
  margin-bottom: var(--space-xs);
}
.account-menu__name {
  font-weight: 600;
  font-size: var(--text-label-md);
  color: var(--color-on-background);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.account-menu__email {
  font-size: var(--text-label-sm);
  color: var(--color-secondary);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-menu__item {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  width: 100%;
  padding: 10px 12px;
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  text-align: left;
  transition: background 0.15s, color 0.15s;
}
.account-menu__item:hover { background: var(--color-surface-container-low); color: var(--color-primary); }
.account-menu__item .material-symbols-outlined { font-size: 20px; }
.account-menu__item--danger { color: var(--color-error); }
.account-menu__item--danger:hover { background: var(--color-error-container); color: var(--color-error); }

.menu-enter-active, .menu-leave-active { transition: opacity 0.15s, transform 0.15s; }
.menu-enter-from, .menu-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
