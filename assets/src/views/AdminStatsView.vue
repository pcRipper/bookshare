<script setup>
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminStore } from '@/stores/admin'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import { relativeTime } from '@/utils/time'
import { languageLabel } from '@/utils/languages'
import { resolveCategoryColors } from '@/utils/categoryColors'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseChart from '@/components/ui/BaseChart.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import StatBar from '@/components/ui/StatBar.vue'
import AdminStatsSkeleton from '@/components/admin/AdminStatsSkeleton.vue'
import RankTable from '@/components/admin/RankTable.vue'

const store = useAdminStore()
const toast = useToastStore()
const { t } = useI18n()
const { stats, loading, error, windowDays } = storeToRefs(store)

onMounted(load)

async function load(days = windowDays.value) {
  try {
    await store.fetchStats(days)
  } catch (e) {
    // The in-page block below carries the retry; the toast is the standard
    // convention for the failure itself.
    toast.error(apiErrorMessage(e, t('admin.loadFailed')))
  }
}

/* ── window picker ─────────────────────────────────────────────────────── */

const windowOptions = computed(() => [
  { value: 7, label: t('admin.window.d7') },
  { value: 30, label: t('admin.window.d30') },
  { value: 90, label: t('admin.window.d90') },
])

const selectedWindow = computed({
  get: () => windowDays.value,
  set: days => { store.setWindow(days)?.catch(() => {}) },
})

/* ── formatting ────────────────────────────────────────────────────────── */

// StatBar renders its value raw, so the grouping separators are applied here.
const number = value => new Intl.NumberFormat(undefined).format(value ?? 0)

const days = computed(() => stats.value?.days ?? [])

/** A section with nothing but zeroes gets an empty state, not a flat chart on a
 *  0-to-1 axis, which reads as a rendering fault rather than as "no data yet". */
const allZero = series => !series?.length || series.every(v => v === 0)

/* ── growth ────────────────────────────────────────────────────────────── */

const growthStats = computed(() => {
  const totals = stats.value?.growth?.totals
  if (!totals) return []
  return [
    { label: t('admin.growth.users'), value: number(totals.users) },
    { label: t('admin.growth.books'), value: number(totals.books) },
    { label: t('admin.growth.collections'), value: number(totals.collections) },
    { label: t('admin.growth.categories'), value: number(totals.categories) },
  ]
})

const growthDatasets = computed(() => {
  const series = stats.value?.growth?.series
  if (!series) return []
  return [
    { label: t('admin.growth.newUsers'), data: series.users },
    { label: t('admin.growth.newBooks'), data: series.books },
    { label: t('admin.growth.newCollections'), data: series.collections },
  ]
})

const growthEmpty = computed(() =>
  growthDatasets.value.every(set => allZero(set.data)),
)

/* ── engagement ────────────────────────────────────────────────────────── */

const engagementStats = computed(() => {
  const totals = stats.value?.engagement?.totals
  if (!totals) return []
  return [
    { label: t('admin.engagement.activeToday'), value: number(totals.activeToday) },
    { label: t('admin.engagement.requested'), value: number(totals.requested) },
    { label: t('admin.engagement.approved'), value: number(totals.approved) },
    { label: t('admin.engagement.returned'), value: number(totals.returned) },
  ]
})

const dauDatasets = computed(() => {
  const series = stats.value?.engagement?.series
  return series ? [{ label: t('admin.engagement.dau'), data: series.activeUsers }] : []
})

const loanDatasets = computed(() => {
  const series = stats.value?.engagement?.series
  if (!series) return []
  return [
    { label: t('admin.engagement.requested'), data: series.requested },
    { label: t('admin.engagement.approved'), data: series.approved },
    { label: t('admin.engagement.returned'), data: series.returned },
  ]
})

const recentActivity = computed(() => stats.value?.engagement?.recentActivity ?? [])

/** Maps the backend's action types onto the sentence keys. */
const activityKey = type => ({
  borrowed: 'admin.activity.borrowed',
  returned: 'admin.activity.returned',
  added_book: 'admin.activity.added',
  followed: 'admin.activity.followed',
  commented: 'admin.activity.commented',
}[type] ?? 'admin.activity.borrowed')

/* ── traffic ───────────────────────────────────────────────────────────── */

const trafficStats = computed(() => {
  const totals = stats.value?.traffic?.totals
  if (!totals) return []
  return [
    { label: t('admin.traffic.views'), value: number(totals.views) },
    { label: t('admin.traffic.uniques'), value: number(totals.visitors) },
  ]
})

const trafficDatasets = computed(() => {
  const series = stats.value?.traffic?.series
  if (!series) return []
  return [
    { label: t('admin.traffic.views'), data: series.views },
    { label: t('admin.traffic.uniques'), data: series.visitors },
  ]
})

const topRoutes = computed(() => stats.value?.traffic?.topRoutes ?? [])

const trafficEmpty = computed(() => !topRoutes.value.length)

/** Route names are raw; unmapped ones degrade to the name, not to blank. */
const routeLabel = name => {
  const key = `admin.routes.${name}`
  const label = t(key)
  return label === key ? name : label
}

const routeColumns = computed(() => [
  { key: 'label', label: t('admin.traffic.page') },
  { key: 'views', label: t('admin.traffic.views'), numeric: true },
])

const routeRows = computed(() =>
  topRoutes.value.map(row => ({ ...row, label: routeLabel(row.route) })),
)

/* ── library health ────────────────────────────────────────────────────── */

const statusLabels = computed(() => {
  const byStatus = stats.value?.library?.booksByStatus ?? {}
  return Object.keys(byStatus).map(key => t(`book.statusOption.${statusKey(key)}`))
})

// The API sends the raw enum value; the existing catalog keys are camelCase.
const statusKey = value => ({
  own: 'own',
  lent: 'lent',
  unavailable: 'unavailable',
  currently_reading: 'currentlyReading',
}[value] ?? value)

const statusDatasets = computed(() => {
  const byStatus = stats.value?.library?.booksByStatus
  if (!byStatus) return []
  return [{ label: t('admin.library.byStatus'), data: Object.values(byStatus) }]
})

const statusEmpty = computed(() => allZero(statusDatasets.value[0]?.data))

const topCategories = computed(() => stats.value?.library?.topCategories ?? [])

const categoryDatasets = computed(() => {
  if (!topCategories.value.length) return []
  return [{
    label: t('admin.library.books'),
    data: topCategories.value.map(c => c.books),
    // Each bar wears its own category's ink, so the chart matches the chips
    // those same categories wear on every book card. That recognition is the
    // reason this one is a chart where the other rankings are tables.
    colors: topCategories.value.map(c => resolveCategoryColors(c.colorHex).text),
  }]
})

const categoryLabels = computed(() => topCategories.value.map(c => c.name))

const languageRows = computed(() =>
  (stats.value?.library?.topLanguages ?? []).map(row => ({
    ...row,
    // Re-derived per locale from the code; the server's English name is only a
    // fallback, matching how book cards render a language everywhere else.
    label: languageLabel(row.code, row.name),
  })),
)

const languageColumns = computed(() => [
  { key: 'label', label: t('admin.library.language') },
  { key: 'books', label: t('admin.library.books'), numeric: true },
])

const bookRows = computed(() =>
  (stats.value?.library?.mostBorrowed ?? []).map(row => ({
    id: row.book.id,
    title: row.book.title,
    author: row.book.author,
    loans: row.loans,
  })),
)

const bookColumns = computed(() => [
  { key: 'title', label: t('admin.library.bookTitle') },
  { key: 'author', label: t('admin.library.author') },
  { key: 'loans', label: t('admin.library.loans'), numeric: true },
])

const lenderRows = computed(() =>
  (stats.value?.library?.topLenders ?? []).map(row => ({
    id: row.user.id,
    fullName: row.user.fullName,
    avatarUrl: row.user.avatarUrl,
    loans: row.loans,
  })),
)

const lenderColumns = computed(() => [
  { key: 'fullName', label: t('admin.library.member') },
  { key: 'loans', label: t('admin.library.loans'), numeric: true },
])

/* ── wish lists ────────────────────────────────────────────────────────── */
// Titles matched on title+author across every wish list, so a row is "this many
// members want this book" — the one thing the wish list tells an operator that
// nothing else on the page does.
const wishlistTotal = computed(() => stats.value?.library?.wishlist?.total ?? 0)

const wantedRows = computed(() =>
  (stats.value?.library?.wishlist?.mostWanted ?? []).map((row, i) => ({ id: i, ...row })),
)

const wantedColumns = computed(() => [
  { key: 'title', label: t('admin.library.bookTitle') },
  { key: 'author', label: t('admin.library.author') },
  { key: 'wanted', label: t('admin.library.wanted'), numeric: true },
])

const libraryEmpty = computed(() =>
  statusEmpty.value && !topCategories.value.length && !bookRows.value.length && !wantedRows.value.length,
)
</script>

<template>
  <AppLayout>
    <div class="admin">
      <header class="admin__header">
        <div class="admin__titles">
          <h1 class="admin__title">{{ t('admin.title') }}</h1>
          <p class="admin__subtitle">{{ t('admin.subtitle') }}</p>
        </div>

        <div class="admin__controls">
          <BaseSelect
            v-model="selectedWindow"
            :options="windowOptions"
            :disabled="loading"
            class="admin__window"
          />
          <button class="admin__refresh" :disabled="loading" @click="load()">
            <span class="material-symbols-outlined">refresh</span>
            {{ t('admin.refresh') }}
          </button>
        </div>
      </header>

      <p v-if="stats?.window && !loading" class="admin__updated">
        {{ t('admin.range', { from: stats.window.from, to: stats.window.to }) }}
      </p>

      <AdminStatsSkeleton v-if="loading && !stats" />

      <div v-else-if="error === 'forbidden'" class="admin-state">
        <span class="material-symbols-outlined admin-state__icon">lock</span>
        <p>{{ t('admin.forbidden') }}</p>
      </div>

      <div v-else-if="error && !stats" class="admin-state">
        <span class="material-symbols-outlined admin-state__icon">error</span>
        <p>{{ t('admin.loadFailed') }}</p>
        <button class="admin-state__link" @click="load()">{{ t('common.retry') }}</button>
      </div>

      <template v-else-if="stats">
        <!-- ── Growth ─────────────────────────────────────────────────── -->
        <section class="admin__section">
          <h2 class="admin__section-title">{{ t('admin.growth.title') }}</h2>
          <StatBar :stats="growthStats" variant="grid" />

          <div v-if="growthEmpty" class="admin-state admin-state--inline">
            <span class="material-symbols-outlined admin-state__icon">trending_up</span>
            <p>{{ t('admin.growth.empty') }}</p>
          </div>
          <div v-else class="admin__panel">
            <h3 class="admin__panel-title">{{ t('admin.growth.chartTitle') }}</h3>
            <BaseChart
              type="line"
              :labels="days"
              :datasets="growthDatasets"
              :aria-label="t('admin.growth.chartTitle')"
            />
          </div>
        </section>

        <!-- ── Engagement ─────────────────────────────────────────────── -->
        <section class="admin__section">
          <h2 class="admin__section-title">{{ t('admin.engagement.title') }}</h2>
          <StatBar :stats="engagementStats" variant="grid" />

          <div class="admin__grid">
            <div class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.engagement.dauChart') }}</h3>
              <!-- A line: presence is a continuous quantity sampled daily. -->
              <BaseChart
                type="line"
                :labels="days"
                :datasets="dauDatasets"
                :aria-label="t('admin.engagement.dauChart')"
              />
            </div>

            <div class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.engagement.loansChart') }}</h3>
              <!-- Bars, stacked: these are discrete, frequently-zero events, and
                   a line would imply continuity between days that doesn't exist.
                   Stacking makes the height read as total lending activity while
                   the segments keep the split. -->
              <BaseChart
                type="bar"
                stacked
                :labels="days"
                :datasets="loanDatasets"
                :aria-label="t('admin.engagement.loansChart')"
              />
            </div>
          </div>

          <div class="admin__panel">
            <h3 class="admin__panel-title">{{ t('admin.engagement.recent') }}</h3>
            <div v-if="!recentActivity.length" class="admin-state admin-state--inline">
              <span class="material-symbols-outlined admin-state__icon">history</span>
              <p>{{ t('admin.engagement.recentEmpty') }}</p>
            </div>
            <ul v-else class="activity">
              <li v-for="item in recentActivity" :key="item.id" class="activity__row">
                <BaseAvatar :src="item.actor.avatarUrl" :name="item.actor.fullName" size="sm" />
                <p class="activity__text">
                  <!-- Named slots so word order around the inserted values stays
                       the translator's choice. -->
                  <i18n-t :keypath="activityKey(item.actionType)" scope="global">
                    <template #actor>
                      <RouterLink :to="`/profile/${item.actor.id}`" class="activity__link">
                        {{ item.actor.fullName }}
                      </RouterLink>
                    </template>
                    <template #book>
                      <em>{{ item.targetBook?.title ?? '—' }}</em>
                    </template>
                    <template #target>
                      <strong>{{ item.targetUser?.fullName ?? '—' }}</strong>
                    </template>
                  </i18n-t>
                </p>
                <time class="activity__time" :datetime="item.createdAt">
                  {{ relativeTime(item.createdAt) }}
                </time>
              </li>
            </ul>
          </div>
        </section>

        <!-- ── Traffic ────────────────────────────────────────────────── -->
        <section class="admin__section">
          <h2 class="admin__section-title">{{ t('admin.traffic.title') }}</h2>
          <StatBar :stats="trafficStats" variant="grid" />

          <div v-if="trafficEmpty" class="admin-state admin-state--inline">
            <span class="material-symbols-outlined admin-state__icon">bar_chart</span>
            <p>{{ t('admin.traffic.empty') }}</p>
          </div>
          <div v-else class="admin__grid">
            <div class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.traffic.viewsChart') }}</h3>
              <!-- Both series on one plot: they share a unit and uniques never
                   exceed views, so the lines nest and the gap between them is
                   itself the metric (repeat visits per visitor). -->
              <BaseChart
                type="line"
                :labels="days"
                :datasets="trafficDatasets"
                :aria-label="t('admin.traffic.viewsChart')"
              />
            </div>

            <div class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.traffic.topRoutes') }}</h3>
              <RankTable :rows="routeRows" :columns="routeColumns" bar-key="views" ranked />
            </div>
          </div>
        </section>

        <!-- ── Library health ─────────────────────────────────────────── -->
        <section class="admin__section">
          <h2 class="admin__section-title">{{ t('admin.library.title') }}</h2>

          <div v-if="libraryEmpty" class="admin-state admin-state--inline">
            <span class="material-symbols-outlined admin-state__icon">menu_book</span>
            <p>{{ t('admin.library.empty') }}</p>
          </div>
          <template v-else>
            <div class="admin__grid">
              <div v-if="!statusEmpty" class="admin__panel">
                <h3 class="admin__panel-title">{{ t('admin.library.byStatus') }}</h3>
                <!-- The one legitimate whole-made-of-parts case on this page:
                     four mutually exclusive statuses that sum to every book. -->
                <BaseChart
                  type="doughnut"
                  :labels="statusLabels"
                  :datasets="statusDatasets"
                  :date-labels="false"
                  :aria-label="t('admin.library.byStatus')"
                />
              </div>

              <div v-if="topCategories.length" class="admin__panel">
                <h3 class="admin__panel-title">{{ t('admin.library.topCategories') }}</h3>
                <BaseChart
                  type="bar"
                  index-axis="y"
                  :labels="categoryLabels"
                  :datasets="categoryDatasets"
                  :date-labels="false"
                  :show-legend="false"
                  :aria-label="t('admin.library.topCategories')"
                />
              </div>
            </div>

            <div class="admin__grid">
              <div class="admin__panel">
                <h3 class="admin__panel-title">{{ t('admin.library.topLanguages') }}</h3>
                <RankTable :rows="languageRows" :columns="languageColumns" bar-key="books" />
              </div>

              <div class="admin__panel">
                <h3 class="admin__panel-title">{{ t('admin.library.topBooks') }}</h3>
                <RankTable :rows="bookRows" :columns="bookColumns" bar-key="loans" ranked min-width="480px" />
              </div>
            </div>

            <!-- What the shelves are missing: the same question the status and
                 category breakdowns ask, from the other side. Hidden entirely
                 when nobody wants anything yet — a table of nothing under a
                 heading reads as a broken query. -->
            <div v-if="wantedRows.length" class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.library.mostWanted') }}</h3>
              <p class="admin__panel-note">{{ t('admin.library.wishlistTotal', { count: wishlistTotal }) }}</p>
              <RankTable :rows="wantedRows" :columns="wantedColumns" bar-key="wanted" ranked min-width="480px" />
            </div>

            <div class="admin__panel">
              <h3 class="admin__panel-title">{{ t('admin.library.topLenders') }}</h3>
              <RankTable :rows="lenderRows" :columns="lenderColumns" bar-key="loans" ranked>
                <template #cell:fullName="{ row }">
                  <RouterLink :to="`/profile/${row.id}`" class="lender">
                    <BaseAvatar :src="row.avatarUrl" :name="row.fullName" size="sm" />
                    <span>{{ row.fullName }}</span>
                  </RouterLink>
                </template>
              </RankTable>
            </div>
          </template>
        </section>
      </template>
    </div>
  </AppLayout>
</template>

<style scoped>
.admin {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
}
@media (max-width: 767px) {
  .admin { padding: var(--space-lg) var(--space-gutter) var(--space-xl); }
}

.admin__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-md);
}
.admin__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  color: var(--color-on-surface);
  margin: 0;
}
@media (min-width: 768px) {
  .admin__title { font-size: var(--text-headline-lg); }
}
.admin__subtitle {
  margin: var(--space-xs) 0 0;
  color: var(--color-on-surface-variant);
}

.admin__controls {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.admin__window { min-width: 160px; }
@media (max-width: 767px) {
  .admin__controls { width: 100%; }
  .admin__window { flex: 1; }
}

.admin__refresh {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 10px var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  color: var(--color-on-surface);
  font: inherit;
  cursor: pointer;
}
.admin__refresh:hover:not(:disabled) { background: var(--color-surface-container); }
.admin__refresh:disabled { opacity: 0.5; cursor: default; }
.admin__refresh .material-symbols-outlined { font-size: 18px; }

.admin__updated {
  margin: calc(var(--space-xl) * -1 + var(--space-sm)) 0 0;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

.admin__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.admin__section-title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  color: var(--color-on-surface);
  margin: 0;
}

/* Two panels side by side on desktop, stacked on a phone — no media query. */
.admin__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: var(--space-md);
}

.admin__panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  padding: var(--space-md);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  background: var(--color-surface-container-lowest);
  /* Consumed by BaseChart's wrapper. */
  --chart-h: 220px;
  min-width: 0;
}
@media (min-width: 768px) {
  .admin__panel { --chart-h: 300px; }
}
.admin__panel-title {
  margin: 0;
  font-size: var(--text-label-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}
/* Context line under a panel title — the whole-population figure the ranking
   below is a slice of. Sits between the two, so neither has to carry it. */
/* Pulls back most of the panel's 12px gap so the note reads as a subtitle of
   the title rather than a third sibling — but not all of it: cancelling the
   gap outright left the two lines touching. */
.admin__panel-note {
  margin: calc(var(--space-sm) * -1 + 4px) 0 0;
  font-size: var(--text-body-md);
  color: var(--color-secondary);
}

/* In-page states, matching SubscriptionsView's .feed-state. StatusScreen is
   deliberately not used — that is for full-page 404/error views. */
.admin-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  padding: var(--space-xl) var(--space-md);
  color: var(--color-on-surface-variant);
  text-align: center;
}
.admin-state--inline {
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  background: var(--color-surface-container-lowest);
}
.admin-state__icon { font-size: 48px; opacity: 0.5; }
.admin-state__link {
  border: none;
  background: none;
  padding: 0;
  font: inherit;
  color: var(--color-primary);
  text-decoration: underline;
  cursor: pointer;
}

.activity { list-style: none; margin: 0; padding: 0; }
.activity__row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-sm) 0;
  border-bottom: 1px solid var(--color-outline-variant);
}
.activity__row:last-child { border-bottom: none; }
.activity__text {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: var(--text-body-md);
  color: var(--color-on-surface);
}
.activity__link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.activity__link:hover { text-decoration: underline; }
.activity__time {
  flex-shrink: 0;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
  white-space: nowrap;
}

.lender {
  display: inline-flex;
  align-items: center;
  gap: var(--space-sm);
  color: inherit;
  text-decoration: none;
}
.lender:hover { text-decoration: underline; }
</style>
