<script setup>
/**
 * The second-level tab control: a strip of pills inside a rounded track.
 *
 * One control for both of the Library's split tabs — Books (Books /
 * Collections / Wish List) and Sharing (Borrowing / Lending) — so a panel's
 * inner axis always looks and behaves the same wherever it appears. It was the
 * loan history's own toggle before Sharing promoted it to a subtab strip; this
 * component is that markup lifted out once a second caller wanted it.
 *
 * Each item is `{ key, label, icon?, badge?, count? }`. `badge` is the navy
 * pill used for things that want your attention (a pending request); `count` is
 * the muted one used for sizes (how many books are on a shelf). They are
 * deliberately different affordances — a shelf being large is not a task.
 *
 * Arrow keys move between pills, matching the tablist they sit under.
 */
const props = defineProps({
  modelValue: { type: String, required: true },
  items: { type: Array, required: true },
  ariaLabel: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

function onKeydown(e) {
  const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0
  if (!dir) return
  e.preventDefault()
  const i = Math.max(props.items.findIndex(it => it.key === props.modelValue), 0)
  const next = (i + dir + props.items.length) % props.items.length
  emit('update:modelValue', props.items[next].key)
  // Follow the selection with focus, so the next arrow press continues from it.
  // By index, not by `[aria-selected]` — that attribute hasn't moved yet.
  e.currentTarget.querySelectorAll('[role="tab"]')[next]?.focus()
}
</script>

<template>
  <div class="subtab-nav" role="tablist" :aria-label="ariaLabel" @keydown="onKeydown">
    <button
      v-for="item in items"
      :key="item.key"
      class="subtab-nav__btn"
      :class="{ 'subtab-nav__btn--active': modelValue === item.key }"
      type="button"
      role="tab"
      :aria-selected="modelValue === item.key"
      :tabindex="modelValue === item.key ? 0 : -1"
      @click="emit('update:modelValue', item.key)"
    >
      <span v-if="item.icon" class="material-symbols-outlined subtab-nav__icon">{{ item.icon }}</span>
      <span class="subtab-nav__label">{{ item.label }}</span>
      <span v-if="item.badge" class="subtab-nav__badge">{{ item.badge }}</span>
      <span v-else-if="item.count" class="subtab-nav__count">{{ item.count }}</span>
    </button>
  </div>
</template>

<style scoped>
.subtab-nav {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  margin: var(--space-sm) 0;
  max-width: 100%;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
}
.subtab-nav__btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 6px 16px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  transition: background 0.2s, color 0.2s;
}
.subtab-nav__btn:hover:not(.subtab-nav__btn--active) { color: var(--color-on-background); }
.subtab-nav__btn--active {
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 600;
}
.subtab-nav__label {
  overflow: hidden;
  text-overflow: ellipsis;
}
.subtab-nav__icon {
  font-size: 18px;
  flex-shrink: 0;
  /* The optical weight of a filled pill comes from the label; the icon is a
     locator, so it stays a step lighter than the text beside it. */
  font-variation-settings: 'wght' 400;
}


.subtab-nav__badge,
.subtab-nav__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}
.subtab-nav__badge {
  background: var(--color-outline);
  color: var(--color-on-primary);
}
.subtab-nav__count {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
/* Both markers use a fill the active pill also wears, so invert them there or
   the number disappears into its own background. */
.subtab-nav__btn--active .subtab-nav__badge,
.subtab-nav__btn--active .subtab-nav__count {
  background: var(--color-on-primary);
  color: var(--color-primary);
}
/* Phones: the pills share the row instead of each taking its content width.
   At content width three translated labels overflow the track — measured at
   390 wide, German, Spanish and French all did, and an inline-flex track with
   `nowrap` children pushes the last pill out of reach rather than scrolling to
   it. Equal segments cannot overflow, so the strip stays one row in every
   locale and the longest label ellipsises instead of escaping. */
@media (max-width: 767px) {
  .subtab-nav {
    display: flex;
    width: 100%;
  }
  .subtab-nav__btn {
    flex: 1 1 0;
    min-width: 0;
    justify-content: center;
    padding-inline: 10px;
  }
  /* Even sharing the row, three labels plus three counts don't fit a 390-wide
     phone in German or Spanish — the counts cost ~28px each and truncated
     "Sammlungen" and "Wunschliste" to fit. So the count yields and the badge
     does not: a count is how big a shelf is, a badge is something waiting for
     you, and only one of those is worth a clipped label. */
  .subtab-nav__count { display: none; }
}
/* Below that the icon is the half carrying least meaning beside a written
   label, so it yields the space first. */
@media (max-width: 420px) {
  .subtab-nav__icon { display: none; }
}
</style>
