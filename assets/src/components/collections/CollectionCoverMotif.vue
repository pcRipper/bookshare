<script setup>
import { computed } from 'vue'
import { useCoverFallback } from '@/composables/useCoverFallback'

const { hasCover, onCoverError } = useCoverFallback()

/**
 * The fallback a collection draws when its owner set no explicit cover: a hint of
 * what's inside, built from the member books' own covers. Shared so the card and
 * the detail sheet can't drift — the detail sheet used to fall straight through
 * to the static icon while the card next to it fanned real covers.
 *
 * Two variants, because the two surfaces are different shapes:
 *  - 'card'   → the fanned spines, on a ~155px portrait tile. Three is all that
 *    fits; more only smears the edges together.
 *  - 'detail' → a gapless mosaic filling the modal's cover area, which is a
 *    190–280px column from 640px up and a full-width 160px banner below it.
 *
 * The mosaic count is snapped to a bucket that tiles its grid **exactly**
 * (1/2/3/4/6/9): a partial bottom row reads as a rendering fault, not a design.
 * The phone banner caps at 6 — nine cells in 160px of height are thumbnails of
 * nothing — so a 9-bucket hides its last row there rather than re-bucketing,
 * which would make the two breakpoints show different books.
 */
const props = defineProps({
  books: { type: Array, default: () => [] },
  variant: { type: String, default: 'card' }, // 'card' | 'detail'
})

const covers = computed(() =>
  (props.books ?? []).map(b => b.coverPath).filter(url => hasCover(url)),
)

// Largest exactly-tileable count we can fill.
const BUCKETS = [9, 6, 4, 3, 2, 1]
const shown = computed(() => {
  const n = covers.value.length
  return BUCKETS.find(b => b <= n) ?? 0
})

const mosaic = computed(() => covers.value.slice(0, shown.value))
const fan = computed(() => covers.value.slice(0, 3))

// Columns per breakpoint, keyed by bucket. Portrait column vs. wide banner.
const COLS = {
  1: [1, 1],
  2: [2, 1],
  3: [3, 1],
  4: [4, 2],
  6: [6, 2],
  9: [6, 3], // mobile shows the first 6 of the 9; desktop shows all nine
}
const gridStyle = computed(() => {
  const [mobile, desktop] = COLS[shown.value] ?? [1, 1]
  return { '--cols-m': mobile, '--cols-d': desktop }
})
</script>

<template>
  <div class="motif" :class="`motif--${variant}`" aria-hidden="true">
    <!-- Detail: a gapless mosaic of member covers. -->
    <div
      v-if="variant === 'detail' && mosaic.length"
      class="motif__mosaic"
      :class="{ 'motif__mosaic--capped': shown === 9 }"
      :style="gridStyle"
    >
      <img
        v-for="url in mosaic"
        :key="url"
        :src="url"
        class="motif__cell"
        loading="lazy"
        alt=""
        @error="onCoverError(url)"
      />
    </div>

    <!-- Card: the fanned spines. -->
    <div v-else-if="variant === 'card' && fan.length" class="motif__stack">
      <img
        v-for="(url, i) in fan"
        :key="url"
        :src="url"
        class="motif__stack-img"
        :style="{ '--i': i }"
        loading="lazy"
        alt=""
        @error="onCoverError(url)"
      />
    </div>

    <!-- Nothing to hint with: the collection glyph. -->
    <span v-else class="material-symbols-outlined motif__icon">library_books</span>
  </div>
</template>

<style scoped>
.motif {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-primary-container) 0%, var(--color-surface-variant) 100%);
}
.motif__icon { color: var(--color-primary); opacity: 0.6; }
.motif--card .motif__icon { font-size: 48px; }
.motif--detail .motif__icon { font-size: 56px; }

/* Mosaic: edge to edge, hairline gaps letting the ground show through so
   adjacent covers of similar colour stay distinguishable. */
.motif__mosaic {
  width: 100%;
  height: 100%;
  display: grid;
  grid-template-columns: repeat(var(--cols-m), 1fr);
  grid-auto-rows: 1fr;
  gap: 1px;
}
.motif__cell { width: 100%; height: 100%; object-fit: cover; display: block; min-width: 0; }

@media (min-width: 640px) {
  .motif__mosaic { grid-template-columns: repeat(var(--cols-d), 1fr); }
}
/* Phone banner: 160px of height can't carry a third row of nine. */
@media (max-width: 639px) {
  .motif__mosaic--capped .motif__cell:nth-child(n + 7) { display: none; }
}

/* Fanned member covers (card only). */
.motif__stack {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  height: 100%;
  padding: var(--space-sm);
}
.motif__stack-img {
  width: 64px;
  height: 92px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 2px solid var(--color-surface-container-lowest);
  box-shadow: 0 2px 6px rgba(35, 44, 51, 0.2);
  margin-left: calc(var(--i) * -14px);
  transform: rotate(calc((var(--i) - 1) * 4deg));
}
</style>
