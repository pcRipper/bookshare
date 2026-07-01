<script setup>
import { ref, computed } from 'vue'

/**
 * Description reveal for a book cover. Drop inside a `position: relative` cover
 * element; it fills that box with an overlay panel showing the book's blurb.
 *
 * Interaction is device-appropriate:
 * - Pointer devices (hover): the parent card reveals the panel on hover via a
 *   `:deep(.book-blurb__panel)` rule. The panel stays click-through (pointer-
 *   events: none) so it never steals the card's own click (e.g. open-to-edit).
 * - Touch devices (no hover): an info button toggles the panel, which then
 *   becomes interactive (scrollable, tap-to-dismiss). The button stops event
 *   propagation so toggling it doesn't also trigger the card.
 */
const props = defineProps({
  description: { type: String, default: null },
})

const revealed = ref(false)
const hasDescription = computed(() => !!props.description?.trim())
</script>

<template>
  <div v-if="hasDescription" class="book-blurb">
    <button
      type="button"
      class="book-blurb__toggle"
      :aria-expanded="revealed"
      :aria-label="revealed ? 'Hide description' : 'Show description'"
      @click.stop="revealed = !revealed"
    >
      <span class="material-symbols-outlined">{{ revealed ? 'close' : 'info' }}</span>
    </button>

    <div
      class="book-blurb__panel"
      :class="{ 'is-open': revealed }"
      role="region"
      aria-label="Book description"
      @click.stop="revealed = false"
    >
      <p class="book-blurb__text">{{ description }}</p>
    </div>
  </div>
</template>

<style scoped>
.book-blurb {
  position: absolute;
  inset: 0;
  pointer-events: none; /* let the cover keep its own click; children opt back in */
}

/* Info button — bottom-right to clear the top corners (status badge / category chip).
   Only shown where there's no hover to reveal the panel. */
.book-blurb__toggle {
  position: absolute;
  bottom: var(--space-base);
  right: var(--space-base);
  display: none;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  background: rgba(35, 44, 51, 0.55);
  color: #fff;
  pointer-events: auto;
  backdrop-filter: blur(2px);
}
.book-blurb__toggle .material-symbols-outlined { font-size: 18px; }
@media (hover: none) {
  .book-blurb__toggle { display: inline-flex; }
}

/* Overlay panel — hidden by default. Desktop hover reveals it display-only
   (see each card's :deep rule); touch open makes it interactive. */
.book-blurb__panel {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-end;
  padding: var(--space-sm);
  background: linear-gradient(180deg, rgba(35, 44, 51, 0) 0%, rgba(35, 44, 51, 0.82) 55%);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
  overflow-y: auto;
}
.book-blurb__panel.is-open {
  opacity: 1;
  pointer-events: auto;
}

.book-blurb__text {
  margin: 0;
  color: #fff;
  font-size: var(--text-label-md);
  line-height: 1.45;
}
</style>
