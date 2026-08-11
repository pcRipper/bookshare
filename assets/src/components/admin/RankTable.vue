<script setup>
/**
 * A ranked list: top routes, top languages, most-borrowed books, top lenders.
 *
 * Those four are the same shape — a label, a count, and an order that carries
 * the meaning — so they share one component rather than four hand-rolled tables
 * that would drift apart. They are tables and not charts deliberately: the
 * labels are multi-word and identity-bearing (page names, book titles, people),
 * and the exact number matters, which is precisely where a bar chart reads
 * worse than a row.
 *
 * `barKey` draws a proportional bar behind a numeric column — the bar chart the
 * ranking actually wanted, at no bundle cost and readable by a screen reader,
 * since the number is right there.
 */
import { computed } from 'vue'

const props = defineProps({
  /** Row objects; keys are addressed by `columns[].key`. */
  rows: { type: Array, default: () => [] },
  /** [{ key, label, align?, numeric? }] — labels already translated. */
  columns: { type: Array, required: true },
  /** Column key to scale the proportional bar from. */
  barKey: { type: String, default: null },
  /** Minimum table width before the wrapper scrolls sideways. */
  minWidth: { type: String, default: '420px' },
  /** Show a 1..n rank column. */
  ranked: { type: Boolean, default: false },
})

const max = computed(() => {
  if (!props.barKey) return 0
  return props.rows.reduce((top, row) => Math.max(top, Number(row[props.barKey]) || 0), 0)
})

function share(row) {
  if (!max.value) return 0
  return Math.round(((Number(row[props.barKey]) || 0) / max.value) * 100)
}

/** Counts are numbers, so they follow the reader's locale like every other. */
const format = value =>
  typeof value === 'number' ? new Intl.NumberFormat(undefined).format(value) : value
</script>

<template>
  <!-- Narrow screens pan sideways rather than losing columns, matching
       BookTable's .book-table__scroll. -->
  <div class="rank-table__scroll">
    <table class="rank-table" :style="{ minWidth }">
      <thead>
        <tr>
          <th v-if="ranked" scope="col" class="rank-table__rank">#</th>
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            :class="{ 'rank-table__num': column.numeric }"
          >
            {{ column.label }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in rows" :key="row.id ?? index">
          <td v-if="ranked" class="rank-table__rank">{{ index + 1 }}</td>
          <td
            v-for="column in columns"
            :key="column.key"
            :class="{ 'rank-table__num': column.numeric }"
          >
            <!-- Named per column so a caller can slot in an avatar or a link
                 without this component knowing about either. -->
            <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
              <template v-if="barKey === column.key">
                <span class="rank-table__bar-cell">
                  <span class="rank-table__bar" :style="{ width: `${share(row)}%` }" aria-hidden="true" />
                  <span class="rank-table__bar-value">{{ format(row[column.key]) }}</span>
                </span>
              </template>
              <template v-else>{{ format(row[column.key]) }}</template>
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.rank-table__scroll {
  width: 100%;
  overflow-x: auto;
}

.rank-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-body-md);
}

.rank-table th {
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-outline-variant);
  font-size: var(--text-label-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
  text-align: left;
  white-space: nowrap;
}

.rank-table td {
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-outline-variant);
  color: var(--color-on-surface);
  vertical-align: middle;
}

.rank-table tbody tr:last-child td { border-bottom: none; }

.rank-table__rank {
  width: 2.5rem;
  color: var(--color-on-surface-variant);
  font-variant-numeric: tabular-nums;
}

.rank-table__num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

/* The proportional bar sits behind its own number so the exact value stays
   readable — the point of choosing a table over a chart here. */
.rank-table__bar-cell {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  min-width: 100px;
  padding: 2px var(--space-sm);
}
.rank-table__bar {
  position: absolute;
  inset: 0 auto 0 0;
  border-radius: var(--radius-sm);
  background: var(--color-primary-fixed);
}
.rank-table__bar-value {
  position: relative;
  font-variant-numeric: tabular-nums;
}
</style>
