<script setup>
/**
 * The achievement collection as a header strip, and the modal behind it.
 *
 * Sits where the library's three-figure stat block used to. That block was
 * removed because the numbers restated what the shelves themselves show and it
 * cost the top of every visit a 232px column — so this deliberately does not
 * bring a tall panel back: it shows **only earned badges**, capped, with a `+N`
 * chip, and the whole collection (including the locked families, which is where
 * the ladder is legible) lives one click away in the modal.
 *
 * Used by three views — the library, a member profile and the signed-out share
 * page — which is why it lives in `ui/` rather than `profile/`, the same move
 * that pulled SubTabNav out of LibraryView.
 *
 * The strip is **one button**, not a row of them: every badge opens the same
 * dialog, so making each focusable would add ten tab stops that all do the same
 * thing.
 *
 * **On a phone there is no cap at all**, and that is the fix for the staircase.
 * The ugliness was never the number of badges — it was that labelled pills are
 * all different widths, so four of them wrapped into a four-deep ragged column.
 * Compact medals (see AchievementBadge, which drops the label below 768px) are
 * every one the same width, so the whole earned collection wraps into two tidy
 * rows in less height than four ragged ones took, and shows everything instead
 * of four of nine behind an arbitrary cut. The cap exists only where the badges
 * are labelled and a row is genuinely finite.
 *
 * That breakpoint is the one thing here needing `matchMedia` rather than CSS:
 * the `+N` count has to agree with how many badges are actually rendered.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AchievementBadge from '@/components/ui/AchievementBadge.vue'
import AchievementsModal from '@/components/ui/AchievementsModal.vue'
import { earnedAchievements } from '@/utils/achievements'

const props = defineProps({
  // The API's `achievements` array. Null while the profile is still loading —
  // the strip then renders nothing rather than an empty state that would flash.
  items: { type: Array, default: null },
  // Whose collection this is; null for your own, which changes only the wording.
  ownerName: { type: String, default: null },
  /*
   * How the medals sit in their row **on a phone**: 'start' for the library,
   * whose header is left-aligned, 'center' for the profile and share pages,
   * whose identity column is centred there and left-aligned from 768px up — so
   * the centring is scoped to that breakpoint rather than applied outright.
   *
   * A prop because the row lives inside this component's scope and a caller
   * cannot style it; `:deep()` would work but appears nowhere else in the app,
   * and alignment is genuinely the caller's business.
   */
  align: { type: String, default: 'start' },
})

const { t } = useI18n()

const open = ref(false)

/** How many labelled badges fit the header's one row. Phones show them all. */
const VISIBLE_LABELLED = 4

// Matches AchievementBadge's own breakpoint. Kept in a `ref` with a listener
// rather than read once, so a rotation or a resized window doesn't leave the
// "+N" chip disagreeing with the badges beside it.
const wide = ref(true)
let media = null
if (typeof window !== 'undefined' && window.matchMedia) {
  media = window.matchMedia('(min-width: 768px)')
  wide.value = media.matches
  const onChange = e => { wide.value = e.matches }
  media.addEventListener('change', onChange)
  onBeforeUnmount(() => media.removeEventListener('change', onChange))
}

const earned = computed(() => earnedAchievements(props.items))
// Uncapped on a phone, so `extra` is 0 there and no overflow chip is rendered.
const cap = computed(() => (wide.value ? VISIBLE_LABELLED : earned.value.length))
const shown = computed(() => earned.value.slice(0, cap.value))
const extra = computed(() => Math.max(earned.value.length - cap.value, 0))

const ready = computed(() => Array.isArray(props.items) && props.items.length > 0)
</script>

<template>
  <div v-if="ready" class="shelf">
    <button
      class="shelf__trigger"
      :class="{ 'shelf__trigger--centered': align === 'center' }"
      type="button"
      :aria-label="t('achievements.viewAll')"
      @click="open = true"
    >
      <template v-if="earned.length">
        <AchievementBadge
          v-for="item in shown"
          :key="item.key"
          :item="item"
          size="sm"
        />
        <!-- The overflow count reads as one more chip in the row rather than
             loose text after it, which stranded on its own line whenever the
             badges happened to fill the last one. -->
        <span v-if="extra" class="shelf__more">{{ t('achievements.more', { count: extra }) }}</span>
      </template>

      <!-- Nothing earned yet. Named rather than hidden: a member who can't see
           the feature can't discover that cataloguing a book starts it. -->
      <span v-else class="shelf__empty">
        <span class="material-symbols-outlined">workspace_premium</span>
        {{ ownerName ? t('achievements.emptyOther', { name: ownerName }) : t('achievements.emptySelf') }}
      </span>
    </button>

    <AchievementsModal
      :open="open"
      :items="items"
      :owner-name="ownerName"
      @close="open = false"
    />
  </div>
</template>

<style scoped>
/* Full width, with the row itself hugging its chips.
   Both halves matter. In a column flex parent with `align-items: flex-start`
   (the profile and share headers) the shelf would otherwise shrink-to-fit — and
   the trigger's -6px side margins make that fit 12px narrower than the row
   actually needs, so the last chip wrapped to its own line with hundreds of
   pixels of free space beside it. `fit-content` on the trigger then keeps the
   hover tint hugging the chips instead of spanning the whole column. */
.shelf {
  width: 100%;
  min-width: 0;
}

/* A button that looks like a row of chips: the affordance is the chips
   themselves, so the trigger contributes only the hover tint and the focus
   ring the rest of the app uses. */
.shelf__trigger {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  width: fit-content;
  max-width: 100%;
  /* No side padding and **no negative margin**. A -6px margin was buying the
     hover tint some bleed while keeping the first chip aligned with the text
     above, but it also subtracted 12px from this row's max-content
     contribution — so every shrink-to-fit ancestor (the profile and share
     header columns are both content-sized flex items) sized the row 12px
     narrower than it needs and the last chip wrapped to its own line with
     hundreds of pixels free beside it. The bleed comes from a box-shadow on
     hover instead, which paints outside the box without touching layout. */
  padding: 4px 0;
  border: none;
  border-radius: var(--radius-default);
  background: none;
  text-align: left;
  cursor: pointer;
}
.shelf__trigger:hover {
  background: var(--color-surface-container-low);
  /* Stands in for the 6px of side padding this row deliberately doesn't have. */
  box-shadow: 0 0 0 6px var(--color-surface-container-low);
}
.shelf__trigger:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
/* Phones centre the identity column on the profile and share pages, so the
   medals centre with it. Only there, and only at that width: both columns are
   left-aligned from 768px up, and the library's header is left-aligned at every
   width. */
@media (max-width: 767px) {
  .shelf__trigger--centered {
    /* The row is fit-content, so centring its *contents* is not enough — the
       row itself has to be centred in the shelf. */
    width: 100%;
    justify-content: center;
  }
}

/* Shaped like a badge so the row ends on a chip, not on stray text — and on
   the same chip recipe, so it matches the badges beside it and the count chips
   elsewhere on the page. */
.shelf__more {
  display: inline-flex;
  align-items: center;
  /* Stretches to the badges' height instead of to its own text, so the row's
     chips share one baseline box — a badge is sized by its 15px icon, this by
     12px text, and left alone the two sat half a pixel apart. */
  align-self: stretch;
  padding: 3px 8px;
  border: 1px dashed var(--color-outline-variant);
  border-radius: var(--radius-full);
  font-family: var(--font-body);
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: var(--ls-label-sm);
  font-weight: 600;
  color: var(--color-on-surface-variant);
  white-space: nowrap;
}

.shelf__empty {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  color: var(--color-outline);
}
.shelf__empty .material-symbols-outlined { font-size: 18px; }
</style>
