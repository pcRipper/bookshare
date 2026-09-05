<script setup>
/**
 * The admin panel's Dumps tab: make a backup now, keep the last ten, take one
 * away.
 *
 * The two kinds are presented as two different things rather than a format
 * dropdown, because they are: only the SQL archive can be restored from. A
 * picker would imply they are interchangeable, which is exactly the belief that
 * gets someone to a disaster holding the wrong file.
 */
import { onMounted, computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminDumpsStore } from '@/stores/adminDumps'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import { relativeTime } from '@/utils/time'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const store = useAdminDumpsStore()
const toast = useToastStore()
const { t, n } = useI18n()

const { items, capabilities, loading, creating, error } = storeToRefs(store)

onMounted(load)

async function load() {
  try {
    await store.fetchDumps()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.dumps.loadFailed')))
  }
}

/* ── creating ──────────────────────────────────────────────────────────── */

const kinds = computed(() => [
  {
    key: 'sql',
    label: t('admin.dumps.createSql'),
    hint: t('admin.dumps.sqlHint'),
    icon: 'database',
  },
  {
    key: 'json',
    label: t('admin.dumps.createJson'),
    hint: t('admin.dumps.jsonHint'),
    icon: 'data_object',
  },
])

async function create(kind) {
  try {
    await store.create(kind)
    toast.success(t('admin.dumps.created'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.dumps.createFailed')))
  }
}

/* ── per-row actions ───────────────────────────────────────────────────── */

// Per-row, so one download does not disable the whole table.
const busy = ref(null)
const confirming = ref(null)

async function download(dump) {
  busy.value = dump.name
  try {
    await store.download(dump.name)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.dumps.downloadFailed')))
  } finally {
    busy.value = null
  }
}

async function remove(dump) {
  busy.value = dump.name
  try {
    await store.remove(dump.name)
    toast.success(t('admin.dumps.deleted'))
    confirming.value = null
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.dumps.deleteFailed')))
  } finally {
    busy.value = null
  }
}

/* ── formatting ────────────────────────────────────────────────────────── */

/** Binary units, since this is a file size and the operator is reading disk. */
function size(bytes) {
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }

  return `${n(Math.round(value * 10) / 10)} ${units[unit]}`
}
</script>

<template>
  <div class="dumps">
    <p class="dumps__lead">{{ t('admin.dumps.lead') }}</p>

    <div class="dumps__actions">
      <div v-for="kind in kinds" :key="kind.key" class="dumps__kind">
        <button
          class="dumps__create"
          type="button"
          :disabled="!capabilities[kind.key] || !!creating"
          @click="create(kind.key)"
        >
          <BaseSpinner v-if="creating === kind.key" size="sm" />
          <span v-else class="material-symbols-outlined">{{ kind.icon }}</span>
          {{ kind.label }}
        </button>
        <p class="dumps__hint">
          {{ kind.hint }}
          <!-- pg_dump is simply not installed outside the container, which is
               the normal state of a dev machine — so this explains rather than
               reporting a fault. -->
          <span v-if="!capabilities[kind.key]" class="dumps__unavailable">
            {{ t('admin.dumps.unavailable') }}
          </span>
        </p>
      </div>
    </div>

    <div v-if="error === 'forbidden'" class="dumps__state">
      <span class="material-symbols-outlined dumps__state-icon">lock</span>
      <p>{{ t('admin.forbidden') }}</p>
    </div>

    <div v-else-if="error && !items.length" class="dumps__state">
      <span class="material-symbols-outlined dumps__state-icon">error</span>
      <p>{{ t('admin.dumps.loadFailed') }}</p>
      <button class="dumps__state-link" @click="load">{{ t('common.retry') }}</button>
    </div>

    <div v-else-if="loading && !items.length" class="dumps__loading">
      <BaseSpinner size="lg" />
    </div>

    <div v-else-if="!items.length" class="dumps__state">
      <span class="material-symbols-outlined dumps__state-icon">inventory_2</span>
      <p>{{ t('admin.dumps.empty') }}</p>
    </div>

    <div v-else class="dumps__scroll">
      <table class="dumps__table">
        <thead>
          <tr>
            <th scope="col">{{ t('admin.dumps.colName') }}</th>
            <th scope="col">{{ t('admin.dumps.colKind') }}</th>
            <th scope="col" class="dumps__num">{{ t('admin.dumps.colSize') }}</th>
            <th scope="col">{{ t('admin.dumps.colCreated') }}</th>
            <th scope="col"><span class="sr-only">{{ t('admin.dumps.colActions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="dump in items" :key="dump.name">
            <td class="dumps__name">{{ dump.name }}</td>
            <td>
              <span class="dumps__tag" :class="dump.restorable ? 'dumps__tag--restorable' : 'dumps__tag--rows'">
                {{ dump.restorable ? t('admin.dumps.restorable') : t('admin.dumps.rowsOnly') }}
              </span>
            </td>
            <td class="dumps__num">{{ size(dump.bytes) }}</td>
            <td class="dumps__muted">{{ relativeTime(dump.createdAt) }}</td>
            <td class="dumps__actions-cell">
              <div class="dumps__row-actions">
                <BaseSpinner v-if="busy === dump.name" size="sm" />
                <template v-else-if="confirming === dump.name">
                  <span class="dumps__confirm">{{ t('admin.dumps.confirmDelete') }}</span>
                  <button class="dumps__action dumps__action--danger" type="button" @click="remove(dump)">
                    {{ t('common.delete') }}
                  </button>
                  <button class="dumps__action" type="button" @click="confirming = null">
                    {{ t('common.cancel') }}
                  </button>
                </template>
                <template v-else>
                  <button class="dumps__action" type="button" @click="download(dump)">
                    {{ t('admin.dumps.download') }}
                  </button>
                  <button
                    class="dumps__action dumps__action--danger"
                    type="button"
                    @click="confirming = dump.name"
                  >
                    {{ t('common.delete') }}
                  </button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="dumps__retention">{{ t('admin.dumps.retention') }}</p>
  </div>
</template>

<style scoped>
.dumps {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.dumps__lead {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  max-width: 70ch;
}

.dumps__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
}
.dumps__kind {
  flex: 1 1 280px;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.dumps__create {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.dumps__create:hover:not(:disabled) {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
  border-color: var(--color-outline);
}
.dumps__create:disabled { opacity: 0.5; cursor: not-allowed; }
.dumps__create .material-symbols-outlined { font-size: 18px; }
.dumps__hint {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}
.dumps__unavailable {
  display: block;
  margin-top: 2px;
  color: var(--color-tertiary);
  font-weight: 500;
}

.dumps__scroll {
  overflow-x: auto;
  /* Positioned so the absolutely-positioned .sr-only header label stays inside
     the scroll box — see AdminMembersView for the whole story. */
  position: relative;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
}
.dumps__table {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
  font-size: var(--text-body-sm);
}
.dumps__table th {
  text-align: left;
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-on-surface-variant);
  border-bottom: 1px solid var(--color-outline-variant);
  white-space: nowrap;
}
.dumps__table td {
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-high);
  vertical-align: middle;
}
.dumps__table tbody tr:last-child td { border-bottom: none; }

.dumps__name {
  font-family: var(--font-mono, monospace);
  color: var(--color-on-surface);
  white-space: nowrap;
}
.dumps__num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.dumps__muted { color: var(--color-on-surface-variant); white-space: nowrap; }

.dumps__tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-sm);
  font-weight: 600;
  white-space: nowrap;
}
/* The restorable one wears the primary ink; the other is deliberately quiet.
   Which of these you are holding is the only thing that matters in a crisis. */
.dumps__tag--restorable { background: var(--color-primary); color: var(--color-on-primary); }
.dumps__tag--rows { background: var(--color-surface-container-high); color: var(--color-on-surface-variant); }

/* The flex row goes on a wrapper, never on the <td>: a table cell made
   `display: flex` stops being a table cell and spills past the table's edge. */
.dumps__actions-cell { text-align: right; }
.dumps__row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-xs);
  white-space: nowrap;
}
.dumps__confirm {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}
.dumps__action {
  padding: 5px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.dumps__action:hover {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
  border-color: var(--color-outline);
}
.dumps__action--danger { color: var(--color-error); border-color: var(--color-error); }
.dumps__action--danger:hover { background: var(--color-error); color: #ffffff; }

.dumps__retention {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}

.dumps__loading {
  display: flex;
  justify-content: center;
  padding: var(--space-xl) 0;
  color: var(--color-primary);
}
.dumps__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xl) var(--space-md);
  text-align: center;
  color: var(--color-on-surface-variant);
}
.dumps__state-icon { font-size: 40px; opacity: 0.6; }
.dumps__state-link {
  font-weight: 600;
  color: var(--color-primary);
}

.sr-only {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
