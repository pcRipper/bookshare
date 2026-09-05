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
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import BookShelfPanel from '@/components/library/BookShelfPanel.vue'
import LoanCard from '@/components/library/LoanCard.vue'
import ManageBookModal from '@/components/library/ManageBookModal.vue'
import ImportBooksModal from '@/components/library/ImportBooksModal.vue'
import SharePublicLinkModal from '@/components/library/SharePublicLinkModal.vue'
import CollectionCard from '@/components/collections/CollectionCard.vue'
import CollectionEditModal from '@/components/collections/CollectionEditModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SubTabNav from '@/components/ui/SubTabNav.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
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

// Inline "mark as read" toggle from the table view; revert is handled in the
// store, so just surface a failure as a toast.
async function onToggleRead({ id, isRead }) {
  try {
    await store.setBookRead(id, isRead)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('library.errors.updateBook')))
  }
}

/* ── Tabs ─────────────────────────────────────────────────────────────────
   Three, and three is the point. Books, Collections and Wish List were three
   top-level tabs holding three shelves of the same catalogue — the wish list is
   literally the Books shelf under `is_wished`, and a collection is a grouping
   *of* the Books shelf — so finding something meant knowing which shelf it had
   been filed on before you could look for it. They are now one **Books** tab
   with the shelf as a subtab, exactly the move Sharing made for the four loan
   lists, and the strip is down from five entries to three: one for what you
   have, one for what is on loan, one for who you read alongside. Three fits a
   phone without scrolling, which is what lets the strip below be static. */
const activeTab = ref('books')

/* Sharing badges count only the lists fetched eagerly on mount. `lending` and
   `cLending` are lazy, so counting them would read 0 until the tab is opened —
   which is exactly why the old Lending tab carried no badge at all. */
const borrowingCount = computed(() =>
  borrowing.value.length + cBorrowing.value.length +
  pendingBorrowing.value.length + cPendingBorrowing.value.length,
)
const lendingCount = computed(() => requests.value.length + cIncoming.value.length)

/* The top strip carries no counters. Books can't have one — a shelf size is not
   a task — so a number on the other two made the three tabs three different
   kinds of thing, and on a phone, where the icon sits above the label, the
   badge had nowhere to go but on top of the icon. The counts live one level
   down instead, on the Borrowing / Lending pills, where they are next to the
   loans they describe. */
const tabs = computed(() => [
  // One tab for the whole catalogue and one for the whole loan lifecycle; both
  // split into subtabs below.
  { key: 'books',     label: t('library.tabs.books'),     icon: 'book_2' },
  { key: 'sharing',   label: t('library.tabs.sharing'),   icon: 'swap_horiz' },
  { key: 'following', label: t('library.tabs.following'), icon: 'group' },
])
const activeTabIndex = computed(() => Math.max(tabs.value.findIndex(x => x.key === activeTab.value), 0))

// Arrow keys walk the strip, which is what `role="tablist"` promises. The whole
// list is one tab stop (the active button), so Tab still leaves the strip in one
// press rather than stepping through every panel switch.
function onTabKeydown(e) {
  const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0
  if (!dir) return
  e.preventDefault()
  const list = tabs.value
  const next = (activeTabIndex.value + dir + list.length) % list.length
  activeTab.value = list[next].key
  // Focus by index, not by `[aria-selected]` — that attribute won't have moved
  // until the next render, so selecting it here would refocus the old tab.
  e.currentTarget.querySelectorAll('[role="tab"]')[next]?.focus()
}

/* ── Books subtabs: the three shelves of your own catalogue ───────────────
   Books (what you hold) · Collections (how you've grouped it) · Wish List
   (what you don't hold yet). The pills carry *counts*, not badges: Sharing's
   badge means "these want something from you", and a shelf being large is not a
   task. Every count comes from the one eager `fetchMe()`, so none of them reads
   0 until its shelf is opened — the trap the Sharing badges had to work around. */
const shelf = ref('books')

const shelves = computed(() => [
  { key: 'books',       label: t('library.tabs.books'),       icon: 'menu_book',            count: stats.value.totalBooks || null },
  { key: 'collections', label: t('library.tabs.collections'), icon: 'collections_bookmark', count: stats.value.collections || null },
  { key: 'wishlist',    label: t('library.tabs.wishlist'),    icon: 'bookmark',             count: stats.value.wished || null },
])
const onBooksTab = computed(() => activeTab.value === 'books')

/* ── Sharing subtabs: borrowing (books I hold) vs lending (books I own) ──
   Each side carries its whole lifecycle — requests in flight, the active loan,
   then the settled ones — so a loan is found by asking "which side am I on?"
   rather than "which of four lists holds it?". This axis is the one the loan
   history was already split along, so its old inner toggle is now the subtab. */
const sharingSide = ref('borrowing')

const sharingSides = computed(() => [
  // The arrows read as direction of travel: a borrowed book came to you, a lent
  // one went out.
  { key: 'borrowing', label: t('library.tabs.borrowing'), icon: 'call_received', badge: borrowingCount.value || null },
  { key: 'lending',   label: t('library.tabs.lending'),   icon: 'call_made',     badge: lendingCount.value || null },
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

/* ── Data loading: the Books shelf + profile up front, others lazily ───── */
const loaded = ref({ collections: false, following: false, wishlist: false })

// The two lazy shelves load on first entry and are then cached: nothing but
// this page writes to them, so unlike a loan list they can't go stale behind
// your back. The Books shelf is already fetched on mount.
function loadShelf(which) {
  if (which === 'collections' && !loaded.value.collections) { loaded.value.collections = true; collections.fetchMine() }
  if (which === 'wishlist' && !loaded.value.wishlist) { loaded.value.wishlist = true; store.fetchWishlist() }
}

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
  if (tab === 'books') loadShelf(shelf.value)
  if (tab === 'following' && !loaded.value.following) { loaded.value.following = true; subscriptions.fetchFollowing() }
  if (tab === 'sharing') loadSharingSide(sharingSide.value)
})

// Switching shelf while the Books tab is open loads the one being revealed.
watch(shelf, which => { if (onBooksTab.value) loadShelf(which) })

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

/* ── Mobile FAB ───────────────────────────────────────────────────────────
   The toolbar's add button is desktop-only, so on a phone this is the only way
   into a create flow — which means it has to follow the shelf you're looking
   at, collections included. */
const fabAction = computed(() => {
  if (!onBooksTab.value) return { key: 'book', label: t('library.addBookAria') }
  if (shelf.value === 'collections') return { key: 'collection', label: t('library.addCollectionAria') }
  if (shelf.value === 'wishlist') return { key: 'wish', label: t('wishlist.addBook') }
  return { key: 'book', label: t('library.addBookAria') }
})
function onFab() {
  if (fabAction.value.key === 'collection') openCreateCollection()
  else openCreate({ wished: fabAction.value.key === 'wish' })
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

        <!-- The primary action, and nothing else. The three-figure stat block
             that used to sit under it (total / shared / loaned) is gone: the
             numbers restated what the shelves themselves show, and the panel
             they lived in cost the top of every visit a 232px column. -->
        <button class="btn-add-book" @click="openCreate">
          <span class="material-symbols-outlined">add</span>
          {{ t('library.addNewBook') }}
        </button>
      </section>

      <!-- ── Library content ───────────────────────────────────────────── -->
      <section class="library-content">

        <!-- Tabs — a static three-up strip. At five tabs this had to scroll
             (565px of tabs in 342px at 390 wide) and carried edge shadows to
             say so; three share the width evenly and nothing is off-screen, so
             the scroller, its hidden scrollbar and the shadow hack are gone.
             The active marker is one span rather than a per-button border, so
             it slides between tabs — and since the tabs are equal thirds it can
             be positioned from the index alone, with no measuring. -->
        <div
          class="tab-nav"
          role="tablist"
          :aria-label="t('library.tabsLabel')"
          :style="{ '--tab-count': tabs.length, '--tab-index': activeTabIndex }"
          @keydown="onTabKeydown"
        >
          <button
            v-for="tab in tabs"
            :key="tab.key"
            class="tab-btn"
            :class="{ 'tab-btn--active': activeTab === tab.key }"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.key"
            :tabindex="activeTab === tab.key ? 0 : -1"
            @click="activeTab = tab.key"
          >
            <span class="material-symbols-outlined tab-btn__icon">{{ tab.icon }}</span>
            <span class="tab-btn__label">{{ tab.label }}</span>
          </button>
          <span class="tab-nav__indicator" aria-hidden="true" />
        </div>

        <!-- ── Books tab: the three shelves of your own catalogue ────────── -->
        <div v-if="onBooksTab" role="tabpanel">
          <SubTabNav v-model="shelf" :items="shelves" :aria-label="t('library.shelvesLabel')" />

          <!-- Books shelf -->
          <BookShelfPanel
            v-if="shelf === 'books'"
            :books="collection"
            :meta="collectionMeta"
            :loading="loading.collection"
            :query="collectionQuery"
            :filtered="!!collectionQuery"
            :search-placeholder="t('library.searchPlaceholder')"
            :no-matches="t('library.noMatches', { query: collectionQuery })"
            :add-label="t('library.addBook')"
            add-icon="add_circle"
            :add-title="t('library.addCard.bookTitle')"
            :add-hint="t('library.addCard.bookHint')"
            @search="store.setCollectionSearch"
            @open="openEdit"
            @toggle-read="onToggleRead"
            @page="store.fetchCollection"
            @add="openCreate()"
          >
            <!-- Whole-shelf actions: only the owned shelf can be shared or
                 round-tripped through CSV. -->
            <template #actions>
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
            </template>
          </BookShelfPanel>

          <!-- Collections shelf (curated groups you own) -->
          <template v-else-if="shelf === 'collections'">
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
          </template>

          <!-- Wish List shelf (books you want, not ones you hold) -->
          <BookShelfPanel
            v-else
            :books="wishlist"
            :meta="wishlistMeta"
            :loading="loading.wishlist"
            :query="wishlistQuery"
            :filtered="!!wishlistQuery || !!wishlistPriority"
            wish
            :search-placeholder="t('wishlist.searchPlaceholder')"
            :no-matches="t('wishlist.noMatches')"
            :add-label="t('wishlist.addBook')"
            add-icon="bookmark_add"
            :add-title="t('wishlist.addCard.title')"
            :add-hint="t('wishlist.addCard.hint')"
            @search="store.setWishlistSearch"
            @open="openEdit"
            @toggle-read="onToggleRead"
            @page="store.fetchWishlist"
            @add="openCreate({ wished: true })"
          >
            <!-- Narrowing controls: priority as pills rather than a select,
                 because there are only four choices and each carries its own
                 colour; the sort is a plain two-option select. -->
            <template #filters>
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
            </template>
          </BookShelfPanel>
        </div>

        <!-- Sharing tab — every loan you are part of, in one list per side -->
        <div v-else-if="activeTab === 'sharing'" role="tabpanel">
          <!-- Borrowing (books I hold) vs Lending (books I own) -->
          <SubTabNav v-model="sharingSide" :items="sharingSides" :aria-label="t('library.sidesLabel')" />

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

    <!-- Mobile FAB (hidden on desktop) — follows the shelf you're on, so it
         creates a collection on the Collections shelf and a wanted book on the
         Wish List. -->
    <button class="fab" :aria-label="fabAction.label" @click="onFab">
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
  /* No trailing margin: with the stat panel gone the bio is the last thing in
     the header, so its bottom margin and the header's own bottom padding were
     stacking under it — 84px of nothing above the tabs, on top of the section
     gap that is already there to separate them. */
  margin: 0;
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
/* Desktop: a plain button at the top of the header row, no longer the header
   of a framed panel. `flex-shrink: 0` keeps it off the identity column's
   wrapping — the label is one line and must stay one line. */
@media (min-width: 768px) {
  .btn-add-book { flex-shrink: 0; align-self: flex-start; }
}

/* ── Library content section ─────────────────────────────────────────── */
.library-content { display: flex; flex-direction: column; gap: var(--space-md); }

/* ── Tab nav ──────────────────────────────────────────────────────────────
   A static, equal-thirds strip. Five tabs could not fit a phone — 565px of
   tabs in 342px at 390 wide — so this was a hidden-scrollbar scroller with
   edge shadows standing in for the missing affordance. Three tabs fit, so the
   whole apparatus is gone: no scroll container, no `v-hscroll`, no gradients
   masquerading as a cue. Nothing here is ever off-screen. */
.tab-nav {
  position: relative;
  display: flex;
  border-bottom: 1px solid var(--color-surface-container-highest);
  /* One pitch for the whole strip: the tabs are laid out on it and the marker
     is measured from it, so the two can't disagree. Phones divide the width
     evenly; from 768px the tabs take a fixed width and sit left, because an
     equal third of a 1200px page put 400px of marker under a 60px label. */
  --tab-w: calc(100% / var(--tab-count));
}
@media (min-width: 768px) {
  .tab-nav { --tab-w: 168px; }
}

/* The active marker is one element, not a border on the selected button, so it
   travels between tabs instead of blinking from one to the next. Every tab is
   the same width, so its position follows from the index alone — no measuring,
   no observer, and it stays correct through a locale switch that changes every
   label's width. */
.tab-nav__indicator {
  position: absolute;
  left: 0;
  bottom: -1px; /* sit on the strip's own border, not above it */
  height: 2px;
  width: var(--tab-w);
  /* 100% of the marker is one tab pitch, which is what makes this exact. */
  transform: translateX(calc(var(--tab-index) * 100%));
  background: var(--color-accent);
  border-radius: var(--radius-full) var(--radius-full) 0 0;
  transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-xs);
  /* Every tab is exactly one pitch wide — never its content width — so a long
     label in one locale can't win space from its neighbours and knock the
     marker out of alignment with its tab. */
  flex: 0 0 var(--tab-w);
  max-width: var(--tab-w);
  min-width: 0;
  padding: var(--space-sm) var(--space-xs);
  font-size: var(--text-label-md);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  color: var(--color-secondary);
  transition: color 0.2s;
}
.tab-btn:hover { color: var(--color-on-background); }
.tab-btn--active { color: var(--color-primary); font-weight: 600; }
.tab-btn__label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* The icon is the fast half of the pair — it's what the eye returns to once the
   labels have been read once — so it carries the state change: it fills and
   grows a touch on selection, while the label only shifts colour. */
.tab-btn__icon {
  font-size: 20px;
  transition: font-variation-settings 0.2s, transform 0.2s;
}
.tab-btn--active .tab-btn__icon {
  font-variation-settings: 'FILL' 1, 'wght' 500;
  transform: scale(1.08);
}

@media (max-width: 767px) {
  /* Icon over label: three columns of ~114px at 390 wide are comfortable
     stacked and cramped side by side. */
  .tab-btn {
    flex-direction: column;
    gap: 2px;
    padding: var(--space-xs) 4px var(--space-sm);
  }
  .tab-btn__icon { font-size: 22px; }
}

/* Motion is decoration here — the colour and fill changes already say which tab
   is active — so it goes entirely when asked. */
@media (prefers-reduced-motion: reduce) {
  .tab-nav__indicator,
  .tab-btn,
  .tab-btn__icon { transition: none; }
  .tab-btn--active .tab-btn__icon { transform: none; }
}

/* ── Wish-list sort + priority filter ─────────────────────────────────── */
/* Narrow enough not to crowd the toolbar; the two labels are short. */
.wishlist-sort { min-width: 168px; }


/* .filter-row / .filter-pill live in tokens.css — the admin panel's member
   status filter is a second caller outside this scope id. */

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
