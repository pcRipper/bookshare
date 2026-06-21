<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useLibraryStore } from '@/stores/library'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import BookCard from '@/components/library/BookCard.vue'
import BorrowingCard from '@/components/library/BorrowingCard.vue'
import RequestCard from '@/components/library/RequestCard.vue'
import LoanHistoryCard from '@/components/library/LoanHistoryCard.vue'
import ManageBookModal from '@/components/library/ManageBookModal.vue'

const store = useLibraryStore()
const toast = useToastStore()
const { profile, stats, collection, lending, requests, history, borrowing, borrowingHistory, loading } = storeToRefs(store)

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const activeTab = ref('collection')

const tabs = computed(() => [
  { key: 'collection', label: 'Collection' },
  { key: 'borrowing',  label: 'Borrowing', badge: borrowing.value.length || null },
  { key: 'lending',    label: 'Lending' },
  { key: 'requests',   label: 'Requests', badge: requests.value.length || null },
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

/* ── Data loading: collection + profile up front, others lazily ───────── */
const loaded = ref({ borrowing: false, lending: false, requests: false })

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
  if (tab === 'requests' && !loaded.value.requests) { loaded.value.requests = true; store.fetchRequests() }
  if (tab === 'history') loadHistorySide(historySide.value)
})

// Switching the lending/borrowing toggle (while on the History tab) lazy-loads it.
watch(historySide, side => { if (activeTab.value === 'history') loadHistorySide(side) })

// Badges should reflect reality even before their tabs are opened.
onMounted(() => store.fetchRequests().then(() => { loaded.value.requests = true }))
onMounted(() => store.fetchBorrowing().then(() => { loaded.value.borrowing = true }))

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
async function handleDecline(id) {
  processing[id] = 'decline'
  try {
    await store.declineRequest(id)
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
            <div>
              <h1 class="profile-header__name">{{ profile.fullName }}</h1>
              <p v-if="profile.bio" class="profile-header__bio">{{ profile.bio }}</p>
              <p v-else class="profile-header__bio profile-header__bio--muted">Add a short bio in settings.</p>
              <div class="profile-header__stats">
                <div v-for="stat in statCards" :key="stat.label" class="stat">
                  <span class="stat__value">{{ stat.value }}</span>
                  <span class="stat__label">{{ stat.label }}</span>
                </div>
              </div>
            </div>
          </template>

          <!-- Skeleton while the profile loads -->
          <template v-else>
            <BaseSkeleton width="96px" height="96px" circle />
            <div class="profile-header__skeleton">
              <BaseSkeleton width="180px" height="28px" />
              <BaseSkeleton width="260px" height="14px" />
              <div class="profile-header__stats">
                <BaseSkeleton v-for="n in 3" :key="n" width="64px" height="44px" />
              </div>
            </div>
          </template>
        </div>

        <button class="btn-add-book" @click="openCreate">
          <span class="material-symbols-outlined">add</span>
          Add New Book
        </button>
      </section>

      <!-- ── Library content ───────────────────────────────────────────── -->
      <section class="library-content">

        <!-- Tabs -->
        <div class="tab-nav" role="tablist">
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
          <BookGridSkeleton v-if="loading.collection && !collection.length" :count="8" />
          <div v-else class="book-grid">
            <BookCard
              v-for="book in collection"
              :key="book.id"
              :book="book"
              @click="openEdit"
            />
            <!-- "Add new book" placeholder card -->
            <div class="add-book-card" @click="openCreate" role="button" tabindex="0">
              <span class="material-symbols-outlined add-book-card__icon">add_circle</span>
              <h3 class="add-book-card__title">Catalog a New Book</h3>
              <p class="add-book-card__hint">Add a title to your collection.</p>
            </div>
          </div>
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

        <!-- Requests tab -->
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
          <p v-else-if="requests.length === 0" class="empty-requests">All caught up — no pending requests.</p>
          <div v-else class="request-grid">
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
          <ul v-else-if="historyItems.length" class="history-list">
            <LoanHistoryCard
              v-for="item in historyItems"
              :key="item.id"
              :request="item"
              :perspective="historySide"
            />
          </ul>
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
  </AppLayout>
</template>

<style scoped>
/* ── Page wrapper ─────────────────────────────────────────────────────── */
.library-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
}
@media (max-width: 767px) {
  .library-page {
    padding: var(--space-lg) var(--space-gutter) var(--space-xl);
    gap: var(--space-md);
  }
}

/* ── Profile header ───────────────────────────────────────────────────── */
.profile-header {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  padding-bottom: var(--space-lg);
  border-bottom: 1px solid var(--color-surface-container-highest);
}
@media (min-width: 768px) {
  .profile-header {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.profile-header__info {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}
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

.profile-header__stats {
  display: flex;
  gap: var(--space-md);
}
@media (max-width: 767px) {
  /* On mobile, stats become a 3-col bordered grid */
  .profile-header__stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-xs);
    width: 100%;
  }
  .stat {
    background: var(--color-surface-container-low);
    border: 1px solid var(--color-outline-variant);
    border-radius: var(--radius-default);
    padding: var(--space-sm);
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
}

.stat {
  display: flex;
  flex-direction: column;
}

.stat__value {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  font-weight: 600;
  color: var(--color-primary);
}

.stat__label {
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--color-secondary);
  text-transform: uppercase;
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
  align-self: flex-start;
}
@media (min-width: 768px) { .btn-add-book { align-self: auto; } }
.btn-add-book:hover { background: var(--color-primary-container); }

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
  border-bottom-color: var(--color-primary);
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
.profile-header__skeleton .profile-header__stats { margin-top: var(--space-xs); }

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

/* Muted placeholder bio */
.profile-header__bio--muted { font-style: italic; opacity: 0.7; }

/* ── Mobile FAB ───────────────────────────────────────────────────────── */
.fab {
  position: fixed;
  bottom: calc(64px + var(--space-md)); /* above bottom nav */
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
