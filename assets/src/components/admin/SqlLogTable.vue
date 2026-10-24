<script setup>
/**
 * The shared query log: every sandbox attempt by every operator, newest first —
 * run, previewed, committed, refused or failed.
 *
 * This is the record of what the sandbox changed. Its statements run on a
 * connection auditor-bundle never sees, so no `_audit` row exists for them;
 * and the sandbox role cannot read or touch the table behind this list.
 *
 * A statement is clipped to one line until its row is opened, which also
 * reveals the error, if any.
 */
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminSqlStore } from '@/stores/adminSql'
import { relativeTime } from '@/utils/time'
import Pagination from '@/components/ui/Pagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const store = useAdminSqlStore()
const { t, n } = useI18n()
const { log, logLoading } = storeToRefs(store)

const open = ref(null)

function toggle(id) {
  open.value = open.value === id ? null : id
}
</script>

<template>
  <div class="sql-log">
    <div v-if="logLoading && !log.items.length" class="sql-log__loading">
      <BaseSpinner size="lg" />
    </div>

    <p v-else-if="!log.items.length" class="sql-log__empty">{{ t('admin.sql.log.empty') }}</p>

    <template v-else>
      <div class="sql-log__scroll">
        <table class="sql-log__table">
          <thead>
            <tr>
              <th scope="col">{{ t('admin.sql.log.colWhen') }}</th>
              <th scope="col">{{ t('admin.sql.log.colOperator') }}</th>
              <th scope="col">{{ t('admin.sql.log.colOutcome') }}</th>
              <th scope="col">{{ t('admin.sql.log.colStatement') }}</th>
              <th scope="col" class="sql-log__num">{{ t('admin.sql.log.colRows') }}</th>
              <th scope="col" class="sql-log__num">{{ t('admin.sql.log.colDuration') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in log.items" :key="entry.id">
              <td class="sql-log__muted" :title="entry.executedAt">{{ relativeTime(entry.executedAt) }}</td>
              <td>{{ entry.operator ?? t('admin.sql.log.removedOperator') }}</td>
              <td>
                <span class="sql-log__pill" :class="`sql-log__pill--${entry.outcome}`">
                  {{ t(`admin.sql.log.outcome.${entry.outcome}`) }}
                </span>
              </td>
              <td class="sql-log__statement-cell">
                <button
                  class="sql-log__statement"
                  :class="{ 'sql-log__statement--open': open === entry.id }"
                  type="button"
                  :aria-expanded="open === entry.id"
                  @click="toggle(entry.id)"
                >{{ entry.statement }}</button>
                <pre v-if="open === entry.id && entry.error" class="sql-log__error">{{ entry.error }}</pre>
              </td>
              <td class="sql-log__num">{{ entry.rowCount === null ? '—' : n(entry.rowCount) }}</td>
              <td class="sql-log__num sql-log__muted">{{ t('admin.sql.duration', { ms: n(entry.durationMs) }) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination
        :page="log.page"
        :total-pages="log.totalPages"
        :disabled="logLoading"
        @change="store.fetchLog($event)"
      />
    </template>
  </div>
</template>

<style scoped>
.sql-log { display: flex; flex-direction: column; gap: var(--space-sm); }

.sql-log__scroll {
  overflow-x: auto;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
}
.sql-log__table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  font-size: var(--text-label-md);
}
.sql-log__table th {
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
.sql-log__table td {
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-high);
  vertical-align: top;
}
.sql-log__table tbody tr:last-child td { border-bottom: none; }

.sql-log__num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.sql-log__muted { color: var(--color-on-surface-variant); white-space: nowrap; }

/* max-width: 0 lets the cell take the leftover width and clip inside it,
   instead of one long statement widening the whole table. */
.sql-log__statement-cell { width: 50%; max-width: 0; }
.sql-log__statement {
  display: block;
  width: 100%;
  padding: 0;
  border: none;
  background: none;
  text-align: left;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: var(--text-label-sm);
  color: var(--color-on-surface);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
}
.sql-log__statement--open { white-space: pre-wrap; word-break: break-word; }
.sql-log__error {
  margin: var(--space-xs) 0 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: var(--text-label-sm);
  color: var(--color-error);
}

.sql-log__pill {
  display: inline-block;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-sm);
  font-weight: 600;
  white-space: nowrap;
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
/* Only a committed change wears the primary ink: it is the one outcome that
   altered the database. Refusals and failures share the error tone. */
.sql-log__pill--committed { background: var(--color-primary); color: var(--color-on-primary); }
.sql-log__pill--rejected,
.sql-log__pill--failed { background: var(--color-error-container); color: var(--color-error); }

.sql-log__loading {
  display: flex;
  justify-content: center;
  padding: var(--space-lg) 0;
  color: var(--color-primary);
}
.sql-log__empty {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}
</style>
