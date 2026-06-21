<script setup>
/**
 * Centred full-page status layout shared by the 404 and error screens: a large
 * Material icon, headline, supporting copy, and a slot for action buttons/links.
 */
defineProps({
  icon: { type: String, default: 'sentiment_dissatisfied' },
  code: { type: String, default: null },
  title: { type: String, required: true },
  message: { type: String, default: '' },
})
</script>

<template>
  <div class="status-screen">
    <span class="material-symbols-outlined status-screen__icon">{{ icon }}</span>
    <p v-if="code" class="status-screen__code">{{ code }}</p>
    <h1 class="status-screen__title">{{ title }}</h1>
    <p v-if="message" class="status-screen__message">{{ message }}</p>
    <div class="status-screen__actions">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.status-screen {
  min-height: 60vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: var(--space-sm);
  padding: var(--space-xl) var(--space-gutter);
  max-width: 32rem;
  margin: 0 auto;
}
.status-screen__icon {
  font-size: 64px;
  color: var(--color-primary);
  opacity: 0.85;
}
.status-screen__code {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-primary);
  letter-spacing: 0.1em;
  margin: 0;
}
.status-screen__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-background);
  margin: 0;
}
@media (min-width: 768px) {
  .status-screen__title { font-size: var(--text-headline-lg); line-height: var(--lh-headline-lg); }
}
.status-screen__message {
  font-size: var(--text-body-md);
  line-height: var(--lh-body-md);
  color: var(--color-on-surface-variant);
  margin: 0;
}
.status-screen__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--space-sm);
  margin-top: var(--space-md);
}
</style>
