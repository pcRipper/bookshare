<script setup>
/**
 * The operator's panel: one page holding the admin tools, with the section
 * chosen by a subtab strip.
 *
 * This shell exists because Analytics stopped being the only admin screen. The
 * dashboard used to *be* /admin, carrying its own page header inside AppLayout;
 * now the header and the layout live here, once, and each section renders into
 * the RouterView below as a bare panel.
 *
 * The strip is `ui/SubTabNav.vue` — the control the Library already uses for its
 * second-level axis. An admin panel is not the place to invent a third tab
 * pattern, and the shape of the problem is identical: a handful of sibling
 * panels, one at a time, no counters worth badging.
 *
 * The section is the route, not local state, so a bookmark or a browser back
 * button lands where the operator expects and /admin/stats keeps the URL it has
 * always had.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppLayout from '@/components/layout/AppLayout.vue'
import SubTabNav from '@/components/ui/SubTabNav.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const sections = computed(() => [
  { key: 'admin-members', label: t('admin.nav.members'), icon: 'group' },
  { key: 'admin-stats', label: t('admin.nav.analytics'), icon: 'insights' },
])

const current = computed({
  // A child route always matches, but during the redirect from bare /admin it
  // briefly does not — falling back keeps the strip from flashing unselected.
  get: () => (sections.value.some(s => s.key === route.name) ? route.name : 'admin-members'),
  set: name => router.push({ name }),
})
</script>

<template>
  <AppLayout>
    <div class="admin-panel">
      <header class="admin-panel__header">
        <h1 class="admin-panel__title">{{ t('admin.title') }}</h1>
        <p class="admin-panel__subtitle">{{ t('admin.subtitle') }}</p>
      </header>

      <SubTabNav
        v-model="current"
        :items="sections"
        :aria-label="t('admin.nav.label')"
      />

      <RouterView />
    </div>
  </AppLayout>
</template>

<style scoped>
/* The page frame the sections used to carry individually. */
.admin-panel {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
}
@media (max-width: 767px) {
  .admin-panel { padding: var(--space-lg) var(--space-gutter) var(--space-xl); }
}
.admin-panel__header { margin-bottom: var(--space-xs); }
.admin-panel__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  color: var(--color-on-surface);
  margin: 0;
}
@media (min-width: 768px) {
  .admin-panel__title { font-size: var(--text-headline-lg); }
}
.admin-panel__subtitle {
  margin: var(--space-xs) 0 0;
  color: var(--color-on-surface-variant);
}
</style>
