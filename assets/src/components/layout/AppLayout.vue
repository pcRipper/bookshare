<script setup>
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'
import MobileBottomNav from './MobileBottomNav.vue'
</script>

<template>
  <div class="app-layout">
    <AppHeader />
    <main class="app-layout__main">
      <slot />
    </main>
    <AppFooter />
    <MobileBottomNav />
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
  /* Reserve space for fixed mobile bottom nav */
  padding-bottom: 64px;
}
@media (min-width: 768px) {
  .app-layout__main { padding-bottom: 0; }
}
</style>
