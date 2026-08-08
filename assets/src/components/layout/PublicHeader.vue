<script setup>
import { useI18n } from 'vue-i18n'

/*
 * Chrome for the signed-out share page. AppHeader can't be reused: its nav,
 * account menu and sign-out all target authenticated routes, so an anonymous
 * visitor would see an empty avatar and links that bounce them to /login.
 *
 * The brand is deliberately not a link — /library would do exactly that.
 */
const { t } = useI18n()
</script>

<template>
  <header class="public-header">
    <div class="public-header__inner">
      <span class="public-header__brand">FolioShare</span>

      <RouterLink to="/login" class="public-header__signin">
        {{ t('public.signIn') }}
      </RouterLink>
    </div>
  </header>
</template>

<style scoped>
.public-header {
  position: sticky;
  top: 0;
  z-index: 50;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-surface-container-highest);
}

.public-header__inner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: var(--container-max);
  margin: 0 auto;
  padding: 0 var(--space-gutter);
  height: 64px;
}

.public-header__brand {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-primary);
  white-space: nowrap;
}

.public-header__signin {
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  color: var(--color-on-primary);
  background: var(--color-primary);
  border-radius: var(--radius-sm);
  padding: var(--space-sm) var(--space-md);
  text-decoration: none;
  white-space: nowrap;
}
.public-header__signin:hover { opacity: 0.9; }
.public-header__signin:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
</style>
