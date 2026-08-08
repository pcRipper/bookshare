<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { usePublicLibraryStore } from '@/stores/public'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import BookTableSkeleton from '@/components/ui/BookTableSkeleton.vue'
import BookTable from '@/components/ui/BookTable.vue'
import BookDetailModal from '@/components/ui/BookDetailModal.vue'
import BorrowBookCard from '@/components/profile/BorrowBookCard.vue'
import CollectionCard from '@/components/collections/CollectionCard.vue'
import CollectionBorrowModal from '@/components/collections/CollectionBorrowModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import StatusScreen from '@/components/ui/StatusScreen.vue'
import ViewToggle from '@/components/ui/ViewToggle.vue'
import { useBookView } from '@/composables/useBookView'

/*
 * The signed-out share page. Mirrors ProfileView's shelves and Collections tab,
 * minus everything that needs a viewer: no follow, no borrow, no edit, no
 * owner links. The read-only affordances come from the components themselves —
 * `is-self` on the cards, `readonly` on the collection modal — so this view
 * doesn't reimplement any of their rendering.
 */
const route = useRoute()
const store = usePublicLibraryStore()
const toast = useToastStore()
const { t } = useI18n()
const {
  owner, books, booksMeta, booksLoading, availableCount, shelf, booksQuery, loading, error,
  collections, collectionsMeta, collectionsLoading,
} = storeToRefs(store)
const { bookView, tableDetailed } = useBookView()

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const section = ref('books')
const collectionsLoaded = ref(false)

const tabs = computed(() => [
  { key: 'available',   label: t('profile.tabs.available'),   count: availableCount.value },
  { key: 'full',        label: t('profile.tabs.full'),        count: booksMeta.value.total },
  { key: 'collections', label: t('profile.tabs.collections'), count: collectionsMeta.value.total, collections: true },
])

function isTabActive(tab) {
  return tab.collections ? section.value === 'collections' : section.value === 'books' && shelf.value === tab.key
}

async function selectTab(tab) {
  if (tab.collections) {
    section.value = 'collections'
    if (!collectionsLoaded.value) {
      collectionsLoaded.value = true
      await loadCollections(1)
    }
    return
  }
  section.value = 'books'
  store.setShelf(tab.key)
}

async function loadCollections(page) {
  try {
    await store.fetchCollectionsPage(page)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('profile.errors.collections')))
  }
}

/* ── Read-only previews ───────────────────────────────────────────────── */
const detailBook = ref(null)
const previewCollection = ref(null)

function openDetail(book) {
  detailBook.value = book
}

/* ── Load ─────────────────────────────────────────────────────────────── */
function load() {
  section.value = 'books'
  collectionsLoaded.value = false
  // The page is reachable without an account, so the tab title is the only
  // thing naming whose shelf this is once the link is shared on.
  store.fetchLibrary(route.params.id).then(() => {
    if (owner.value) document.title = `${owner.value.fullName} · FolioShare`
  })
}
onMounted(load)
watch(() => route.params.id, load)
</script>

<template>
  <AppLayout variant="public">
    <div class="public-page">

      <!-- Loading -->
      <div v-if="loading" class="public-skeleton">
        <section class="public-skeleton__header">
          <BaseSkeleton width="96px" height="96px" circle />
          <div class="public-skeleton__lines">
            <BaseSkeleton width="55%" height="28px" />
            <BaseSkeleton width="90%" height="14px" />
            <BaseSkeleton width="70%" height="14px" />
          </div>
        </section>
        <BookGridSkeleton :count="8" />
      </div>

      <!-- Not shared / not found — the API answers both the same way, so this
           screen deliberately doesn't guess which one it was. -->
      <StatusScreen
        v-else-if="error"
        :icon="error === 'not-found' ? 'lock' : 'error'"
        :title="error === 'not-found' ? t('public.notShared.title') : t('public.error.title')"
        :message="error === 'not-found' ? t('public.notShared.message') : t('public.error.message')"
      >
        <RouterLink to="/login" class="public-state__link">{{ t('public.signIn') }}</RouterLink>
      </StatusScreen>

      <template v-else-if="owner">
        <!-- ── Owner header ────────────────────────────────────────────── -->
        <section class="public-header-card">
          <BaseAvatar :src="owner.avatarUrl" :name="owner.fullName" size="xl" />

          <div class="public-header-card__main">
            <p class="public-header-card__eyebrow">{{ t('public.eyebrow') }}</p>
            <h1 class="public-header-card__name">{{ owner.fullName }}</h1>
            <p v-if="owner.bio" class="public-header-card__bio">{{ owner.bio }}</p>
          </div>
        </section>

        <!-- ── Tabs ───────────────────────────────────────────────────── -->
        <div v-hscroll class="tab-nav" role="tablist">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            class="tab-btn"
            :class="{ 'tab-btn--active': isTabActive(tab) }"
            role="tab"
            :aria-selected="isTabActive(tab)"
            @click="selectTab(tab)"
          >
            {{ tab.label }}
            <span v-if="tab.count != null" class="tab-count">{{ tab.count }}</span>
          </button>
        </div>

        <!-- ── Books ──────────────────────────────────────────────────── -->
        <template v-if="section === 'books'">
          <div class="public-toolbar">
            <SearchInput
              :key="`${owner.id}-${shelf}`"
              class="public-search"
              :placeholder="t('library.searchPlaceholder')"
              :loading="booksLoading"
              @search="store.setBooksSearch"
            />
            <ViewToggle v-model="bookView" v-model:detailed="tableDetailed" />
          </div>

          <template v-if="booksLoading">
            <BookTableSkeleton v-if="bookView === 'table'" :count="8" :detailed="tableDetailed" />
            <BookGridSkeleton v-else :count="8" />
          </template>
          <BookTable
            v-else-if="books.length && bookView === 'table'"
            :books="books"
            :detailed="tableDetailed"
            :show-holder="false"
            role="tabpanel"
            @open="openDetail"
          />
          <div v-else-if="books.length" class="book-grid" role="tabpanel">
            <BorrowBookCard
              v-for="book in books"
              :key="book.id"
              :book="book"
              is-self
              @open="openDetail"
            />
          </div>
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">{{ booksQuery ? 'search_off' : 'auto_stories' }}</span>
            <p v-if="booksQuery">{{ t('library.noMatches', { query: booksQuery }) }}</p>
            <p v-else>{{ shelf === 'available' ? t('profile.empty.available') : t('profile.empty.full') }}</p>
          </div>

          <Pagination
            v-if="!booksLoading"
            :page="booksMeta.page"
            :total-pages="booksMeta.totalPages"
            :disabled="booksLoading"
            @change="store.fetchBooksPage"
          />
        </template>

        <!-- ── Collections ────────────────────────────────────────────── -->
        <template v-else>
          <BookGridSkeleton v-if="collectionsLoading && !collections.length" :count="4" />
          <div v-else-if="collections.length" class="book-grid" role="tabpanel">
            <CollectionCard
              v-for="c in collections"
              :key="c.id"
              :collection="c"
              variant="browse"
              is-self
              @open="previewCollection = c"
            />
          </div>
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">library_books</span>
            <p>{{ t('profile.empty.collections') }}</p>
          </div>

          <Pagination
            v-if="!collectionsLoading && collections.length"
            :page="collectionsMeta.page"
            :total-pages="collectionsMeta.totalPages"
            :disabled="collectionsLoading"
            @change="loadCollections"
          />
        </template>
      </template>
    </div>

    <BookDetailModal
      :open="!!detailBook"
      :book="detailBook"
      is-self
      @close="detailBook = null"
    />

    <CollectionBorrowModal
      :open="!!previewCollection"
      :collection="previewCollection"
      readonly
      @close="previewCollection = null"
    />
  </AppLayout>
</template>

<style scoped>
.public-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
@media (max-width: 767px) {
  .public-page { padding: var(--space-lg) var(--space-gutter) var(--space-xl); }
}

.public-state__link {
  display: inline-flex;
  align-items: center;
  padding: 12px 24px;
  border-radius: var(--radius-default);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-size: var(--text-label-md);
  font-weight: 500;
}

/* ── Loading skeleton ─────────────────────────────────────────────────── */
.public-skeleton { display: flex; flex-direction: column; gap: var(--space-lg); }
.public-skeleton__header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-md);
  padding-bottom: var(--space-md);
}
@media (min-width: 768px) {
  .public-skeleton__header { flex-direction: row; align-items: center; gap: var(--space-lg); }
}
.public-skeleton__lines {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  width: 100%;
  max-width: 32rem;
  align-items: center;
}
@media (min-width: 768px) { .public-skeleton__lines { align-items: flex-start; } }

/* ── Header ───────────────────────────────────────────────────────────── */
.public-header-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-md);
  padding-bottom: var(--space-md);
}
@media (min-width: 768px) {
  .public-header-card { flex-direction: row; text-align: left; gap: var(--space-lg); }
}

.public-header-card__main {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  min-width: 0;
}

.public-header-card__eyebrow {
  font-size: var(--text-label-sm);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
}

.public-header-card__name {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg);
  font-weight: 700;
  color: var(--color-on-surface);
  overflow-wrap: anywhere;
}

.public-header-card__bio {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  max-width: 48rem;
  white-space: pre-line;
}

/* ── Tabs ─────────────────────────────────────────────────────────────── */
.tab-nav {
  display: flex;
  gap: var(--space-sm);
  overflow-x: auto;
  scrollbar-width: none;
  border-bottom: 1px solid var(--color-surface-container-highest);
}
.tab-nav::-webkit-scrollbar { display: none; }

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  white-space: nowrap;
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  border-bottom: 2px solid transparent;
  background: none;
  cursor: pointer;
}
.tab-btn--active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}

.tab-count {
  font-size: var(--text-label-sm);
  background: var(--color-surface-container-low);
  border-radius: var(--radius-pill);
  padding: 0 var(--space-xs);
}

/* ── Grids & empty ────────────────────────────────────────────────────── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: var(--space-md);
}

.public-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}
.public-search { flex: 1 1 16rem; min-width: 0; }

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xl) 0;
  color: var(--color-on-surface-variant);
  text-align: center;
}
.empty-state__icon { font-size: 48px; opacity: 0.6; }
</style>
