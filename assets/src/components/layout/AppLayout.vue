<script setup>
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'
import MobileBottomNav from './MobileBottomNav.vue'
import PublicHeader from './PublicHeader.vue'

/*
 * One layout, two chromes. The signed-out share page can't use AppHeader or
 * MobileBottomNav — every link in them targets an authenticated route — but it
 * does want the same page shell, and the bottom-nav spacing below is exactly
 * the fiddly part that shouldn't be copy-pasted into a second layout.
 */
defineProps({
  variant: {
    type: String,
    default: 'app',
    validator: v => ['app', 'public'].includes(v),
  },
})
</script>

<template>
  <div class="app-layout">
    <AppHeader v-if="variant === 'app'" />
    <PublicHeader v-else />
    <main class="app-layout__main" :class="{ 'app-layout__main--public': variant === 'public' }">
      <slot />
    </main>
    <AppFooter />
    <MobileBottomNav v-if="variant === 'app'" />
  </div>
</template>

<style scoped>
.app-layout {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}

.app-layout__main {
  flex: 1;
  min-width: 0;
  /* Reserve space for the fixed mobile bottom nav (+ iOS home indicator). */
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
}
/* No bottom nav on the public shell, so nothing to reserve for. */
.app-layout__main--public { padding-bottom: 0; }
@media (min-width: 768px) {
  .app-layout__main { padding-bottom: 0; }
}
</style>
