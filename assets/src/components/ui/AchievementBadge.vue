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
 * **The `sm` badge drops its label below 768px.** A labelled pill is ~110px, so
 * a phone fitted three per row and the strip became a four-deep ragged
 * staircase — reintroducing exactly the vertical column the stat block was
 * removed to reclaim. Compact, it is an icon and its meter: a medal. The name
 * is a tap away in the modal, and stays on the badge's accessible name, which
 * is why that is set explicitly rather than left to the (display:none, and so
 * untree'd) label.
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
  // 'sm' for the header strip (label hidden on phones), 'md' for the modal.
  size: { type: String, default: 'sm' },
})

const { t } = useI18n()

const state = computed(() => achievementState(props.item))
const icon = computed(() => achievementIcon(props.item.key))
const name = computed(() => t(achievementNameKey(props.item.key)))
const tier = computed(() => t('achievements.tierOf', { tier: props.item.tier, total: props.item.tiers }))

// One accessible name for the whole badge, so it reads the same whether the
// label is on screen or hidden by the compact breakpoint. The parts are then
// decorative, and the meter needs no label of its own.
const label = computed(() => `${name.value} — ${tier.value}`)
</script>

<template>
  <div
    class="badge"
    :class="[`badge--${state}`, `badge--${size}`]"
    role="img"
    :aria-label="label"
    :title="label"
  >
    <span class="material-symbols-outlined badge__icon" aria-hidden="true">{{ icon }}</span>

    <span class="badge__name" aria-hidden="true">{{ name }}</span>

    <!-- The meter: filled up to the tier reached. A locked family shows the
         empty ladder rather than nothing, so the modal's rows stay one height. -->
    <span class="badge__meter" aria-hidden="true">
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
/* Sized off the app's chip recipe — the one CategoryTag and `.count-chip`
   already use — rather than off numbers of its own: 12px/600 on the label
   scale, a pill radius, 8px of side padding. A badge that came out 27px next to
   23px chips read as a different kind of object.

   The typography is set on the **root**, not just on the label: the strip's
   trigger is a <button>, and a badge that declared no font inherited the UA's
   13.33px/400 through it, which is why nothing here lined up with the tags a
   few pixels away. */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  max-width: 100%;
  padding: 3px 8px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
  color: var(--color-secondary);
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
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
  /* `line-height: 1` so the glyph's own leading doesn't set the chip's height —
     that is what pushed the box past the 23px the app's chips sit at. */
  font-size: 15px;
  line-height: 1;
}
.badge--maxed .badge__icon,
.badge--earned .badge__icon {
  /* A filled glyph for a family you hold, outlined for one you don't — the
     same signal the tab strip's icons use for selection. */
  font-variation-settings: 'FILL' 1;
}
.badge--md {
  gap: 6px;
  padding: 4px 10px;
  font-size: var(--text-label-md);
}
.badge--md .badge__icon { font-size: 18px; }

.badge__name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Compact: the strip's badges lose their label on a phone and become medals.
   The modal's `md` badges keep theirs — there the label is the row's heading
   and the sheet is full width, so nothing is cramped. */
@media (max-width: 767px) {
  .badge--sm {
    gap: 4px;
    padding: 3px 6px;
  }
  .badge--sm .badge__name { display: none; }
}

/* The capsule is what stops the bars reading as punctuation: a light track
   behind them turns three marks into one gauge. White works on all three of
   the badge's tinted grounds, so the track needs no per-state rule. */
.badge__meter {
  display: inline-flex;
  align-items: flex-end;
  flex-shrink: 0;
  gap: 2px;
  padding: 2px 4px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
}
.badge__bar {
  width: 3px;
  height: 6px;
  border-radius: 1px;
  background: currentColor;
  opacity: 0.2;
}
.badge__bar--on { opacity: 1; }
.badge--md .badge__bar { height: 8px; }
</style>
