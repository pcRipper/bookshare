<script setup>
import { computed, reactive, onMounted, onBeforeUnmount } from 'vue'
import { storeToRefs } from 'pinia'
import { useDiscoverStore } from '@/stores/discover'
import AppLayout from '@/components/layout/AppLayout.vue'
import DiscoverBookCard from '@/components/discover/DiscoverBookCard.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import { resolveCategoryColors } from '@/utils/categoryColors'

const store = useDiscoverStore()
const { books, categories, loading, error, query, activeCategory } = storeToRefs(store)

onMounted(store.init)

/* ── Search (debounced) ───────────────────────────────────────────────── */
let debounce = null
function onSearchInput(e) {
  query.value = e.target.value
  clearTimeout(debounce)
  debounce = setTimeout(() => store.fetchBooks(), 300)
}
function submitSearch() {
  clearTimeout(debounce)
  store.fetchBooks()
}
onBeforeUnmount(() => clearTimeout(debounce))

/* ── Filters ──────────────────────────────────────────────────────────── */
function pillStyle(cat) {
  const c = resolveCategoryColors(cat.colorHex)
  if (activeCategory.value === cat.id) {
    return { background: c.text, color: '#fff', borderColor: c.text }
  }
  return { background: c.bg, color: c.text, borderColor: c.border }
}

const hasFilters = computed(() => !!query.value.trim() || activeCategory.value != null)

const resultsHeading = computed(() => (hasFilters.value ? 'Results' : 'Recommended for You'))

/* ── Borrow requests (per-book in-flight tracking for button loaders) ──── */
const requesting = reactive(new Set())
async function onRequest(id) {
  if (requesting.has(id)) return
  requesting.add(id)
  try {
    await store.requestBorrow(id)
  } finally {
    requesting.delete(id)
  }
}
</script>

<template>
  <AppLayout>
    <div class="discover-page">
      <!-- ── Hero + search ──────────────────────────────────────────────── -->
      <section class="discover-hero">
        <h1 class="discover-hero__title">Discover</h1>
        <p class="discover-hero__subtitle">
          Explore the community's shelves, uncover hidden gems, and borrow your next read.
        </p>
        <form class="discover-search" role="search" @submit.prevent="submitSearch">
          <span class="material-symbols-outlined discover-search__icon">search</span>
          <input
            :value="query"
            class="discover-search__input"
            type="search"
            placeholder="Search titles or authors…"
            aria-label="Search the community's books"
            @input="onSearchInput"
          />
        </form>
      </section>

      <!-- ── Category filter pills ──────────────────────────────────────── -->
      <section v-if="categories.length" class="discover-filters" aria-label="Filter by category">
        <h2 class="discover-filters__label">Browse by category</h2>
        <div class="discover-filters__pills hide-scrollbar">
          <button
            class="pill"
            :class="{ 'pill--active': activeCategory == null }"
            @click="store.setCategory(null)"
          >
            All
          </button>
          <button
            v-for="cat in categories"
            :key="cat.id"
            class="pill"
            :style="pillStyle(cat)"
            @click="store.setCategory(cat.id)"
          >
            {{ cat.name }}
          </button>
        </div>
      </section>

      <!-- ── Results ────────────────────────────────────────────────────── -->
      <section class="discover-results">
        <div class="discover-results__header">
          <h2 class="discover-results__heading">{{ resultsHeading }}</h2>
          <button v-if="hasFilters" class="discover-results__clear" @click="store.clearFilters()">
            Clear filters
          </button>
        </div>

        <!-- Loading -->
        <BookGridSkeleton v-if="loading" :count="8" />

        <!-- Error -->
        <div v-else-if="error" class="discover-state">
          <span class="material-symbols-outlined discover-state__icon">error</span>
          <p>Something went wrong loading Discover.</p>
          <button class="discover-results__clear" @click="store.fetchBooks()">Try again</button>
        </div>

        <!-- Results grid -->
        <div v-else-if="books.length" class="book-grid">
          <DiscoverBookCard
            v-for="book in books"
            :key="book.id"
            :book="book"
            :pending="requesting.has(book.id)"
            @request="onRequest"
          />
        </div>

        <!-- Empty / no results -->
        <div v-else class="discover-state">
          <span class="material-symbols-outlined discover-state__icon">{{ hasFilters ? 'search_off' : 'travel_explore' }}</span>
          <p v-if="hasFilters">No books match your search just yet.</p>
          <p v-else>No books are being shared by the community right now. Check back soon.</p>
          <button v-if="hasFilters" class="discover-results__clear" @click="store.clearFilters()">
            Clear filters
          </button>
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<style scoped>
.discover-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-lg) var(--space-gutter) var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}

/* ── Hero ─────────────────────────────────────────────────────────────── */
.discover-hero { display: flex; flex-direction: column; gap: var(--space-sm); }
@media (min-width: 768px) {
  .discover-hero { align-items: center; text-align: center; gap: var(--space-md); }
}
.discover-hero__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-surface);
  margin: 0;
}
@media (min-width: 768px) {
  .discover-hero__title { font-size: var(--text-headline-xl); line-height: var(--lh-headline-xl); letter-spacing: var(--ls-headline-xl); }
}
.discover-hero__subtitle {
  font-size: var(--text-body-md);
  line-height: var(--lh-body-md);
  color: var(--color-on-surface-variant);
  margin: 0;
  max-width: 42rem;
}
@media (min-width: 768px) { .discover-hero__subtitle { font-size: var(--text-body-lg); } }

.discover-search {
  position: relative;
  width: 100%;
  margin-top: var(--space-xs);
}
@media (min-width: 768px) { .discover-search { max-width: 640px; } }
.discover-search__icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-outline);
  pointer-events: none;
}
.discover-search__input {
  width: 100%;
  padding: 14px 16px 14px 46px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-lg);
  background: var(--color-surface-container-lowest);
  font-family: var(--font-body);
  font-size: var(--text-body-md);
  color: var(--color-on-background);
  box-shadow: 0 1px 2px rgba(35, 44, 51, 0.04);
  transition: border-color 0.2s, box-shadow 0.2s;
}
.discover-search__input:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-fixed);
}

/* ── Filters ──────────────────────────────────────────────────────────── */
.discover-filters { display: flex; flex-direction: column; gap: var(--space-sm); }
.discover-filters__label {
  font-size: var(--text-label-sm);
  letter-spacing: 0.05em;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
  margin: 0;
}
.discover-filters__pills {
  display: flex;
  gap: var(--space-sm);
  overflow-x: auto;
  padding-bottom: var(--space-xs);
}
@media (min-width: 768px) { .discover-filters__pills { flex-wrap: wrap; overflow-x: visible; } }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.hide-scrollbar::-webkit-scrollbar { display: none; }

.pill {
  flex: none;
  padding: 8px 16px;
  border-radius: var(--radius-full);
  border: 1px solid var(--color-outline-variant);
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  font-size: var(--text-label-md);
  font-weight: 500;
  white-space: nowrap;
  transition: opacity 0.15s, transform 0.1s, box-shadow 0.15s;
}
.pill:hover { opacity: 0.85; }
.pill:active { transform: scale(0.97); }
.pill--active {
  background: var(--color-primary);
  color: var(--color-on-primary);
  border-color: var(--color-primary);
}

/* ── Results ──────────────────────────────────────────────────────────── */
.discover-results { display: flex; flex-direction: column; gap: var(--space-md); }
.discover-results__header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-outline-variant);
  padding-bottom: var(--space-xs);
}
.discover-results__heading {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  color: var(--color-on-surface);
  margin: 0;
}
@media (min-width: 768px) { .discover-results__heading { font-size: var(--text-headline-lg); line-height: var(--lh-headline-lg); } }
.discover-results__clear {
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-primary);
}
.discover-results__clear:hover { text-decoration: underline; }

.book-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
}
@media (min-width: 600px) { .book-grid { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 960px) { .book-grid { grid-template-columns: repeat(4, 1fr); } }

/* ── States ───────────────────────────────────────────────────────────── */
.discover-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  padding: var(--space-xl) 0;
  color: var(--color-on-surface-variant);
  text-align: center;
}
.discover-state__icon { font-size: 48px; opacity: 0.5; }
.discover-state p { margin: 0; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
