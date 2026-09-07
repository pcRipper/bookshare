<script setup>
/**
 * The whole achievement collection: every family, earned or not, with what the
 * next tier costs.
 *
 * The locked ones are the reason this exists. The header strip shows only what
 * a member has, so the modal is where the ladder is legible — and a locked row
 * with a progress bar ("38 of 100") says something an absent badge cannot.
 *
 * `md` on the modal scale: a compact single-column list, the same size the
 * import and edit-profile forms take.
 */
import { computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import AchievementBadge from '@/components/ui/AchievementBadge.vue'
import {
  achievementDescriptionKey,
  achievementProgress,
  achievementState,
  earnedAchievements,
} from '@/utils/achievements'

const props = defineProps({
  open: { type: Boolean, default: false },
  // The API's `achievements` array, in catalogue order — never re-sorted here.
  items: { type: Array, default: () => [] },
  // Whose collection this is; null for your own.
  ownerName: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { t } = useI18n()

const earnedCount = computed(() => earnedAchievements(props.items).length)

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-overlay" @click.self="emit('close')">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="t('achievements.title')">
        <header class="modal__header">
          <div>
            <h2 class="modal__title">{{ t('achievements.title') }}</h2>
            <p class="modal__subtitle">
              {{ ownerName
                ? t('achievements.subtitleOther', { name: ownerName, earned: earnedCount, total: items.length })
                : t('achievements.subtitleSelf', { earned: earnedCount, total: items.length }) }}
            </p>
          </div>
          <button class="modal__close" type="button" :aria-label="t('common.close')" @click="emit('close')">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="modal__body">
          <article
            v-for="item in items"
            :key="item.key"
            class="row"
            :class="`row--${achievementState(item)}`"
          >
            <AchievementBadge :item="item" size="md" class="row__badge" />

            <p class="row__desc">{{ t(achievementDescriptionKey(item.key)) }}</p>

            <!-- A maxed family has nothing left to aim at, so it says so
                 instead of showing a bar pinned at 100%. -->
            <p v-if="item.next == null" class="row__progress-text row__progress-text--maxed">
              <span class="material-symbols-outlined">check_circle</span>
              {{ t('achievements.maxed', { value: item.value }) }}
            </p>
            <template v-else>
              <div class="row__bar" role="presentation">
                <div class="row__bar-fill" :style="{ width: `${achievementProgress(item) * 100}%` }" />
              </div>
              <p class="row__progress-text">
                {{ t('achievements.progress', { value: item.value, next: item.next }) }}
              </p>
            </template>

            <p class="row__ladder">{{ t('achievements.ladder', { thresholds: item.thresholds.join(' · ') }) }}</p>
          </article>
        </div>

        <footer class="modal__footer">
          <button class="btn-secondary" type="button" @click="emit('close')">{{ t('common.close') }}</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--modal-gutter);
  background: rgba(48, 49, 46, 0.4);
}
.modal {
  display: flex;
  flex-direction: column;
  width: 100%;
  /* `md` on the shared scale: a compact single-column list, the size the
     import and edit-profile forms take. */
  max-width: var(--modal-w-md);
  max-height: var(--modal-max-h);
  background: var(--color-surface);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.modal__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-md);
  padding: var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.modal__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  font-weight: 700;
  color: var(--color-on-surface);
  margin: 0;
}
.modal__subtitle {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
  margin: 4px 0 0;
}
.modal__close {
  display: flex;
  padding: 4px;
  border: none;
  border-radius: var(--radius-default);
  background: none;
  cursor: pointer;
  color: var(--color-on-surface-variant);
}
.modal__close:hover { background: var(--color-surface-container-low); }

.modal__body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-md);
  display: grid;
  gap: var(--space-sm);
}

.row {
  /* One column: the bar spans the row and the badge and prose stack above it.
     Putting a 6px bar beside a wrapping description lined the two up at no
     width at all. */
  display: grid;
  gap: 6px;
  padding: var(--space-sm);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
}
.row--locked { background: var(--color-surface-container-low); }

.row__badge { justify-self: start; max-width: 100%; }

.row__desc {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
  margin: 0;
}
.row--locked .row__desc { color: var(--color-outline); }

.row__bar {
  height: 6px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-highest);
  overflow: hidden;
}
.row__bar-fill {
  height: 100%;
  border-radius: var(--radius-full);
  background: var(--color-primary);
  transition: width 0.3s;
}
@media (prefers-reduced-motion: reduce) {
  .row__bar-fill { transition: none; }
}

.row__progress-text {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: var(--text-label-sm);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  margin: 0;
}
/* The one place the brass appears outside a maxed badge, and for the same
   reason: it marks the family as finished. */
.row__progress-text--maxed { color: var(--color-accent); }
.row__progress-text .material-symbols-outlined { font-size: 16px; }

.row__ladder {
  font-size: var(--text-label-sm);
  color: var(--color-outline);
  margin: 0;
}

.modal__footer {
  display: flex;
  justify-content: flex-end;
  padding: var(--space-md);
  border-top: 1px solid var(--color-surface-container-highest);
}

.btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px 16px;
  border: 1px solid var(--color-outline);
  border-radius: var(--radius-default);
  background: none;
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  cursor: pointer;
}
.btn-secondary:hover { background: var(--color-surface-container-low); }
</style>
