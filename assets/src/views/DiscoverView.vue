<script setup>
import { computed, reactive, onMounted, onBeforeUnmount } from 'vue'
import { storeToRefs } from 'pinia'
import { useDiscoverStore } from '@/stores/discover'
import AppLayout from '@/components/layout/AppLayout.vue'
import DiscoverBookCard from '@/components/discover/DiscoverBookCard.vue'
import DiscoverUserCard from '@/components/discover/DiscoverUserCard.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import { resolveCategoryColors } from '@/utils/categoryColors'

const store = useDiscoverStore()
const { mode, books, accounts, categories, loading, error, query, activeCategory, activeLanguage } = storeToRefs(store)

onMounted(store.init)

/* ── Search mode (books ↔ accounts) ───────────────────────────────────── */
const isAccounts = computed(() => mode.value === 'accounts')
const searchPlaceholder = computed(() =>
  isAccounts.value ? 'Search readers by name…' : 'Search titles or authors…',
)

/* ── Search (debounced) ───────────────────────────────────────────────── */
let debounce = null
function onSearchInput(e) {
  query.value = e.target.value
  clearTimeout(debounce)
  debounce = setTimeout(() => store.fetchActive(), 300)
}
function submitSearch() {
  clearTimeout(debounce)
  store.fetchActive()
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

const hasQuery = computed(() => !!query.value.trim())
const hasFilters = computed(() =>
  hasQuery.value || (!isAccounts.value && (activeCategory.value != null || activeLanguage.value != null)),
)

const resultsHeading = computed(() => {
  if (isAccounts.value) return 'Readers'
  return hasFilters.value ? 'Results' : 'Recommended for You'
})

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

/* ── Follow / unfollow (per-account in-flight tracking) ────────────────── */
const following = reactive(new Set())
async function onToggleFollow(action, id) {
  if (following.has(id)) return
  following.add(id)
  try {
    await store[action](id)
  } finally {
    following.delete(id)
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
        <div class="discover-controls">
          <div class="discover-toggle" role="tablist" aria-label="Search for">
            <span
              class="discover-toggle__thumb"
              :class="{ 'discover-toggle__thumb--right': isAccounts }"
              aria-hidden="true"
            ></span>
            <button
              class="discover-toggle__btn"
              :class="{ 'discover-toggle__btn--active': !isAccounts }"
              role="tab"
              :aria-selected="!isAccounts"
              @click="store.setMode('books')"
            >
              <span class="material-symbols-outlined">menu_book</span>
              Books
            </button>
            <button
              class="discover-toggle__btn"
              :class="{ 'discover-toggle__btn--active': isAccounts }"
              role="tab"
              :aria-selected="isAccounts"
              @click="store.setMode('accounts')"
            >
              <span class="material-symbols-outlined">group</span>
              Accounts
            </button>
          </div>

          <form class="discover-search" role="search" @submit.prevent="submitSearch">
            <span class="material-symbols-outlined discover-search__icon">search</span>
            <input
              :value="query"
              class="discover-search__input"
              type="search"
              :placeholder="searchPlaceholder"
              :aria-label="isAccounts ? 'Search the community\'s readers' : 'Search the community\'s books'"
              @input="onSearchInput"
            />
          </form>
        </div>
      </section>

      <!-- ── Filters (books mode only): category pills + language ───────── -->
      <section v-if="!isAccounts" class="discover-filters" aria-label="Filter books">
        <div v-if="categories.length" class="discover-filters__group">
          <h2 class="discover-filters__label">Browse by category</h2>
          <div v-hscroll class="discover-filters__pills hide-scrollbar">
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
        </div>

        <div class="discover-filters__group discover-filters__group--language">
          <h2 class="discover-filters__label">Language</h2>
          <LanguageSelect
            :model-value="activeLanguage"
            class="discover-filters__lang"
            @update:model-value="store.setLanguage($event)"
          />
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
          <button class="discover-results__clear" @click="store.fetchActive()">Try again</button>
        </div>

        <!-- ── Accounts mode ──────────────────────────────────────────── -->
        <template v-else-if="isAccounts">
          <!-- Results grid -->
          <div v-if="accounts.length" class="book-grid">
            <DiscoverUserCard
              v-for="user in accounts"
              :key="user.id"
              :user="user"
              :pending="following.has(user.id)"
              @follow="onToggleFollow('follow', $event)"
              @unfollow="onToggleFollow('unfollow', $event)"
            />
          </div>

          <!-- Prompt to search (empty box) -->
          <div v-else-if="!hasQuery" class="discover-state">
            <span class="material-symbols-outlined discover-state__icon">person_search</span>
            <p>Search for readers by name to find people to follow.</p>
          </div>

          <!-- No matches -->
          <div v-else class="discover-state">
            <span class="material-symbols-outlined discover-state__icon">search_off</span>
            <p>No readers match your search just yet.</p>
          </div>
        </template>

        <!-- ── Books mode ─────────────────────────────────────────────── -->
        <template v-else>
          <!-- Results grid -->
          <div v-if="books.length" class="book-grid">
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
        </template>
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

/* ── Controls row (toggle + search) ───────────────────────────────────── */
.discover-controls {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  width: 100%;
}
/* Wide screens: lay the toggle and search box out on a single line. */
@media (min-width: 960px) {
  .discover-controls {
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: var(--space-md);
    max-width: 820px;
    margin: 0 auto;
  }
  .discover-controls .discover-toggle { flex: none; align-self: auto; }
  .discover-controls .discover-search { flex: 1; max-width: none; margin-top: 0; }
}

/* ── Books ↔ Accounts toggle ──────────────────────────────────────────── */
.discover-toggle {
  position: relative;
  display: inline-flex;
  align-self: flex-start;
  padding: 4px;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
}
@media (min-width: 768px) { .discover-toggle { align-self: center; } }

/* Sliding pill that tracks the active segment. */
.discover-toggle__thumb {
  position: absolute;
  top: 4px;
  bottom: 4px;
  left: 4px;
  width: calc(50% - 4px);
  border-radius: var(--radius-full);
  background: var(--color-primary);
  box-shadow: 0 1px 3px rgba(35, 44, 51, 0.18);
  transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}
.discover-toggle__thumb--right { transform: translateX(100%); }

.discover-toggle__btn {
  position: relative;
  z-index: 1;
  flex: 1 1 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  padding: 8px 22px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  cursor: pointer;
  transition: color 0.28s;
}
.discover-toggle__btn .material-symbols-outlined { font-size: 19px; }
.discover-toggle__btn:hover:not(.discover-toggle__btn--active) { color: var(--color-on-background); }
.discover-toggle__btn--active {
  color: var(--color-on-primary);
  font-weight: 600;
}
@media (min-width: 768px) {
  .discover-toggle__btn { padding: 9px 30px; font-size: var(--text-body-md); }
  .discover-toggle__btn .material-symbols-outlined { font-size: 20px; }
}

/* ── Filters ──────────────────────────────────────────────────────────── */
.discover-filters { display: flex; flex-direction: column; gap: var(--space-md); }
.discover-filters__group { display: flex; flex-direction: column; gap: var(--space-sm); }
.discover-filters__group--language { max-width: 280px; }
.discover-filters__lang { width: 100%; }
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
