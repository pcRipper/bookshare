<script setup>
const year = new Date().getFullYear()
</script>

<template>
  <footer class="app-footer">
    <div class="app-footer__inner">
      <span class="app-footer__brand">FolioShare</span>

      <nav class="app-footer__links">
        <a href="#">Community Guidelines</a>
        <a href="#">About Us</a>
        <a href="#">Help Center</a>
      </nav>

      <p class="app-footer__copy">© {{ year }} FolioShare. Curating stories for a thoughtful community.</p>
    </div>
  </footer>
</template>

<style scoped>
.app-footer {
  background: var(--color-surface-container-low);
  border-top: 1px solid var(--color-surface-container-highest);
  width: 100%;
  padding: var(--space-xl) var(--space-gutter);
  margin-top: auto;
}

.app-footer__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-md);
  max-width: var(--container-max);
  margin: 0 auto;
}
@media (min-width: 768px) {
  .app-footer__inner {
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
  }
}

.app-footer__brand {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-primary);
}

.app-footer__links {
  display: flex;
  gap: var(--space-md);
  flex-wrap: wrap;
  justify-content: center;
}
.app-footer__links a {
  font-size: var(--text-label-md);
  letter-spacing: var(--ls-label-md);
  color: var(--color-on-surface-variant);
  transition: color 0.2s, text-decoration-color 0.2s;
}
.app-footer__links a:hover {
  color: var(--color-primary);
  text-decoration: underline;
}

.app-footer__copy {
  font-size: var(--text-label-md);
  color: var(--color-secondary);
  margin: 0;
  text-align: center;
}
@media (min-width: 768px) {
  .app-footer__copy { text-align: right; }
}
</style>
