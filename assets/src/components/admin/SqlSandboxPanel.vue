<script setup>
/**
 * The SQL sandbox: one statement at a time against the live database.
 *
 * The rules shown under the editor are the server's, stated rather than
 * enforced here — StatementGuard and the `bookshare_sandbox` role do the
 * enforcing, and the limits come from GET /admin/sql so the copy cannot drift.
 * The UI's job is the two-step write: a preview that says how many rows would
 * change, and a separate Commit that the server re-checks against that count.
 */
import { computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminSqlStore } from '@/stores/adminSql'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const store = useAdminSqlStore()
const toast = useToastStore()
const { t, n } = useI18n()

const { capabilities, limits, running, result, preview, failure } = storeToRefs(store)

const sql = ref('')

const canRun = computed(() => capabilities.value.sandbox && sql.value.trim() !== '' && !running.value)

const limitsHint = computed(() =>
  t('admin.sql.limits', {
    rows: n(limits.value.maxRows),
    affected: n(limits.value.maxAffected),
    seconds: n(limits.value.timeoutMs / 1000),
  }),
)

async function onRun() {
  if (!canRun.value) return
  try {
    await store.run(sql.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.sql.runFailed')))
  }
}

async function onCommit() {
  try {
    const data = await store.commit()
    if (data?.committed) toast.success(t('admin.sql.committed', data.rowCount))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.sql.runFailed')))
  }
}

// Ctrl/Cmd+Enter runs, the shortcut every SQL console has taught people.
function onKeydown(event) {
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
    event.preventDefault()
    onRun()
  }
}

/** Cells arrive as JSON scalars; null and booleans read better as words. */
function display(value) {
  if (value === null) return 'NULL'
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  return String(value)
}
</script>

<template>
  <div class="sandbox">
    <p class="sandbox__lead">{{ t('admin.sql.lead') }}</p>

    <p v-if="!capabilities.sandbox" class="sandbox__unavailable">
      <span class="material-symbols-outlined">block</span>
      {{ t('admin.sql.unavailable') }}
    </p>

    <label class="sr-only" for="sql-sandbox-editor">{{ t('admin.sql.editorLabel') }}</label>
    <textarea
      id="sql-sandbox-editor"
      v-model="sql"
      class="sandbox__editor"
      rows="6"
      spellcheck="false"
      autocomplete="off"
      autocapitalize="off"
      :maxlength="limits.maxLength"
      :disabled="!capabilities.sandbox"
      :placeholder="t('admin.sql.placeholder')"
      @keydown="onKeydown"
    />

    <div class="sandbox__bar">
      <button class="btn-primary sandbox__run" type="button" :disabled="!canRun" @click="onRun">
        <BaseSpinner v-if="running" size="sm" />
        <span v-else class="material-symbols-outlined">play_arrow</span>
        {{ t('admin.sql.run') }}
      </button>
      <span class="sandbox__shortcut">{{ t('admin.sql.shortcut') }}</span>
    </div>

    <ul class="sandbox__rules">
      <li>{{ t('admin.sql.ruleStatements') }}</li>
      <li>{{ t('admin.sql.ruleWrites') }}</li>
      <li>{{ limitsHint }}</li>
    </ul>

    <!-- A refusal or the database's own error: kept on screen beside the
         statement it is about, since it is usually needed to fix it. -->
    <div v-if="failure" class="sandbox__failure" role="alert">
      <span class="material-symbols-outlined">error</span>
      <div>
        <pre class="sandbox__failure-text">{{ failure.message }}</pre>
        <span v-if="failure.sqlState" class="sandbox__sqlstate">SQLSTATE {{ failure.sqlState }}</span>
      </div>
    </div>

    <div v-if="preview" class="sandbox__preview" role="status">
      <p class="sandbox__preview-text">
        <strong>{{ t('admin.sql.previewRows', preview.rowCount) }}</strong>
        {{ t('admin.sql.previewNote') }}
      </p>
      <div class="sandbox__preview-actions">
        <button class="btn-secondary" type="button" :disabled="running" @click="store.cancelPreview()">
          {{ t('common.cancel') }}
        </button>
        <button class="btn-danger" type="button" :disabled="running" @click="onCommit">
          {{ t('admin.sql.commit') }}
        </button>
      </div>
    </div>

    <p v-if="result && result.kind === 'write'" class="sandbox__meta">
      {{ t('admin.sql.committed', result.rowCount) }} · {{ t('admin.sql.duration', { ms: n(result.durationMs) }) }}
    </p>

    <template v-if="result && result.kind === 'read'">
      <p class="sandbox__meta">
        {{ t('admin.sql.rows', result.rowCount) }} · {{ t('admin.sql.duration', { ms: n(result.durationMs) }) }}
        <span v-if="result.truncated" class="sandbox__truncated">
          {{ t('admin.sql.truncated', { rows: n(limits.maxRows) }) }}
        </span>
      </p>

      <p v-if="!result.rows.length" class="sandbox__empty">{{ t('admin.sql.noRows') }}</p>

      <div v-else class="sandbox__scroll">
        <table class="sandbox__table">
          <thead>
            <tr>
              <th v-for="(column, i) in result.columns" :key="i" scope="col">{{ column }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, r) in result.rows" :key="r">
              <td
                v-for="(value, c) in row"
                :key="c"
                :class="{ 'sandbox__null': value === null }"
                :title="display(value)"
              >{{ display(value) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.sandbox {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
.sandbox__lead {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  max-width: 70ch;
}
.sandbox__unavailable {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-tertiary);
}
.sandbox__unavailable .material-symbols-outlined { font-size: 18px; }

.sandbox__editor {
  width: 100%;
  min-height: 140px;
  resize: vertical;
  padding: var(--space-sm) var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
  color: var(--color-on-surface);
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: var(--text-label-md);
  line-height: 1.5;
  tab-size: 2;
}
.sandbox__editor:focus {
  outline: 2px solid var(--color-primary);
  outline-offset: -1px;
  border-color: var(--color-primary);
}
.sandbox__editor:disabled { opacity: 0.6; cursor: not-allowed; }

.sandbox__bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-sm);
}
.sandbox__run {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
}
.sandbox__run .material-symbols-outlined { font-size: 18px; }
.sandbox__shortcut {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

.sandbox__rules {
  margin: 0;
  padding-left: var(--space-md);
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}

.sandbox__failure {
  display: flex;
  align-items: flex-start;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  border: 1px solid var(--color-error);
  border-radius: var(--radius-default);
  background: var(--color-error-container);
  color: var(--color-on-surface);
}
.sandbox__failure .material-symbols-outlined { color: var(--color-error); font-size: 20px; }
.sandbox__failure-text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: var(--text-label-md);
}
.sandbox__sqlstate {
  display: block;
  margin-top: 2px;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

.sandbox__preview {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-left: 4px solid var(--color-error);
  border-radius: var(--radius-default);
  background: var(--color-surface);
}
.sandbox__preview-text { margin: 0; font-size: var(--text-body-md); }
.sandbox__preview-actions { display: flex; gap: var(--space-xs); }

.sandbox__meta {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}
.sandbox__truncated {
  margin-left: var(--space-xs);
  color: var(--color-tertiary);
  font-weight: 500;
}
.sandbox__empty {
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
}

.sandbox__scroll {
  overflow: auto;
  max-height: 480px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
}
.sandbox__table {
  border-collapse: collapse;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: var(--text-label-sm);
}
.sandbox__table th {
  position: sticky;
  top: 0;
  text-align: left;
  padding: var(--space-xs) var(--space-sm);
  background: var(--color-surface-container-low);
  color: var(--color-on-surface-variant);
  font-weight: 600;
  border-bottom: 1px solid var(--color-outline-variant);
  white-space: nowrap;
}
.sandbox__table td {
  max-width: 320px;
  padding: var(--space-xs) var(--space-sm);
  border-bottom: 1px solid var(--color-surface-container-high);
  color: var(--color-on-surface);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sandbox__table tbody tr:last-child td { border-bottom: none; }
.sandbox__null { color: var(--color-outline); font-style: italic; }

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
