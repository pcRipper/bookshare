<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useLibraryStore } from '@/stores/library'
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
import BorrowingCard from '@/components/library/BorrowingCard.vue'
import PendingRequestCard from '@/components/library/PendingRequestCard.vue'
import RequestCard from '@/components/library/RequestCard.vue'
import LoanHistoryCard from '@/components/library/LoanHistoryCard.vue'
import ManageBookModal from '@/components/library/ManageBookModal.vue'
import ImportBooksModal from '@/components/library/ImportBooksModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SearchInput from '@/components/ui/SearchInput.vue'

const store = useLibraryStore()
const subscriptions = useSubscriptionsStore()
const toast = useToastStore()
const { profile, stats, collection, collectionMeta, collectionQuery, lending, requests, history, historyMeta, borrowing, pendingBorrowing, borrowingHistory, borrowingHistoryMeta, loading } = storeToRefs(store)
const { following, followingMeta, loadingFollowing } = storeToRefs(subscriptions)

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const activeTab = ref('collection')

const tabs = computed(() => [
  { key: 'collection', label: 'Collection' },
  { key: 'borrowing',  label: 'Borrowing', badge: borrowing.value.length || null },
  { key: 'lending',    label: 'Lending' },
  { key: 'requests',   label: 'Requests', badge: (requests.value.length + pendingBorrowing.value.length) || null },
  { key: 'following',  label: 'Following', badge: followingMeta.value.total || null },
  { key: 'history',    label: 'History' },
])

const statCards = computed(() => [
  { label: 'Total Books', value: stats.value.totalBooks },
  { label: 'Shared',      value: stats.value.shared },
  { label: 'Loaned',      value: stats.value.loaned },
])

/* ── History sub-views: lending (as owner) vs borrowing (as borrower) ──── */
const historySide = ref('lending')

const historyLoading = computed(() =>
  historySide.value === 'lending' ? loading.value.history : loading.value.borrowingHistory,
)
const historyItems = computed(() =>
  historySide.value === 'lending' ? history.value : borrowingHistory.value,
)
// Pagination metadata for whichever history side is showing.
const historyPageMeta = computed(() =>
  historySide.value === 'lending' ? historyMeta.value : borrowingHistoryMeta.value,
)
function onHistoryPage(page) {
  if (historySide.value === 'lending') store.fetchHistory(page)
  else store.fetchBorrowingHistory(page)
}

/* ── Data loading: collection + profile up front, others lazily ───────── */
const loaded = ref({ borrowing: false, lending: false, requests: false, following: false })

// History shows in-progress loans too, whose state changes as the owner/borrower
// acts in other tabs — so refetch the visible side each time it's viewed.
function loadHistorySide(side) {
  if (side === 'lending') store.fetchHistory()
  if (side === 'borrowing') store.fetchBorrowingHistory()
}

onMounted(() => {
  store.fetchMe()
  store.fetchCollection()
  store.fetchCategories()
})

watch(activeTab, tab => {
  if (tab === 'borrowing' && !loaded.value.borrowing) { loaded.value.borrowing = true; store.fetchBorrowing() }
  if (tab === 'lending'  && !loaded.value.lending)  { loaded.value.lending  = true; store.fetchLending() }
  if (tab === 'requests' && !loaded.value.requests) { loaded.value.requests = true; store.fetchRequests(); store.fetchPendingBorrowing() }
  if (tab === 'following' && !loaded.value.following) { loaded.value.following = true; subscriptions.fetchFollowing() }
  if (tab === 'history') loadHistorySide(historySide.value)
})

// Switching the lending/borrowing toggle (while on the History tab) lazy-loads it.
watch(historySide, side => { if (activeTab.value === 'history') loadHistorySide(side) })

// Badges should reflect reality even before their tabs are opened.
onMounted(() => store.fetchRequests().then(() => { loaded.value.requests = true }))
onMounted(() => store.fetchBorrowing().then(() => { loaded.value.borrowing = true }))
onMounted(() => store.fetchPendingBorrowing())

/* ── Request actions (owner inbox) ────────────────────────────────────── */
// Per-request in-flight action ('approve' | 'decline' | 'confirm-return').
const processing = reactive({})

async function handleApprove(id, dueDate) {
  processing[id] = 'approve'
  try {
    await store.approveRequest(id, dueDate)
    // The book is now an active loan — mark Lending loaded so it refetches fresh.
    loaded.value.lending = true
  } finally {
    delete processing[id]
  }
}
async function handleDecline(id, message = null) {
  processing[id] = 'decline'
  try {
    await store.declineRequest(id, message)
  } finally {
    delete processing[id]
  }
}
async function handleConfirmReturn(id) {
  processing[id] = 'confirm-return'
  try {
    await store.confirmReturn(id)
    // The book returned to the collection and the loan closed.
    loaded.value.lending = true
  } finally {
    delete processing[id]
  }
}

/* ── Borrowing actions (borrower side) ────────────────────────────────── */
const returning = reactive(new Set())
async function handleReturn(id) {
  if (returning.has(id)) return
  returning.add(id)
  try {
    await store.returnBook(id)
  } finally {
    returning.delete(id)
  }
}

// Withdraw a still-pending borrow request.
const cancelling = reactive(new Set())
async function handleCancel(id) {
  if (cancelling.has(id)) return
  cancelling.add(id)
  try {
    await store.cancelRequest(id)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Could not cancel this request.'))
  } finally {
    cancelling.delete(id)
  }
}

/* ── Following (unfollow from the management list) ────────────────────── */
const unfollowing = reactive(new Set())
async function handleUnfollow(userId) {
  if (unfollowing.has(userId)) return
  unfollowing.add(userId)
  try {
    await subscriptions.unsubscribe(userId)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Could not unfollow this reader.'))
  } finally {
    unfollowing.delete(userId)
  }
}

/* ── Manage Book modal ───────────────────────────────────────────────── */
const modalOpen = ref(false)
const modalBusy = ref(false)
const editingBook = ref(null)

function openCreate() {
  editingBook.value = null
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
    if (loaded.value.lending) await store.fetchLending()
    modalOpen.value = false
  } catch (e) {
    // Surface the failure as a toast instead of letting it bubble to the
    // app-wide error boundary (which would replace the whole page).
    toast.error(apiErrorMessage(e, 'Could not save the book.'))
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
    toast.error(apiErrorMessage(e, 'Could not delete the book.'))
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
    toast.error(apiErrorMessage(e, 'Could not export your books.'))
  } finally {
    exporting.value = false
  }
}

function onImported() {
  // A replace import may empty Lending; refresh it next time it's viewed.
  loaded.value.lending = false
  toast.success('Your collection has been updated.')
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
              <p v-else class="profile-header__bio profile-header__bio--muted">Add a short bio in settings.</p>
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
            Add New Book
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
            <SearchInput
              class="collection-toolbar__search"
              placeholder="Search by title, author or ISBN"
              :loading="loading.collection"
              @search="store.setCollectionSearch"
            />
            <div class="collection-toolbar__actions">
              <button class="toolbar-btn" type="button" @click="importOpen = true">
                <span class="material-symbols-outlined">upload</span>
                Import
              </button>
              <button class="toolbar-btn" type="button" :disabled="exporting || !collection.length" @click="onExport">
                <BaseSpinner v-if="exporting" size="sm" />
                <span v-else class="material-symbols-outlined">download</span>
                Export
              </button>
            </div>
          </div>

          <BookGridSkeleton v-if="loading.collection && !collection.length" :count="8" class="collection-skeleton" />
          <!-- No matches for an active search -->
          <div v-else-if="collectionQuery && !collection.length" class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">search_off</span>
            <p class="empty-state__text">No books match “{{ collectionQuery }}”.</p>
          </div>
          <div v-else class="book-grid">
            <!-- "Add new book" placeholder card, leading the grid (first page, and not while searching) -->
            <div v-if="collectionMeta.page === 1 && !collectionQuery" class="add-book-card" @click="openCreate" role="button" tabindex="0">
              <span class="material-symbols-outlined add-book-card__icon">add_circle</span>
              <h3 class="add-book-card__title">Catalog a New Book</h3>
              <p class="add-book-card__hint">Add a title to your collection.</p>
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

        <!-- Borrowing tab (books I'm borrowing from others) -->
        <div v-else-if="activeTab === 'borrowing'" role="tabpanel">
          <BookGridSkeleton v-if="loading.borrowing && !borrowing.length" :count="4" />
          <div v-else-if="borrowing.length" class="book-grid">
            <BorrowingCard
              v-for="loan in borrowing"
              :key="loan.id"
              :loan="loan"
              :pending="returning.has(loan.id)"
              @return="handleReturn"
            />
          </div>
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">auto_stories</span>
            <p class="empty-state__text">You're not borrowing any books right now.</p>
            <RouterLink to="/discover" class="empty-state__link">Discover books to borrow</RouterLink>
          </div>
        </div>

        <!-- Lending tab -->
        <div v-else-if="activeTab === 'lending'" role="tabpanel">
          <BookGridSkeleton v-if="loading.lending" :count="4" />
          <div v-else-if="lending.length" class="book-grid">
            <BookCard v-for="book in lending" :key="book.id" :book="book" @click="openEdit" />
          </div>
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">local_library</span>
            <p class="empty-state__text">No books currently lent out.</p>
          </div>
        </div>

        <!-- Requests tab — both directions: incoming (owner inbox) + outgoing (borrower) -->
        <div v-else-if="activeTab === 'requests'" role="tabpanel">
          <div v-if="loading.requests" class="request-grid">
            <div v-for="n in 2" :key="n" class="request-skeleton">
              <div class="request-skeleton__row">
                <BaseSkeleton width="40px" height="40px" circle />
                <div class="request-skeleton__lines">
                  <BaseSkeleton width="50%" height="14px" />
                  <BaseSkeleton width="30%" height="12px" />
                </div>
              </div>
              <BaseSkeleton width="100%" height="80px" radius="var(--radius-default)" />
              <div class="request-skeleton__actions">
                <BaseSkeleton width="100%" height="40px" radius="var(--radius-default)" />
                <BaseSkeleton width="100%" height="40px" radius="var(--radius-default)" />
              </div>
            </div>
          </div>
          <template v-else-if="requests.length || pendingBorrowing.length">
            <!-- Incoming: other readers asking to borrow your books. -->
            <section v-if="requests.length" class="tab-section">
              <h3 class="tab-section__title">Requests for your books</h3>
              <div class="request-grid">
                <RequestCard
                  v-for="req in requests"
                  :key="req.id"
                  :request="req"
                  :pending="processing[req.id] || null"
                  @approve="handleApprove"
                  @decline="handleDecline"
                  @confirm-return="handleConfirmReturn"
                />
              </div>
            </section>

            <!-- Outgoing: your own requests still awaiting the owner's decision. -->
            <section v-if="pendingBorrowing.length" class="tab-section">
              <h3 class="tab-section__title">Your borrow requests</h3>
              <div class="book-grid">
                <PendingRequestCard
                  v-for="req in pendingBorrowing"
                  :key="req.id"
                  :request="req"
                  :pending="cancelling.has(req.id)"
                  @cancel="handleCancel"
                />
              </div>
            </section>
          </template>
          <p v-else class="empty-requests">All caught up — no open requests.</p>
        </div>

        <!-- Following tab (people you subscribe to) -->
        <div v-else-if="activeTab === 'following'" role="tabpanel">
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
                <span v-else>Unfollow</span>
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
            <p class="empty-state__text">You're not following anyone yet.</p>
            <RouterLink to="/discover" class="empty-state__link">Discover readers to follow</RouterLink>
          </div>
        </div>

        <!-- History tab -->
        <div v-else role="tabpanel">
          <!-- Lending (as owner) vs Borrowing (as borrower) toggle -->
          <div class="history-toggle" role="tablist">
            <button
              class="history-toggle__btn"
              :class="{ 'history-toggle__btn--active': historySide === 'lending' }"
              role="tab"
              :aria-selected="historySide === 'lending'"
              @click="historySide = 'lending'"
            >Lent to others</button>
            <button
              class="history-toggle__btn"
              :class="{ 'history-toggle__btn--active': historySide === 'borrowing' }"
              role="tab"
              :aria-selected="historySide === 'borrowing'"
              @click="historySide = 'borrowing'"
            >Borrowed by me</button>
          </div>

          <ul v-if="historyLoading" class="history-list">
            <li v-for="n in 4" :key="n" class="history-card-skeleton">
              <div class="history-card-skeleton__head">
                <BaseSkeleton width="40px" height="40px" circle />
                <div class="request-skeleton__lines" style="flex: 1">
                  <BaseSkeleton width="60%" height="14px" />
                  <BaseSkeleton width="35%" height="12px" />
                </div>
                <BaseSkeleton width="72px" height="22px" radius="var(--radius-full)" />
              </div>
              <BaseSkeleton width="100%" height="72px" radius="var(--radius-default)" />
            </li>
          </ul>
          <template v-else-if="historyItems.length">
            <ul class="history-list">
              <LoanHistoryCard
                v-for="item in historyItems"
                :key="item.id"
                :request="item"
                :perspective="historySide"
              />
            </ul>
            <Pagination
              :page="historyPageMeta.page"
              :total-pages="historyPageMeta.totalPages"
              :disabled="historyLoading"
              @change="onHistoryPage"
            />
          </template>
          <div v-else class="empty-state">
            <span class="material-symbols-outlined empty-state__icon">history</span>
            <p v-if="historySide === 'lending'" class="empty-state__text">Your lending history will appear here.</p>
            <p v-else class="empty-state__text">Your borrowing history will appear here.</p>
          </div>
        </div>

      </section>
    </div>

    <!-- Mobile FAB (hidden on desktop) -->
    <button
      class="fab"
      aria-label="Add new book"
      @click="openCreate"
    >
      <span class="material-symbols-outlined">add</span>
    </button>

    <!-- Add / edit book modal -->
    <ManageBookModal
      :open="modalOpen"
      :book="editingBook"
      :busy="modalBusy"
      @save="onModalSave"
      @delete="onModalDelete"
      @close="modalOpen = false"
    />

    <!-- Import books modal -->
    <ImportBooksModal
      :open="importOpen"
      @imported="onImported"
      @close="importOpen = false"
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
  gap: var(--space-sm);
  flex-shrink: 0;
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

.tab-section + .tab-section { margin-top: var(--space-md); }
.tab-section__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  color: var(--color-on-background);
  margin: 0 0 var(--space-sm);
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

/* ── Request grid ─────────────────────────────────────────────────────── */
.request-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-md);
  padding-top: var(--space-sm);
}
@media (min-width: 600px) {
  .request-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 960px) {
  .request-grid { grid-template-columns: repeat(3, 1fr); }
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

.request-skeleton {
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
.request-skeleton__row { display: flex; align-items: center; gap: var(--space-sm); }
.request-skeleton__lines { display: flex; flex-direction: column; gap: var(--space-xs); flex: 1; }
.request-skeleton__actions { display: flex; gap: var(--space-sm); }
.request-skeleton__actions > * { flex: 1; }

/* ── History list ─────────────────────────────────────────────────────── */
/* Lending/borrowing segmented toggle */
.history-toggle {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  margin: var(--space-sm) 0;
  background: var(--color-surface-container-low);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
}
.history-toggle__btn {
  padding: 6px 16px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-secondary);
  white-space: nowrap;
  transition: background 0.2s, color 0.2s;
}
.history-toggle__btn:hover:not(.history-toggle__btn--active) { color: var(--color-on-background); }
.history-toggle__btn--active {
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 600;
}

.history-list {
  list-style: none;
  margin: 0;
  padding: var(--space-sm) 0 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

/* History loading skeleton */
.history-card-skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  padding: var(--space-md);
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-default);
}
.history-card-skeleton__head {
  display: flex;
  align-items: center;
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
