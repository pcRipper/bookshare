<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useLibraryStore } from '@/stores/library'
import { useCollectionsStore } from '@/stores/collections'
import { useSubscriptionsStore } from '@/stores/subscriptions'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import StatBar from '@/components/ui/StatBar.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import BookCard from '@/components/library/BookCard.vue'
import LoanCard from '@/components/library/LoanCard.vue'
import ManageBookModal from '@/components/library/ManageBookModal.vue'
import ImportBooksModal from '@/components/library/ImportBooksModal.vue'
import SharePublicLinkModal from '@/components/library/SharePublicLinkModal.vue'
import CollectionCard from '@/components/collections/CollectionCard.vue'
import CollectionEditModal from '@/components/collections/CollectionEditModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import ViewToggle from '@/components/ui/ViewToggle.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BookTable from '@/components/ui/BookTable.vue'
import BookTableSkeleton from '@/components/ui/BookTableSkeleton.vue'
import { useBookView } from '@/composables/useBookView'
import { WISH_PRIORITIES, wishPriorityMeta, wishPriorityKey } from '@/utils/wishPriority'
import { toLoans, sortLoans, matchesFilter } from '@/utils/loans'

const store = useLibraryStore()
const collections = useCollectionsStore()
const subscriptions = useSubscriptionsStore()
const toast = useToastStore()
const { t } = useI18n()
const { profile, stats, collection, collectionMeta, collectionQuery, lending, requests, history, historyMeta, borrowing, pendingBorrowing, borrowingHistory, borrowingHistoryMeta, loading, wishlist, wishlistMeta, wishlistQuery, wishlistPriority, wishlistSort } = storeToRefs(store)

// Priority first (what to buy next) or newest first (what did I just add).
const wishlistSortOptions = computed(() => [
  { value: 'priority', label: t('wishlist.sortPriority') },
  { value: 'newest',   label: t('wishlist.sortNewest') },
])
const {
  mine: myCollections, mineMeta,
  incoming: cIncoming, pendingBorrowing: cPendingBorrowing,
  borrowing: cBorrowing, lending: cLending,
  history: cHistory, historyMeta: cHistoryMeta,
  borrowingHistory: cBorrowingHistory, borrowingHistoryMeta: cBorrowingHistoryMeta,
  loading: cLoading,
} = storeToRefs(collections)
const { following, followingMeta, loadingFollowing } = storeToRefs(subscriptions)
const { bookView, tableDetailed } = useBookView()

// Inline "mark as read" toggle from the table view; revert is handled in the
// store, so just surface a failure as a toast.
async function onToggleRead({ id, isRead }) {
  try {
    await store.setBookRead(id, isRead)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.updateBook')))
  }
}

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const activeTab = ref('collection')

/* Sharing badges count only the lists fetched eagerly on mount. `lending` and
   `cLending` are lazy, so counting them would read 0 until the tab is opened —
   which is exactly why the old Lending tab carried no badge at all. */
const borrowingCount = computed(() =>
  borrowing.value.length + cBorrowing.value.length +
  pendingBorrowing.value.length + cPendingBorrowing.value.length,
)
const lendingCount = computed(() => requests.value.length + cIncoming.value.length)

const tabs = computed(() => [
  { key: 'collection',  label: t('library.tabs.books') },
  { key: 'collections', label: t('library.tabs.collections') },
  { key: 'wishlist',    label: t('library.tabs.wishlist'), badge: stats.value.wished || null },
  // One tab for the whole loan lifecycle; the sides live below as subtabs.
  { key: 'sharing',     label: t('library.tabs.sharing'), badge: (borrowingCount.value + lendingCount.value) || null },
  { key: 'following',   label: t('library.tabs.following'), badge: followingMeta.value.total || null },
])

const statCards = computed(() => [
  { label: t('library.stats.totalBooks'), value: stats.value.totalBooks },
  { label: t('library.stats.shared'),     value: stats.value.shared },
  { label: t('library.stats.loaned'),     value: stats.value.loaned },
])

/* ── Sharing subtabs: borrowing (books I hold) vs lending (books I own) ──
   Each side carries its whole lifecycle — requests in flight, the active loan,
   then the settled ones — so a loan is found by asking "which side am I on?"
   rather than "which of four lists holds it?". This axis is the one the loan
   history was already split along, so its old inner toggle is now the subtab. */
const sharingSide = ref('borrowing')

const sharingSides = computed(() => [
  { key: 'borrowing', label: t('library.tabs.borrowing'), badge: borrowingCount.value || null },
  { key: 'lending',   label: t('library.tabs.lending'),   badge: lendingCount.value || null },
])
const isLending = computed(() => sharingSide.value === 'lending')

/* ── One list per side ────────────────────────────────────────────────────
   Six endpoints feed a side — pending / active / settled, for books and for
   collections — but they are six views of one thing, so they are normalised
   into a single `loan` shape (utils/loans.js) and merged. The lifecycle stops
   being a heading and becomes a pill on the card, which is what lets one card
   component and one grid serve the whole panel.

   The three status keywords partition the five statuses with no overlap, which
   is why `fetchRequests`/`fetchIncoming` had to move off `open`: it shares
   ReturnPending with `active` and the same loan would arrive twice. */
const sideLoans = computed(() => {
  const p = sharingSide.value
  const [books, cols] = isLending.value
    ? [[requests.value, lending.value, history.value], [cIncoming.value, cLending.value, cHistory.value]]
    : [[pendingBorrowing.value, borrowing.value, borrowingHistory.value], [cPendingBorrowing.value, cBorrowing.value, cBorrowingHistory.value]]

  return sortLoans([
    ...books.flatMap(list => toLoans(list, 'book', p)),
    ...cols.flatMap(list => toLoans(list, 'collection', p)),
  ])
})

/* ── Status filter ───────────────────────────────────────────────────────── */
const loanFilter = ref('all')
const visibleLoans = computed(() => sideLoans.value.filter(l => matchesFilter(l, loanFilter.value)))

// The settled slice is the only paginated one, so its count comes from the
// server totals rather than the page in hand — otherwise the pill would read
// "1" next to a list of twenty.
const finishedTotal = computed(() =>
  (isLending.value ? historyMeta.value.total : borrowingHistoryMeta.value.total) +
  (isLending.value ? cHistoryMeta.value.total : cBorrowingHistoryMeta.value.total),
)
function liveCount(filter) {
  return sideLoans.value.filter(l => matchesFilter(l, filter)).length
}
const loanFilters = computed(() => [
  { key: 'all',      label: t('library.loans.filter.all'),      count: liveCount('awaiting') + liveCount('onLoan') + finishedTotal.value },
  { key: 'awaiting', label: t('library.loans.filter.awaiting'), count: liveCount('awaiting') },
  { key: 'onLoan',   label: t('library.loans.filter.onLoan'),   count: liveCount('onLoan') },
  { key: 'finished', label: t('library.loans.filter.finished'), count: finishedTotal.value },
])

/* ── Paging the settled tail ─────────────────────────────────────────────
   Two independently paginated sources sit under one control: pages advance
   together and the shorter source simply runs out. Collection loans are rare
   enough beside per-book ones that this reads as one list; two pagers would
   not. Hidden for the live filters, which are bounded and unpaginated. */
const historyMetaForSide = computed(() => isLending.value ? historyMeta.value : borrowingHistoryMeta.value)
const cHistoryMetaForSide = computed(() => isLending.value ? cHistoryMeta.value : cBorrowingHistoryMeta.value)
const historyLoading = computed(() =>
  isLending.value
    ? loading.value.history || cLoading.value.history
    : loading.value.borrowingHistory || cLoading.value.borrowingHistory,
)
const historyPages = computed(() => ({
  page: historyMetaForSide.value.page,
  totalPages: Math.max(historyMetaForSide.value.totalPages || 1, cHistoryMetaForSide.value.totalPages || 1),
}))
const showHistoryPager = computed(() =>
  (loanFilter.value === 'all' || loanFilter.value === 'finished') && historyPages.value.totalPages > 1,
)
function onHistoryPage(page) {
  if (isLending.value) { store.fetchHistory(page); collections.fetchHistory(page) }
  else { store.fetchBorrowingHistory(page); collections.fetchBorrowingHistory(page) }
}

const sideEmpty = computed(() => sideLoans.value.length === 0)
const sideLoading = computed(() =>
  isLending.value
    ? loading.value.lending || loading.value.requests || cLoading.value.lending || cLoading.value.incoming || historyLoading.value
    : loading.value.borrowing || loading.value.pendingBorrowing || cLoading.value.borrowing || cLoading.value.pendingBorrowing || historyLoading.value,
)

/* ── Data loading: collection + profile up front, others lazily ───────── */
const loaded = ref({ collections: false, following: false, wishlist: false })

// The Sharing panel refetches its whole visible side on every entry rather than
// caching behind a `loaded` flag. That was already the History tab's rule — a
// loan's state changes as the *other* party acts, so a cached list goes stale
// without anything on this page touching it — and now that the requests, the
// active loans and the settled ones share one panel, the rule covers them all.
function loadSharingSide(side) {
  if (side === 'lending') {
    store.fetchRequests(); collections.fetchIncoming()
    store.fetchLending(); collections.fetchLending()
    store.fetchHistory(); collections.fetchHistory()
  } else {
    store.fetchPendingBorrowing(); collections.fetchPendingBorrowing()
    store.fetchBorrowing(); collections.fetchBorrowing()
    store.fetchBorrowingHistory(); collections.fetchBorrowingHistory()
  }
}

onMounted(() => {
  store.fetchMe()
  store.fetchCollection()
  store.fetchCategories()
})

watch(activeTab, tab => {
  if (tab === 'collections' && !loaded.value.collections) { loaded.value.collections = true; collections.fetchMine() }
  if (tab === 'wishlist' && !loaded.value.wishlist) { loaded.value.wishlist = true; store.fetchWishlist() }
  if (tab === 'following' && !loaded.value.following) { loaded.value.following = true; subscriptions.fetchFollowing() }
  if (tab === 'sharing') loadSharingSide(sharingSide.value)
})

// Switching sides while Sharing is open loads the side being revealed. The
// filter resets with it: a narrowing that made sense on one side ("Awaiting")
// can match nothing on the other, and landing on "nothing under this filter"
// reads as an empty side rather than as a filter you left on.
watch(sharingSide, side => {
  loanFilter.value = 'all'
  if (activeTab.value === 'sharing') loadSharingSide(side)
})

// Badges should reflect reality even before the tab is opened.
onMounted(() => store.fetchRequests())
onMounted(() => store.fetchBorrowing())
onMounted(() => store.fetchPendingBorrowing())
// Collection badges (Requests + Borrowing) — kept in sync from first load.
onMounted(() => { collections.fetchIncoming(); collections.fetchBorrowing(); collections.fetchPendingBorrowing() })

/* ── Loan actions ─────────────────────────────────────────────────────────
   One card serves both kinds and every state, so the ten handlers this replaced
   collapse into one dispatcher: pick the store by `loan.kind`, run it, and key
   the in-flight flag on `loan.key` (ids repeat across the two request tables).
   The store actions refetch what each transition touches, so nothing here has
   to know which list the card is about to move to. */
const loanBusy = reactive({})

async function runLoanAction(loan, action, run, errorKey) {
  if (loanBusy[loan.key]) return
  loanBusy[loan.key] = action
  try {
    await run()
  } catch (e) {
    toast.error(apiErrorMessage(e, t(errorKey)))
  } finally {
    delete loanBusy[loan.key]
  }
}
const isCollectionLoan = loan => loan.kind === 'collection'

const onLoanApprove = (loan, dueDate) => runLoanAction(loan, 'approve',
  () => isCollectionLoan(loan) ? collections.approve(loan.id, dueDate) : store.approveRequest(loan.id, dueDate),
  'library.errors.approve')

const onLoanDecline = (loan, message = null) => runLoanAction(loan, 'decline',
  () => isCollectionLoan(loan) ? collections.decline(loan.id, message) : store.declineRequest(loan.id, message),
  'library.errors.decline')

const onLoanConfirmReturn = loan => runLoanAction(loan, 'confirm-return',
  () => isCollectionLoan(loan) ? collections.confirmReturn(loan.id) : store.confirmReturn(loan.id),
  'library.errors.confirmReturn')

const onLoanReturn = loan => runLoanAction(loan, 'return',
  () => isCollectionLoan(loan) ? collections.returnCollection(loan.id) : store.returnBook(loan.id),
  'library.errors.returnLoan')

const onLoanCancel = loan => runLoanAction(loan, 'cancel',
  () => isCollectionLoan(loan) ? collections.cancel(loan.id) : store.cancelRequest(loan.id),
  'library.errors.cancelRequest')

/* ── Following (unfollow from the management list) ────────────────────── */
const unfollowing = reactive(new Set())
async function handleUnfollow(userId) {
  if (unfollowing.has(userId)) return
  unfollowing.add(userId)
  try {
    await subscriptions.unsubscribe(userId)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.unfollow')))
  } finally {
    unfollowing.delete(userId)
  }
}

/* ── Share modal ─────────────────────────────────────────────────────── */
const shareOpen = ref(false)

/* ── Manage Book modal ───────────────────────────────────────────────── */
const modalOpen = ref(false)
const modalBusy = ref(false)
const editingBook = ref(null)
// Create mode only: open with the wish checkbox already ticked (the Wish List
// tab's own add affordance). Cleared on every other open.
const creatingWished = ref(false)

function openCreate({ wished = false } = {}) {
  editingBook.value = null
  creatingWished.value = wished
  modalOpen.value = true
}
function openEdit(book) {
  editingBook.value = book
  modalOpen.value = true
}
async function onModalSave(payload) {
  modalBusy.value = true
  try {
    if (editingBook.value) {
      await store.updateBook(editingBook.value.id, payload)
    } else {
      await store.createBook(payload)
    }
    // A lent book can be edited straight from the Lending block, so refresh it
    // when that is what's on screen.
    if (activeTab.value === 'sharing' && isLending.value) await store.fetchLending()
    modalOpen.value = false
  } catch (e) {
    // Surface the failure as a toast instead of letting it bubble to the
    // app-wide error boundary (which would replace the whole page).
    toast.error(apiErrorMessage(e, t('library.errors.saveBook')))
  } finally {
    modalBusy.value = false
  }
}
// "I own this now": the book leaves the wish list for the shelf.
async function onModalAcquire(id) {
  modalBusy.value = true
  try {
    await store.acquireBook(id)
    modalOpen.value = false
    toast.success(t('wishlist.toasts.acquired'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.saveBook')))
  } finally {
    modalBusy.value = false
  }
}

async function onModalDelete(id) {
  modalBusy.value = true
  try {
    await store.deleteBook(id)
    modalOpen.value = false
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.deleteBook')))
  } finally {
    modalBusy.value = false
  }
}

/* ── Import / export ─────────────────────────────────────────────────── */
const importOpen = ref(false)
const exporting = ref(false)

async function onExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    await store.exportBooks()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.export')))
  } finally {
    exporting.value = false
  }
}

function onImported() {
  // A replace import may empty Lending, but the Sharing panel refetches its
  // whole side on every entry, so there is nothing to invalidate here.
  toast.success(t('library.toasts.imported'))
}

/* ── Collections: CRUD ───────────────────────────────────────────────── */
const collectionModalOpen = ref(false)
const collectionModalBusy = ref(false)
const editingCollection = ref(null)

function openCreateCollection() {
  editingCollection.value = null
  collectionModalOpen.value = true
}
function openEditCollection(c) {
  editingCollection.value = c
  collectionModalOpen.value = true
}
async function onCollectionSave(payload) {
  collectionModalBusy.value = true
  try {
    if (editingCollection.value) {
      await collections.updateCollection(editingCollection.value.id, payload)
    } else {
      await collections.createCollection(payload)
    }
    collectionModalOpen.value = false
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.saveCollection')))
  } finally {
    collectionModalBusy.value = false
  }
}
async function onCollectionDelete(id) {
  collectionModalBusy.value = true
  try {
    await collections.deleteCollection(id)
    collectionModalOpen.value = false
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.deleteCollection')))
  } finally {
    collectionModalBusy.value = false
  }
}

</script>

<template>
  <AppLayout>
    <div class="library-page">

      <!-- ── Profile header ────────────────────────────────────────────── -->
      <section class="profile-header">
        <div class="profile-header__info">
          <!-- Real header -->
          <template v-if="profile">
            <BaseAvatar
              :src="profile.avatarUrl"
              :name="profile.fullName"
              size="xl"
              class="profile-header__avatar"
            />
            <div class="profile-header__text">
              <h1 class="profile-header__name">{{ profile.fullName }}</h1>
              <p v-if="profile.bio" class="profile-header__bio">{{ profile.bio }}</p>
              <p v-else class="profile-header__bio profile-header__bio--muted">{{ t('library.bioEmpty') }}</p>
            </div>
          </template>

          <!-- Skeleton while the profile loads -->
          <template v-else>
            <BaseSkeleton width="96px" height="96px" circle />
            <div class="profile-header__skeleton">
              <BaseSkeleton width="180px" height="28px" />
              <BaseSkeleton width="260px" height="14px" />
            </div>
          </template>
        </div>

        <!-- Right rail: primary action + the dedicated stat block -->
        <div class="profile-header__aside">
          <button class="btn-add-book" @click="openCreate">
            <span class="material-symbols-outlined">add</span>
            {{ t('library.addNewBook') }}
          </button>
          <StatBar :stats="statCards" :loading="!profile" />
        </div>
      </section>

      <!-- ── Library content ───────────────────────────────────────────── -->
      <section class="library-content">

        <!-- Tabs -->
        <div v-hscroll class="tab-nav" role="tablist">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            class="tab-btn"
            :class="{ 'tab-btn--active': activeTab === tab.key }"
            role="tab"
            :aria-selected="activeTab === tab.key"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}
            <span v-if="tab.badge" class="tab-badge">{{ tab.badge }}</span>
          </button>
        </div>

        <!-- Collection tab -->
        <div v-if="activeTab === 'collection'" role="tabpanel">
          <!-- Search + import / export toolbar -->
          <div class="collection-toolbar">
            <!-- This panel is `v-if`-ed, so leaving the tab unmounts the search
                 box while the store keeps the filter (and the filtered page).
                 Seed it back on remount, or the list returns filtered behind an
                 empty box. -->
            <SearchInput
              class="collection-toolbar__search"
              :placeholder="t('library.searchPlaceholder')"
              :loading="loading.collection"
              :initial="collectionQuery"
              @search="store.setCollectionSearch"
            />
            <div class="collection-toolbar__actions">
              <ViewToggle v-model="bookView" v-model:detailed="tableDetailed" />
              <!-- The grid leads with an "add" placeholder card; the table has no
                   such cell, so the affordance moves into the toolbar (desktop
                   only — mobile already has the FAB). -->
              <button
                v-if="bookView === 'table'"
                class="toolbar-btn toolbar-btn--add"
                type="button"
                :aria-label="t('library.addBook')"
                @click="openCreate"
              >
                <span class="material-symbols-outlined">add</span>
                <span class="toolbar-btn__label">{{ t('library.addBook') }}</span>
              </button>
              <button class="toolbar-btn" type="button" :aria-label="t('library.share')" @click="shareOpen = true">
                <span class="material-symbols-outlined">share</span>
                <span class="toolbar-btn__label">{{ t('library.share') }}</span>
              </button>
              <button class="toolbar-btn" type="button" :aria-label="t('library.import')" @click="importOpen = true">
                <span class="material-symbols-outlined">upload</span>
                <span class="toolbar-btn__label">{{ t('library.import') }}</span>
              </button>
              <button
                class="toolbar-btn"
                type="button"
                :aria-label="t('library.export')"
                :disabled="exporting || !collection.length"
                @click="onExport"
              >
                <BaseSpinner v-if="exporting" size="sm" />
                <span v-else class="material-symbols-outlined">download</span>
                <span class="toolbar-btn__label">{{ t('library.export') }}</span>
              </button>
            </div>
          </div>

          <template v-if="loading.collection && !collection.length">
            <BookTableSkeleton v-if="bookView === 'table'" :count="8" :detailed="tableDetailed" />
            <BookGridSkeleton v-else :count="8" class="collection-skeleton" />
          </template>
          <!-- No matches for an active search -->
          <div v-else-if="collectionQuery && !collection.length" class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">search_off</span>
            <p class="empty-state__text">{{ t('library.noMatches', { query: collectionQuery }) }}</p>
          </div>
          <BookTable
            v-else-if="bookView === 'table'"
            :books="collection"
            :detailed="tableDetailed"
            read-editable
            @open="openEdit"
            @toggle-read="onToggleRead"
          />
          <div v-else class="book-grid">
            <!-- "Add new book" placeholder card, leading the grid (first page, and not while searching) -->
            <div v-if="collectionMeta.page === 1 && !collectionQuery" class="add-book-card" @click="openCreate" role="button" tabindex="0">
              <span class="material-symbols-outlined add-book-card__icon">add_circle</span>
              <h3 class="add-book-card__title">{{ t('library.addCard.bookTitle') }}</h3>
              <p class="add-book-card__hint">{{ t('library.addCard.bookHint') }}</p>
            </div>
            <BookCard
              v-for="book in collection"
              :key="book.id"
              :book="book"
              @click="openEdit"
            />
          </div>
          <Pagination
            :page="collectionMeta.page"
            :total-pages="collectionMeta.totalPages"
            :disabled="loading.collection"
            @change="store.fetchCollection"
          />
        </div>

        <!-- Collections tab (curated groups you own) -->
        <div v-else-if="activeTab === 'collections'" role="tabpanel">
          <BookGridSkeleton v-if="cLoading.mine && !myCollections.length" :count="4" />
          <template v-else>
            <div class="book-grid">
              <!-- "New collection" lead card (first page only) -->
              <div v-if="mineMeta.page === 1" class="add-book-card" role="button" tabindex="0" @click="openCreateCollection">
                <span class="material-symbols-outlined add-book-card__icon">library_add</span>
                <h3 class="add-book-card__title">{{ t('library.addCard.collectionTitle') }}</h3>
                <p class="add-book-card__hint">{{ t('library.addCard.collectionHint') }}</p>
              </div>
              <CollectionCard
                v-for="c in myCollections"
                :key="c.id"
                :collection="c"
                variant="owner"
                @edit="openEditCollection"
              />
            </div>
            <Pagination
              :page="mineMeta.page"
              :total-pages="mineMeta.totalPages"
              :disabled="cLoading.mine"
              @change="collections.fetchMine"
            />
          </template>
        </div>

        <!-- Wish List tab (books you want, not ones you hold) -->
        <div v-else-if="activeTab === 'wishlist'" role="tabpanel">
          <div class="collection-toolbar">
            <!-- Same uncontrolled-input caveat as the Books tab: the panel is
                 v-if-ed while the store keeps the filter, so seed it on mount. -->
            <SearchInput
              class="collection-toolbar__search"
              :placeholder="t('wishlist.searchPlaceholder')"
              :loading="loading.wishlist"
              :initial="wishlistQuery"
              @search="store.setWishlistSearch"
            />
            <div class="collection-toolbar__actions">
              <!-- Priority filter: pills rather than a select, because there are
                   only four choices and each carries its own colour. -->
              <div class="filter-row" role="group" :aria-label="t('wishlist.filterLabel')">
                <button
                  class="filter-pill"
                  :class="{ 'filter-pill--active': !wishlistPriority }"
                  type="button"
                  @click="store.setWishlistPriority(null)"
                >{{ t('wishlist.allLevels') }}</button>
                <button
                  v-for="p in WISH_PRIORITIES"
                  :key="p"
                  class="filter-pill"
                  :class="[`filter-pill--${wishPriorityMeta(p).tone}`, { 'filter-pill--active': wishlistPriority === p }]"
                  type="button"
                  @click="store.setWishlistPriority(p)"
                >{{ t(wishPriorityKey(p)) }}</button>
              </div>
              <BaseSelect
                class="wishlist-sort"
                :model-value="wishlistSort"
                :options="wishlistSortOptions"
                :aria-label="t('wishlist.sortLabel')"
                @update:model-value="store.setWishlistSort"
              />
              <ViewToggle v-model="bookView" v-model:detailed="tableDetailed" />
              <button
                v-if="bookView === 'table'"
                class="toolbar-btn toolbar-btn--add"
                type="button"
                :aria-label="t('wishlist.addBook')"
                @click="openCreate({ wished: true })"
              >
                <span class="material-symbols-outlined">add</span>
                <span class="toolbar-btn__label">{{ t('wishlist.addBook') }}</span>
              </button>
            </div>
          </div>

          <template v-if="loading.wishlist && !wishlist.length">
            <BookTableSkeleton v-if="bookView === 'table'" :count="8" :detailed="tableDetailed" />
            <BookGridSkeleton v-else :count="8" class="collection-skeleton" />
          </template>
          <div v-else-if="(wishlistQuery || wishlistPriority) && !wishlist.length" class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">search_off</span>
            <p class="empty-state__text">{{ t('wishlist.noMatches') }}</p>
          </div>
          <BookTable
            v-else-if="bookView === 'table'"
            :books="wishlist"
            :detailed="tableDetailed"
            wish
            read-editable
            @open="openEdit"
            @toggle-read="onToggleRead"
          />
          <div v-else class="book-grid">
            <!-- Lead card, mirroring the Books tab's "Catalog a New Book". -->
            <div
              v-if="wishlistMeta.page === 1 && !wishlistQuery && !wishlistPriority"
              class="add-book-card"
              role="button"
              tabindex="0"
              @click="openCreate({ wished: true })"
            >
              <span class="material-symbols-outlined add-book-card__icon">bookmark_add</span>
              <h3 class="add-book-card__title">{{ t('wishlist.addCard.title') }}</h3>
              <p class="add-book-card__hint">{{ t('wishlist.addCard.hint') }}</p>
            </div>
            <BookCard
              v-for="book in wishlist"
              :key="book.id"
              :book="book"
              @click="openEdit"
            />
          </div>
          <Pagination
            :page="wishlistMeta.page"
            :total-pages="wishlistMeta.totalPages"
            :disabled="loading.wishlist"
            @change="store.fetchWishlist"
          />
        </div>

        <!-- Sharing tab — every loan you are part of, in one list per side -->
        <div v-else-if="activeTab === 'sharing'" role="tabpanel">
          <!-- Borrowing (books I hold) vs Lending (books I own) -->
          <div class="subtab-nav" role="tablist">
            <button
              v-for="side in sharingSides"
              :key="side.key"
              class="subtab-nav__btn"
              :class="{ 'subtab-nav__btn--active': sharingSide === side.key }"
              role="tab"
              :aria-selected="sharingSide === side.key"
              @click="sharingSide = side.key"
            >
              {{ side.label }}
              <span v-if="side.badge" class="tab-badge">{{ side.badge }}</span>
            </button>
          </div>

          <!-- Lifecycle as a filter rather than as headings: one list, one card
               shape, and the state lives on the card. Same control the wish list
               uses for priority. -->
          <div v-if="!sideEmpty || sideLoading" class="filter-row" role="group" :aria-label="t('library.loans.filterLabel')">
            <button
              v-for="f in loanFilters"
              :key="f.key"
              class="filter-pill"
              :class="{ 'filter-pill--active': loanFilter === f.key }"
              :aria-pressed="loanFilter === f.key"
              @click="loanFilter = f.key"
            >
              {{ f.label }}
              <span v-if="f.count" class="filter-pill__count">{{ f.count }}</span>
            </button>
          </div>

          <ul v-if="sideLoading && sideEmpty" class="loan-list">
            <li v-for="n in 3" :key="n" class="loan-skeleton">
              <BaseSkeleton width="48px" height="68px" radius="var(--radius-sm)" />
              <div class="loan-skeleton__lines">
                <BaseSkeleton width="35%" height="12px" />
                <BaseSkeleton width="60%" height="16px" />
                <BaseSkeleton width="45%" height="12px" />
              </div>
            </li>
          </ul>

          <template v-else-if="!sideEmpty">
            <ul v-if="visibleLoans.length" class="loan-list">
              <li v-for="loan in visibleLoans" :key="loan.key">
                <LoanCard
                  :loan="loan"
                  :perspective="sharingSide"
                  :pending="loanBusy[loan.key] || null"
                  @approve="onLoanApprove"
                  @decline="onLoanDecline"
                  @confirm-return="onLoanConfirmReturn"
                  @return="onLoanReturn"
                  @cancel="onLoanCancel"
                />
              </li>
            </ul>
            <p v-else class="empty-requests">{{ t('library.loans.noMatches') }}</p>

            <!-- Pages the settled tail only; the live loans above are bounded. -->
            <Pagination
              v-if="showHistoryPager"
              :page="historyPages.page"
              :total-pages="historyPages.totalPages"
              :disabled="historyLoading"
              @change="onHistoryPage"
            />
          </template>

          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">{{ isLending ? 'local_library' : 'auto_stories' }}</span>
            <p class="empty-state__text">{{ isLending ? t('library.empty.lending') : t('library.empty.borrowing') }}</p>
            <RouterLink v-if="!isLending" to="/discover" class="empty-state__link">{{ t('library.empty.borrowingLink') }}</RouterLink>
          </div>
        </div>

        <!-- Following tab (people you subscribe to) -->
        <div v-else role="tabpanel">
          <ul v-if="loadingFollowing && !following.length" class="following-list">
            <li v-for="n in 4" :key="n" class="following-row">
              <BaseSkeleton width="44px" height="44px" circle />
              <BaseSkeleton width="160px" height="16px" />
            </li>
          </ul>
          <ul v-else-if="following.length" class="following-list">
            <li v-for="sub in following" :key="sub.id" class="following-row">
              <RouterLink :to="`/profile/${sub.user.id}`" class="following-row__person">
                <BaseAvatar :src="sub.user.avatarUrl" :name="sub.user.fullName" size="md" />
                <span class="following-row__name">{{ sub.user.fullName }}</span>
              </RouterLink>
              <button
                class="following-row__unfollow"
                :disabled="unfollowing.has(sub.user.id)"
                @click="handleUnfollow(sub.user.id)"
              >
                <BaseSpinner v-if="unfollowing.has(sub.user.id)" size="sm" />
                <span v-else>{{ t('library.unfollow') }}</span>
              </button>
            </li>
          </ul>
          <Pagination
            v-if="following.length"
            :page="followingMeta.page"
            :total-pages="followingMeta.totalPages"
            :disabled="loadingFollowing"
            @change="subscriptions.fetchFollowing"
          />
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">group</span>
            <p class="empty-state__text">{{ t('library.empty.following') }}</p>
            <RouterLink to="/discover" class="empty-state__link">{{ t('library.empty.followingLink') }}</RouterLink>
          </div>
        </div>

      </section>
    </div>

    <!-- Mobile FAB (hidden on desktop) -->
    <!-- The toolbar's add button is desktop-only, so on a phone this is the only
         way onto the Wish List tab's create flow — it follows the active tab. -->
    <button
      class="fab"
      :aria-label="activeTab === 'wishlist' ? t('wishlist.addBook') : t('library.addBookAria')"
      @click="openCreate({ wished: activeTab === 'wishlist' })"
    >
      <span class="material-symbols-outlined">add</span>
    </button>

    <!-- Add / edit book modal -->
    <ManageBookModal
      :open="modalOpen"
      :book="editingBook"
      :busy="modalBusy"
      :wished="creatingWished"
      @save="onModalSave"
      @delete="onModalDelete"
      @acquire="onModalAcquire"
      @close="modalOpen = false"
    />

    <!-- Public share link + QR -->
    <SharePublicLinkModal
      :open="shareOpen"
      :profile="profile"
      @close="shareOpen = false"
    />

    <!-- Import books modal -->
    <ImportBooksModal
      :open="importOpen"
      @imported="onImported"
      @close="importOpen = false"
    />

    <!-- Create / edit collection modal (edit mode carries a Delete action) -->
    <CollectionEditModal
      :open="collectionModalOpen"
      :collection="editingCollection"
      :busy="collectionModalBusy"
      @save="onCollectionSave"
      @delete="onCollectionDelete"
      @close="collectionModalOpen = false"
    />
  </AppLayout>
</template>

<style scoped>
/* ── Page wrapper ─────────────────────────────────────────────────────── */
.library-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-lg) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}
@media (max-width: 767px) {
  .library-page {
    padding: var(--space-md) var(--space-gutter) var(--space-xl);
    gap: var(--space-md);
  }
}

/* ── Profile header ───────────────────────────────────────────────────── */
.profile-header {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  padding-bottom: var(--space-sm);
}
@media (min-width: 768px) {
  .profile-header {
    flex-direction: row;
    align-items: flex-start;
    gap: var(--space-md);
  }
  /* Identity takes the slack; the aside (action + stat block) sits right. */
  .profile-header__info { flex: 1; }
}

.profile-header__info {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}
/* Let the text column shrink so long names/bios wrap instead of widening the row. */
.profile-header__info > * { min-width: 0; }
@media (max-width: 767px) {
  .profile-header__info { align-items: flex-start; }
}

.profile-header__avatar { flex-shrink: 0; }

.profile-header__name {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-background);
  margin: 0 0 4px;
  overflow-wrap: anywhere;
}
@media (min-width: 768px) {
  .profile-header__name {
    font-size: var(--text-headline-xl);
    line-height: var(--lh-headline-xl);
    letter-spacing: var(--ls-headline-xl);
    margin-bottom: var(--space-xs);
  }
}

.profile-header__bio {
  font-size: var(--text-body-md);
  color: var(--color-secondary);
  margin: 0 0 var(--space-sm);
}
@media (min-width: 768px) {
  .profile-header__bio { margin-bottom: var(--space-md); }
}

/* Right rail: the primary action over the dedicated stat block.
   Full-width column on mobile (Add is hidden there → just the stat card);
   on desktop the two fuse into one framed panel — the navy button is its
   header, the stat rows its body. */
.profile-header__aside {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  min-width: 0;
}
@media (min-width: 768px) {
  .profile-header__aside {
    align-items: stretch;
    flex-shrink: 0;
    width: 232px;
    gap: 0;
    border: 1px solid var(--color-outline-variant);
    border-radius: var(--radius-lg);
    background: var(--color-surface-container-low);
    overflow: hidden; /* clip the button's top corners to the panel radius */
  }
}

.btn-add-book {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 12px 24px;
  background: var(--color-primary);
  color: var(--color-on-primary);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.2s;
}
/* Mobile uses the floating action button instead, so hide the header one
   to avoid two competing "add book" affordances on the same screen. */
@media (max-width: 767px) { .btn-add-book { display: none; } }
.btn-add-book:hover { background: var(--color-primary-container); }
/* Desktop: the button is the panel header — square (the panel clips to its
   own radius), centered, and divided from the stat rows below. */
@media (min-width: 768px) {
  .profile-header__aside .btn-add-book {
    justify-content: center;
    border-radius: 0;
    border-bottom: 1px solid var(--color-outline-variant);
  }
}

/* ── Library content section ─────────────────────────────────────────── */
.library-content { display: flex; flex-direction: column; gap: var(--space-md); }

/* Tab nav */
.tab-nav {
  display: flex;
  border-bottom: 1px solid var(--color-surface-container-highest);
  overflow-x: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.tab-nav::-webkit-scrollbar { display: none; }

/* Even at five tabs the strip still overflows a phone — measured 565px of tabs
   in 342px at 390 wide — so the scroller and its cue stay. A hidden scrollbar
   leaves nothing to say the strip scrolls, and anything past the fold reads as
   missing; wrapping would cost extra rows. The cue is a shadow at whichever edge
   has more tabs behind it, which retracts once you reach that end. The `local`
   gradients are the page-coloured covers that hide each shadow when there's
   nothing more to scroll to; the `scroll` ones are the shadows themselves.
   Tighter padding fits one more tab in the same width. */
@media (max-width: 767px) {
  .tab-nav {
    background:
      linear-gradient(to right, var(--color-background) 45%, transparent) left center / 36px 100% no-repeat local,
      linear-gradient(to left, var(--color-background) 45%, transparent) right center / 36px 100% no-repeat local,
      radial-gradient(farthest-side at 0 50%, rgba(35, 44, 51, 0.3), transparent) left center / 20px 100% no-repeat scroll,
      radial-gradient(farthest-side at 100% 50%, rgba(35, 44, 51, 0.3), transparent) right center / 20px 100% no-repeat scroll;
    scroll-behavior: smooth;
  }
  .tab-btn { padding-inline: var(--space-sm); }
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-label-md);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  color: var(--color-secondary);
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color 0.2s, border-color 0.2s;
}
.tab-btn:hover { color: var(--color-on-background); }
.tab-btn--active {
  color: var(--color-primary);
  border-bottom-color: var(--color-accent);
  font-weight: 600;
}

.tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  background: var(--color-primary);
  color: var(--color-on-primary);
  border-radius: var(--radius-full);
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}
.tab-btn--active .tab-badge { background: var(--color-primary); }
.tab-btn:not(.tab-btn--active) .tab-badge { background: var(--color-outline); }

/* ── Collection toolbar (search + import / export) ────────────────────── */
.collection-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding-top: var(--space-sm);
}
.collection-toolbar__search { flex: 1 1 220px; min-width: 0; }
.collection-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
  /* Shrinkable on purpose: with flex-shrink:0 this row keeps its max-content
     width, so its own flex-wrap never engages and extra buttons overflow the
     viewport instead of wrapping. */
  min-width: 0;
}
.toolbar-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 8px 16px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.toolbar-btn:hover:not(:disabled) {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
  border-color: var(--color-outline);
}
.toolbar-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.toolbar-btn .material-symbols-outlined { font-size: 18px; }
/* Mobile uses the floating action button instead, so hide the toolbar one —
   same rule as .btn-add-book. Must come after the .toolbar-btn base rule:
   same specificity, later wins. */
@media (max-width: 767px) { .toolbar-btn--add { display: none; } }
/* Phones: icons only. Four labelled buttons don't fit a narrow viewport, and
   wrapping them onto a second row costs more vertical space above the grid
   than the labels are worth. Each button keeps an aria-label, so the icon is
   never the only thing naming the action. */
@media (max-width: 767px) {
  .toolbar-btn__label { display: none; }
  .toolbar-btn {
    padding: 8px 12px;
    gap: 0;
  }
}

/* ── Wish-list sort + priority filter ─────────────────────────────────── */
/* Narrow enough not to crowd the toolbar; the two labels are short. */
.wishlist-sort { min-width: 168px; }


/* One filter-pill row, shared by the wish list (priority) and the Sharing panel
   (loan state). The colour modifiers below belong to the wish list alone. */
/* Block-level, not inline: in the Sharing panel it follows the subtab pills,
   and as an inline row the two controls ran together into one pill strip that
   looked like a single six-way choice. */
.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
}
.filter-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.filter-pill:hover:not(.filter-pill--active) {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
}
/* Only the selected pill wears its colour — three permanently-lit pills would
   read as three states rather than one choice. */
.filter-pill--active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 600;
}
.filter-pill--amber.filter-pill--active {
  background: var(--color-tertiary);
  border-color: var(--color-tertiary);
  color: #ffffff;
}
.filter-pill--red.filter-pill--active {
  background: var(--color-error);
  border-color: var(--color-error);
  color: #ffffff;
}
/* The count rides inside the pill, so it inverts with it rather than sitting on
   the same fill and disappearing. */
.filter-pill__count {
  font-size: 11px;
  font-weight: 700;
  opacity: 0.75;
}
.filter-pill--active .filter-pill__count { opacity: 0.9; }

/* ── Book grid ────────────────────────────────────────────────────────── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
  padding-top: var(--space-sm);
}
@media (min-width: 600px) {
  .book-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (min-width: 960px) {
  .book-grid { grid-template-columns: repeat(4, 1fr); }
}

/* Match the loaded grid's top offset so the loading skeleton doesn't sit flush
   against the import/export toolbar. */
.collection-skeleton { padding-top: var(--space-sm); }

/* Add-book placeholder card */
.add-book-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  background: var(--color-surface-container-low);
  border: 1.5px dashed var(--color-outline-variant);
  border-radius: var(--radius-default);
  padding: var(--space-md);
  text-align: center;
  cursor: pointer;
  min-height: 260px;
  transition: background 0.2s, border-color 0.2s;
}
.add-book-card:hover {
  background: var(--color-surface-variant);
  border-color: var(--color-outline);
}
.add-book-card__icon {
  font-size: 40px;
  color: var(--color-primary);
  margin-bottom: 4px;
}
.add-book-card__title {
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--color-primary);
  margin: 0;
}
.add-book-card__hint {
  font-size: var(--text-label-md);
  color: var(--color-secondary);
  margin: 0;
}

/* ── Loan list (Sharing) ──────────────────────────────────────────────────
   Deliberately a single column, not a grid: every row is the same card and the
   eye should run straight down them. A multi-column grid was what made the old
   panel read as several unrelated blocks. Capped so the meta line and the
   action stay a readable distance apart on a wide screen. */
.loan-list {
  list-style: none;
  margin: 0;
  padding: var(--space-sm) 0 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  max-width: 860px;
}

.loan-skeleton {
  display: flex;
  gap: var(--space-md);
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  padding: var(--space-md);
}
.loan-skeleton__lines {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  flex: 1;
  justify-content: center;
}

.empty-requests {
  font-size: var(--text-body-md);
  color: var(--color-on-surface-variant);
  padding: var(--space-xl) 0;
  text-align: center;
  grid-column: 1 / -1;
}

/* ── Empty states ─────────────────────────────────────────────────────── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  padding: var(--space-xl) 0;
  color: var(--color-on-surface-variant);
  text-align: center;
}
.empty-state__icon { font-size: 48px; opacity: 0.5; }
.empty-state__text { font-size: var(--text-body-md); margin: 0; }
.empty-state__link { color: var(--color-primary); font-weight: 500; }
.empty-state__link:hover { text-decoration: underline; }

/* ── Loading skeletons ────────────────────────────────────────────────── */
.profile-header__skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

/* ── Sharing subtabs (borrowing / lending) ───────────────────────────── */
/* The segmented pill pair the loan history already used for this same axis. */
.subtab-nav {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  margin: var(--space-sm) 0;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
}
.subtab-nav__btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 6px 16px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  transition: background 0.2s, color 0.2s;
}
.subtab-nav__btn:hover:not(.subtab-nav__btn--active) { color: var(--color-on-background); }
.subtab-nav__btn--active {
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 600;
}
/* The badge's own fill is the primary green, which the active pill also uses —
   invert it there so the count doesn't vanish into its own background. */
.subtab-nav__btn--active .tab-badge {
  background: var(--color-on-primary);
  color: var(--color-primary);
}
.subtab-nav__btn:not(.subtab-nav__btn--active) .tab-badge { background: var(--color-outline); }

/* ── Following list ───────────────────────────────────────────────────── */
.following-list {
  list-style: none;
  margin: 0;
  padding: var(--space-sm) 0 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.following-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
}
.following-row__person {
  display: inline-flex;
  align-items: center;
  gap: var(--space-sm);
  min-width: 0;
  color: var(--color-on-background);
}
.following-row__name {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.15s;
}
.following-row__person:hover .following-row__name { color: var(--color-primary); }
.following-row__unfollow {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 92px;
  padding: 6px 14px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.following-row__unfollow:hover:not(:disabled) {
  background: var(--color-error-container);
  color: var(--color-error);
  border-color: var(--color-error-container);
}
.following-row__unfollow:disabled { opacity: 0.7; cursor: default; }

/* Muted placeholder bio */
.profile-header__bio--muted { font-style: italic; opacity: 0.7; }

/* ── Mobile FAB ───────────────────────────────────────────────────────── */
.fab {
  position: fixed;
  bottom: calc(64px + var(--space-md) + env(safe-area-inset-bottom)); /* above bottom nav */
  right: var(--space-gutter);
  width: 56px;
  height: 56px;
  border-radius: var(--radius-full);
  background: var(--color-primary);
  color: var(--color-on-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(39, 71, 56, 0.35);
  z-index: 40;
  transition: background 0.2s, transform 0.15s;
}
.fab:hover { background: var(--color-primary-container); }
.fab:active { transform: scale(0.95); }

@media (min-width: 768px) {
  .fab { display: none; }
}
</style>
