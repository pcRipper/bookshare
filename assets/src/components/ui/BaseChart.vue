<script setup>
/**
 * The app's only Chart.js surface. No view imports chart.js directly — they
 * describe a chart in this component's vocabulary and it deals with the library.
 *
 * KEEP THIS IMPORTED ONLY FROM THE ADMIN VIEW. AdminStatsView is a dynamic
 * import and is the only module that reaches this file, so Rollup puts chart.js
 * in the admin route's own chunk and no other route pays a byte for it. Import
 * this anywhere reachable from the entry bundle and that stops being true.
 *
 * Controllers are registered explicitly rather than via `chart.js/auto`, which
 * pulls in every controller, scale and plugin the library ships. This costs one
 * import list and roughly halves what lands in the chunk — and it fails loudly
 * if a view ever asks for a type nobody registered.
 *
 * There is deliberately no TimeScale: it needs a date adapter plus a date
 * library, two more dependencies for a repo that has six. The server sends a
 * dense, gap-filled Y-m-d series, so a category axis plus our own Intl
 * formatting does the same job.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  DoughnutController,
  Filler,
  Legend,
  LineController,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js'
import { CATEGORY_PALETTE } from '@/utils/categoryColors'
import { currentLocale } from '@/i18n'

Chart.register(
  LineController, BarController, DoughnutController,
  LineElement, PointElement, BarElement, ArcElement,
  CategoryScale, LinearScale,
  Tooltip, Legend, Filler,
)

const props = defineProps({
  type: {
    type: String,
    default: 'line',
    validator: value => ['line', 'bar', 'doughnut'].includes(value),
  },
  /** Y-m-d strings for a time series, or plain labels otherwise. */
  labels: { type: Array, default: () => [] },
  /** [{ label, data, color?, colors?, fill? }] — labels already translated. */
  datasets: { type: Array, default: () => [] },
  stacked: { type: Boolean, default: false },
  /** 'y' lays bars out horizontally, for ranked word labels. */
  indexAxis: { type: String, default: 'x' },
  /** Whether `labels` are dates needing locale-aware formatting. */
  dateLabels: { type: Boolean, default: true },
  showLegend: { type: Boolean, default: true },
  /** Must describe the finding — a canvas is opaque to screen readers. */
  ariaLabel: { type: String, required: true },
})

const canvas = ref(null)
const { locale } = useI18n()

/**
 * NOT a ref. A Chart.js instance inside Vue's reactive proxy is a known
 * footgun: the library does identity comparisons against its own internals,
 * which the proxy breaks, and every update() pays for deep tracking of an
 * object tree nothing renders. Please don't "tidy" this into a ref.
 */
let chart = null

/** Design tokens are the only source of colour — no hex literals in here. */
function token(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

/**
 * Categorical series reuse the curated chip palette, so a chart is drawn from
 * the same ten on-brand, mutually distinguishable colours the rest of the app
 * uses, and introduces no new ones.
 */
function seriesColor(index) {
  return CATEGORY_PALETTE[index % CATEGORY_PALETTE.length].text
}

const reducedMotion = () =>
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

function mapDatasets() {
  const single = props.datasets.length === 1
  return props.datasets.map((set, i) => {
    // An explicit per-point palette (the top-categories bars, where each bar
    // wears its own category's colour) wins over the positional one.
    const colors = set.colors ?? null
    const color = set.color ?? (single && props.type !== 'doughnut' ? token('--color-primary') : seriesColor(i))

    const base = {
      label: set.label,
      data: set.data,
      borderWidth: props.type === 'line' ? 2 : 1,
    }

    if (props.type === 'doughnut') {
      return {
        ...base,
        backgroundColor: colors ?? props.labels.map((_, n) => seriesColor(n)),
        borderColor: token('--color-surface-container-lowest'),
        borderWidth: 2,
      }
    }

    if (props.type === 'bar') {
      return { ...base, backgroundColor: colors ?? color, borderColor: colors ?? color }
    }

    return {
      ...base,
      borderColor: color,
      // A real token rather than an alpha of the line colour: custom properties
      // are opaque hex, and hand-rolling rgba() here would be the one-off the
      // design system exists to avoid.
      backgroundColor: single ? token('--color-primary-fixed') : color,
      fill: set.fill ?? single,
      tension: 0.25,
      // 90 dots is mush; 7 with none looks like a rendering fault.
      pointRadius: props.labels.length > 31 ? 0 : 3,
      pointHoverRadius: 5,
    }
  })
}

function buildOptions() {
  // Intl instances are built once per chart, not per tick — constructing them
  // inside a callback is measurably slow across 90 points.
  const nf = new Intl.NumberFormat(currentLocale())
  const dfShort = new Intl.DateTimeFormat(currentLocale(), { day: 'numeric', month: 'short' })
  const dfLong = new Intl.DateTimeFormat(currentLocale(), {
    weekday: 'short', day: 'numeric', month: 'long',
  })

  const formatLabel = value =>
    props.dateLabels && /^\d{4}-\d{2}-\d{2}$/.test(String(value))
      ? dfShort.format(new Date(`${value}T00:00:00`))
      : String(value)

  const reduce = reducedMotion()

  const options = {
    responsive: true,
    // The wrapper element owns the height (via --chart-h), so the responsive
    // step stays a CSS media query instead of a JS breakpoint.
    maintainAspectRatio: false,
    animation: reduce ? false : { duration: 400 },
    // animation:false alone still leaves hover transitions moving.
    transitions: reduce ? { active: { animation: { duration: 0 } } } : undefined,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        display: props.showLegend && (props.datasets.length > 1 || props.type === 'doughnut'),
        position: 'bottom',
        labels: { usePointStyle: true, boxWidth: 8, padding: 16 },
      },
      tooltip: {
        callbacks: {
          title: items => {
            const raw = items[0]?.label ?? ''
            return props.dateLabels && /^\d{4}-\d{2}-\d{2}$/.test(raw)
              ? dfLong.format(new Date(`${raw}T00:00:00`))
              : raw
          },
          label: ctx => {
            const value = ctx.parsed?.y ?? ctx.parsed?.x ?? ctx.parsed
            const name = ctx.dataset.label ?? ctx.label
            return `${name}: ${nf.format(value ?? 0)}`
          },
        },
      },
    },
  }

  if (props.type !== 'doughnut') {
    const valueAxis = {
      beginAtZero: true,
      stacked: props.stacked,
      grid: { color: token('--color-outline-variant') },
      // Every value on this dashboard is a count; "2.5 members" is nonsense.
      ticks: { precision: 0, callback: value => nf.format(value) },
    }
    const categoryAxis = {
      stacked: props.stacked,
      grid: { display: false },
      ticks: {
        autoSkip: true,
        maxTicksLimit: props.labels.length > 31 ? 8 : 12,
        callback(index) {
          return formatLabel(this.getLabelForValue(index))
        },
      },
    }

    options.indexAxis = props.indexAxis
    options.scales = props.indexAxis === 'y'
      ? { x: valueAxis, y: categoryAxis }
      : { x: categoryAxis, y: valueAxis }
  }

  return options
}

function build() {
  if (!canvas.value) return

  Chart.defaults.font.family = token('--font-body')
  Chart.defaults.color = token('--color-secondary')
  Chart.defaults.borderColor = token('--color-outline-variant')

  chart = new Chart(canvas.value, {
    type: props.type,
    data: { labels: props.labels, datasets: mapDatasets() },
    options: buildOptions(),
  })
}

function rebuild() {
  chart?.destroy()
  chart = null
  build()
}

onMounted(build)

onBeforeUnmount(() => {
  // Chart.js keeps a global registry of live instances plus its own
  // ResizeObserver; without this every visit to the dashboard leaks one.
  chart?.destroy()
  chart = null
})

// Data changes mutate and update rather than re-create: re-creating clears the
// canvas and replays the entrance animation on every window switch.
watch(
  () => [props.labels, props.datasets],
  () => {
    if (!chart) return
    chart.data.labels = props.labels
    chart.data.datasets = mapDatasets()
    chart.update()
  },
  { deep: true },
)

// A type change genuinely needs a new chart. So does a locale change: the Intl
// formatters live inside the frozen options object, so without this the ticks
// and tooltips would keep the old language while every heading around them
// switched.
watch(() => props.type, rebuild)
watch(locale, rebuild)

const describedBy = computed(() => props.ariaLabel)
</script>

<template>
  <div class="chart">
    <canvas ref="canvas" role="img" :aria-label="describedBy" />
  </div>
</template>

<style scoped>
.chart {
  position: relative;
  /* Height comes from the parent so the responsive step is plain CSS. The
     fallback matters: Chart.js measures its container at construction, and a
     zero-height box yields a chart that never recovers. */
  height: var(--chart-h, 260px);
  width: 100%;
}
</style>
