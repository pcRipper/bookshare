<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { currentLocale } from '@/i18n'
import { relativeTime } from '@/utils/time'

const { t } = useI18n()

const props = defineProps({
  // Ordered lifecycle events from the API: { id, type, createdAt, dueDate, actor }.
  events: { type: Array, default: () => [] },
})

// Presentation for each event type: icon, catalog key, and a tone class.
const META = {
  requested:        { icon: 'bookmark_add',     key: 'requested',       tone: 'neutral' },
  approved:         { icon: 'check_circle',      key: 'approved',        tone: 'positive' },
  declined:         { icon: 'cancel',            key: 'declined',        tone: 'negative' },
  return_requested: { icon: 'assignment_return', key: 'returnRequested', tone: 'neutral' },
  returned:         { icon: 'task_alt',          key: 'returned',        tone: 'positive' },
}

function fmtDate(iso) {
  return new Date(iso).toLocaleDateString(currentLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
}

// Full date + time of day, so each step shows exactly when it happened.
function fmtDateTime(iso) {
  return new Date(iso).toLocaleString(currentLocale(), {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

const steps = computed(() =>
  props.events.map(e => {
    const meta = META[e.type]
    return {
      id: e.id,
      icon: meta?.icon ?? 'circle',
      // An unmapped event type falls back to its raw name rather than a blank step.
      label: meta ? t(`requests.timeline.${meta.key}`) : e.type,
      tone: meta?.tone ?? 'neutral',
      actor: e.actor?.fullName ?? t('requests.timeline.someone'),
      when: relativeTime(e.createdAt),
      at: fmtDateTime(e.createdAt),
      // Surfaced only on the approval step.
      due: e.type === 'approved' && e.dueDate ? fmtDate(e.dueDate) : null,
      // Optional note (owner's reason on a decline).
      note: e.message || null,
    }
  }),
)
</script>

<template>
  <ol class="timeline">
    <li v-for="step in steps" :key="step.id" class="timeline__step">
      <span class="timeline__marker" :class="`timeline__marker--${step.tone}`">
        <span class="material-symbols-outlined">{{ step.icon }}</span>
      </span>
      <div class="timeline__body">
        <p class="timeline__label">
          {{ step.label }}
          <span class="timeline__actor">{{ t('requests.timeline.by', { actor: step.actor }) }}</span>
        </p>
        <p class="timeline__meta">
          <time class="timeline__at">{{ step.at }}</time>
          <span class="timeline__rel">· {{ step.when }}</span>
          <span v-if="step.due" class="timeline__due">{{ t('requests.timeline.dueOn', { date: step.due }) }}</span>
        </p>
        <p v-if="step.note" class="timeline__note">“{{ step.note }}”</p>
      </div>
    </li>
  </ol>
</template>

<style scoped>
.timeline {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}

.timeline__step {
  position: relative;
  display: flex;
  gap: var(--space-sm);
  padding-bottom: var(--space-sm);
}
.timeline__step:last-child { padding-bottom: 0; }

/* Connector line between markers */
.timeline__step:not(:last-child) .timeline__marker::after {
  content: '';
  position: absolute;
  top: 24px;
  left: 11px;
  width: 2px;
  height: calc(100% - 24px);
  background: var(--color-outline-variant);
}

.timeline__marker {
  position: relative;
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
.timeline__marker .material-symbols-outlined { font-size: 16px; }
.timeline__marker--positive { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
.timeline__marker--negative { background: var(--color-error-container); color: var(--color-error); }

.timeline__body { padding-top: 2px; min-width: 0; }
.timeline__label {
  margin: 0;
  font-size: var(--text-label-md);
  font-weight: 600;
  color: var(--color-on-background);
}
.timeline__actor { font-weight: 400; color: var(--color-secondary); }
.timeline__meta {
  margin: 0;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.timeline__at { font-weight: 600; color: var(--color-secondary); }
.timeline__rel { opacity: 0.85; }
.timeline__due { color: var(--color-secondary); }
.timeline__note {
  margin: var(--space-xs) 0 0;
  font-size: var(--text-label-md);
  font-style: italic;
  color: var(--color-on-surface-variant);
}
</style>
