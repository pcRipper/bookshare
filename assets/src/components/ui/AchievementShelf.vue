<script setup>
/**
 * The achievement collection as a header strip, and the modal behind it.
 *
 * Sits where the library's three-figure stat block used to. That block was
 * removed because the numbers restated what the shelves themselves show and it
 * cost the top of every visit a 232px column — so this deliberately does not
 * bring a tall panel back: it shows **only earned badges**, capped, with a `+N`
 * chip, and the whole collection (including the locked families, which is where
 * the ladder is legible) lives one click away in the modal. The overflow chip
 * is modelled on the profile header's existing category-tag overflow.
 *
 * Used by three views — the library, a member profile and the signed-out share
 * page — which is why it lives in `ui/` rather than `profile/`, the same move
 * that pulled SubTabNav out of LibraryView.
 *
 * The strip is **one button**, not a row of them: every badge opens the same
 * dialog, so making each focusable would add ten tab stops that all do the same
 * thing.
 */
import { computed, ref } from 'vue'
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
})

const { t } = useI18n()

const open = ref(false)

/** How many badges fit the strip before the overflow chip takes over. */
const VISIBLE = 4

const earned = computed(() => earnedAchievements(props.items))
const shown = computed(() => earned.value.slice(0, VISIBLE))
const extra = computed(() => Math.max(earned.value.length - VISIBLE, 0))

const ready = computed(() => Array.isArray(props.items) && props.items.length > 0)
</script>

<template>
  <div v-if="ready" class="shelf">
    <button
      class="shelf__trigger"
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
.shelf { min-width: 0; }

/* A button that looks like a row of chips: the affordance is the chips
   themselves, so the trigger contributes only the hover tint and the focus
   ring the rest of the app uses. */
.shelf__trigger {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-xs);
  max-width: 100%;
  margin: 0 -6px;
  padding: 4px 6px;
  border: none;
  border-radius: var(--radius-default);
  background: none;
  text-align: left;
  cursor: pointer;
}
.shelf__trigger:hover { background: var(--color-surface-container-low); }
.shelf__trigger:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

.shelf__more {
  font-size: var(--text-label-sm);
  font-weight: 500;
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
