<script setup>
/**
 * The owner's 1-5 star rating of a book — the app's single star surface.
 *
 * Two variants, and the split is deliberate: five glyphs repeated down a grid of
 * cards is noise, so lists get `compact` (one filled star plus the number) and
 * only the surfaces that are *about* one book — the detail sheet, the editor —
 * get the full `stars` row.
 *
 * An unrated book renders **nothing** (`v-if` on the value, unless editable), so
 * a shelf of unrated books looks like it did before the feature rather than
 * growing a column of empty scales.
 *
 * Editable mode is a radio group, not five buttons: arrow keys move the value,
 * the whole control is one tab stop, and the current value is announced. Filled
 * stars take the brass accent — a set star is exactly the "active/selected"
 * state the design system reserves it for.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const MAX = 5
const STARS = [1, 2, 3, 4, 5]

const props = defineProps({
  // 1-5, or null for "not rated" — never 0, which would read as a low score.
  modelValue: { type: Number, default: null },
  variant: { type: String, default: 'compact' }, // 'compact' | 'stars'
  editable: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
})

const emit = defineEmits(['update:modelValue'])

const value = computed(() => (props.modelValue >= 1 && props.modelValue <= MAX ? props.modelValue : null))
const label = computed(() => t('book.ratingValue', { rating: value.value ?? 0, max: MAX }))

function pick(stars) {
  if (props.disabled) return
  // Clicking the current value clears it: the only way back to "not rated"
  // without hunting for a separate control.
  emit('update:modelValue', stars === value.value ? null : stars)
}

function onKey(e) {
  if (props.disabled) return
  const current = value.value ?? 0
  let next = null
  if (e.key === 'ArrowRight' || e.key === 'ArrowUp') next = Math.min(MAX, current + 1)
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') next = Math.max(1, current - 1)
  else if (e.key === 'Home') next = 1
  else if (e.key === 'End') next = MAX
  else if (e.key === 'Delete' || e.key === 'Backspace') next = null
  else return

  e.preventDefault()
  emit('update:modelValue', next)
}
</script>

<template>
  <!-- Editable: a radio group over the five levels. -->
  <div
    v-if="editable"
    class="rating rating--edit"
    :class="[`rating--${size}`, { 'rating--disabled': disabled }]"
    role="radiogroup"
    :aria-label="t('book.rating')"
    :aria-disabled="disabled || undefined"
    tabindex="0"
    @keydown="onKey"
  >
    <button
      v-for="star in STARS"
      :key="star"
      type="button"
      class="rating__star rating__star--btn"
      :class="{ 'rating__star--on': value !== null && star <= value }"
      role="radio"
      :aria-checked="star === value"
      :aria-label="t('book.ratingValue', { rating: star, max: MAX })"
      :disabled="disabled"
      tabindex="-1"
      @click="pick(star)"
    >
      <span class="material-symbols-outlined">star</span>
    </button>
    <span class="rating__hint">{{ value === null ? t('book.notRated') : label }}</span>
  </div>

  <!-- Read-only, five glyphs: the surfaces that are about one book. -->
  <div
    v-else-if="variant === 'stars' && value !== null"
    class="rating"
    :class="`rating--${size}`"
    :title="label"
    :aria-label="label"
    role="img"
  >
    <span
      v-for="star in STARS"
      :key="star"
      class="rating__star"
      :class="{ 'rating__star--on': star <= value }"
      aria-hidden="true"
    >
      <span class="material-symbols-outlined">star</span>
    </span>
  </div>

  <!-- Read-only, compact: one star and the number, for lists. -->
  <span
    v-else-if="value !== null"
    class="rating rating--compact"
    :class="`rating--${size}`"
    :title="label"
    :aria-label="label"
  >
    <span class="material-symbols-outlined rating__star rating__star--on" aria-hidden="true">star</span>
    <span class="rating__value">{{ value }}</span>
  </span>
</template>

<style scoped>
.rating {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  line-height: 1;
}
.rating--compact { gap: 3px; }

.rating__star {
  display: inline-flex;
  color: var(--color-outline-variant);
}
/* A set star is an "active/selected" state — the one thing the brass accent is
   reserved for (see the Design System section of CLAUDE.md). */
.rating__star--on { color: var(--color-accent); }
.rating__star .material-symbols-outlined,
.rating__star.material-symbols-outlined {
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
}

.rating--sm .material-symbols-outlined { font-size: 14px; }
.rating--md .material-symbols-outlined { font-size: 16px; }
.rating--lg .material-symbols-outlined { font-size: 24px; }

.rating__value {
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
}
.rating--lg .rating__value { font-size: var(--text-body-md); }

/* ── Editable ─────────────────────────────────────────────────────────── */
.rating--edit {
  gap: 0;
  border-radius: var(--radius-default);
}
.rating--edit:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
.rating__star--btn {
  background: none;
  border: 0;
  padding: 4px;
  cursor: pointer;
  transition: transform 0.15s, color 0.15s;
}
.rating__star--btn:hover:not(:disabled) { transform: scale(1.15); }
.rating--disabled .rating__star--btn,
.rating__star--btn:disabled { cursor: not-allowed; }

.rating__hint {
  margin-left: var(--space-sm);
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

@media (prefers-reduced-motion: reduce) {
  .rating__star--btn { transition: none; }
  .rating__star--btn:hover:not(:disabled) { transform: none; }
}
</style>
