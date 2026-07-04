<script setup>
import AppLayout from '@/components/layout/AppLayout.vue'
import { CHANGELOG } from '@/data/changelog'

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('en-US', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}
</script>

<template>
  <AppLayout>
    <div class="changelog-page">
      <header class="changelog-page__header">
        <h1 class="changelog-page__title">Release Notes</h1>
        <p class="changelog-page__subtitle">A running log of what’s new in FolioShare.</p>
      </header>

      <ol class="changelog-list">
        <li v-for="entry in CHANGELOG" :key="entry.version" class="release">
          <div class="release__meta">
            <span class="release__version">v{{ entry.version }}</span>
            <time class="release__date" :datetime="entry.date">{{ formatDate(entry.date) }}</time>
          </div>
          <ul class="release__notes">
            <li v-for="(note, i) in entry.notes" :key="i">{{ note }}</li>
          </ul>
        </li>
      </ol>
    </div>
  </AppLayout>
</template>

<style scoped>
.changelog-page {
  max-width: 800px;
  margin: 0 auto;
  padding: var(--space-lg) var(--space-gutter);
}

.changelog-page__header {
  margin-bottom: var(--space-lg);
}
.changelog-page__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  color: var(--color-on-background);
  margin: 0 0 var(--space-xs);
}
.changelog-page__subtitle {
  font-size: var(--text-body-md);
  line-height: var(--lh-body-md);
  color: var(--color-on-surface-variant);
  margin: 0;
}

.changelog-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.release {
  background: var(--color-surface);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  padding: var(--space-md);
}

.release__meta {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-sm);
  flex-wrap: wrap;
  margin-bottom: var(--space-sm);
  padding-bottom: var(--space-sm);
  border-bottom: 1px solid var(--color-outline-variant);
}
.release__version {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-primary);
}
.release__date {
  font-size: var(--text-label-md);
  letter-spacing: var(--ls-label-md);
  color: var(--color-secondary);
}

.release__notes {
  margin: 0;
  padding-left: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.release__notes li {
  font-size: var(--text-body-md);
  line-height: var(--lh-body-md);
  color: var(--color-on-surface);
}
</style>
