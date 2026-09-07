<script setup>
/**
 * One achievement family, as a badge.
 *
 * The tier is shown as a level meter rather than a word or a metal name: three
 * tiers in a navy-and-brass palette have no "gold" or "silver" to borrow, and a
 * meter needs no translation and no room. The count comes from the payload's
 * `tiers`, so a fourth tier added to the catalogue needs no change here.
 *
 * **Bars in a track, not dots.** Three round pips sitting inline after the
 * label read as an ellipsis — "Collector •••" looks like a truncated name, not
 * a tier — so they are upright bars inside their own light capsule, which reads
 * as a gauge no matter how many are filled.
 *
 * The state (locked / earned / maxed) is derived from the item, not passed in,
 * so a caller cannot mislabel a badge — the same reasoning behind LoanCard
 * deriving its variant from (perspective, status) rather than taking one.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { achievementIcon, achievementNameKey, achievementState } from '@/utils/achievements'

const props = defineProps({
  // One entry from the API's `achievements` array.
  item: { type: Object, required: true },
  // 'sm' for the header strip, 'md' for the modal's grid.
  size: { type: String, default: 'sm' },
  // The header strip is one button, so its badges must not be focusable
  // themselves; the modal's are inert cells.
  showLabel: { type: Boolean, default: true },
})

const { t } = useI18n()

const state = computed(() => achievementState(props.item))
const icon = computed(() => achievementIcon(props.item.key))
const name = computed(() => t(achievementNameKey(props.item.key)))
</script>

<template>
  <div
    class="badge"
    :class="[`badge--${state}`, `badge--${size}`]"
    :title="showLabel ? null : name"
  >
    <span class="material-symbols-outlined badge__icon">{{ icon }}</span>

    <span v-if="showLabel" class="badge__name">{{ name }}</span>

    <!-- The meter: filled up to the tier reached. A locked family shows the
         empty ladder rather than nothing, so the modal's rows stay one height. -->
    <span class="badge__meter" :aria-label="t('achievements.tierOf', { tier: item.tier, total: item.tiers })">
      <i
        v-for="level in item.tiers"
        :key="level"
        class="badge__bar"
        :class="{ 'badge__bar--on': level <= item.tier }"
      />
    </span>
  </div>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  max-width: 100%;
  padding: 4px 10px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
  color: var(--color-secondary);
}

/* Earned: navy, the app's primary. Locked: the muted outline on the low
   container, the same "present but not yours" treatment used elsewhere. Maxed
   is the only state that spends the brass accent. */
.badge--earned {
  border-color: var(--color-primary-fixed-dim);
  background: var(--color-primary-fixed);
  color: var(--color-primary);
}
.badge--maxed {
  border-color: var(--color-accent);
  background: var(--color-accent-container);
  color: var(--color-tertiary);
}
.badge--locked {
  background: var(--color-surface-container-low);
  color: var(--color-outline);
}

.badge__icon {
  flex-shrink: 0;
  font-size: 18px;
}
.badge--maxed .badge__icon,
.badge--earned .badge__icon {
  /* A filled glyph for a family you hold, outlined for one you don't — the
     same signal the tab strip's icons use for selection. */
  font-variation-settings: 'FILL' 1;
}
.badge--md .badge__icon { font-size: 22px; }

.badge__name {
  margin-right: 2px;
  font-size: var(--text-label-md);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.badge--md .badge__name { font-size: var(--text-body-md); }

/* The capsule is what stops the bars reading as punctuation: a light track
   behind them turns three marks into one gauge. White works on all three of
   the badge's tinted grounds, so the track needs no per-state rule. */
.badge__meter {
  display: inline-flex;
  align-items: flex-end;
  flex-shrink: 0;
  gap: 2px;
  padding: 3px 4px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
}
.badge__bar {
  width: 3px;
  height: 8px;
  border-radius: 1px;
  background: currentColor;
  opacity: 0.2;
}
.badge__bar--on { opacity: 1; }
.badge--md .badge__bar { height: 10px; }
</style>
