<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BookCard from '@/components/library/BookCard.vue'
import RequestCard from '@/components/library/RequestCard.vue'

const auth = useAuthStore()
const user = computed(() => auth.user)

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const activeTab = ref('collection')

const tabs = [
  { key: 'collection', label: 'Collection' },
  { key: 'lending',    label: 'Lending' },
  { key: 'requests',   label: 'Requests', badge: 3 },
  { key: 'history',    label: 'History' },
]

/* ── Mock data (replace with API calls) ──────────────────────────────── */
const books = ref([
  { id: 1, title: 'The Great Gatsby',        author: 'F. Scott Fitzgerald', categories: [{ id: 1, name: 'Fiction' }, { id: 2, name: 'Classic' }],   coverPath: null },
  { id: 2, title: 'Dune',                    author: 'Frank Herbert',        categories: [{ id: 3, name: 'Sci-Fi' }],                                 coverPath: null },
  { id: 3, title: 'Pride and Prejudice',     author: 'Jane Austen',          categories: [{ id: 4, name: 'Romance' }, { id: 2, name: 'Classic' }],   coverPath: null },
  { id: 4, title: 'Jane Eyre',               author: 'Charlotte Brontë',     categories: [{ id: 2, name: 'Classic' }],                                coverPath: null },
  { id: 5, title: 'Meditations',             author: 'Marcus Aurelius',      categories: [{ id: 5, name: 'Philosophy' }],                             coverPath: null },
  { id: 6, title: 'The Secret History',      author: 'Donna Tartt',          categories: [{ id: 1, name: 'Fiction' }],                                coverPath: null },
  { id: 7, title: 'The Secret Life of Trees',author: 'Peter Wohlleben',      categories: [{ id: 6, name: 'Nature' }, { id: 7, name: 'Non-Fiction' }], coverPath: null },
])

const requests = ref([
  { id: 1, requester: { fullName: 'Julian Black',    avatarUrl: null }, book: { title: 'The Secret History',                  author: 'Donna Tartt',       coverPath: null }, requestedAt: 'today' },
  { id: 2, requester: { fullName: 'Eleanor Vance',   avatarUrl: null }, book: { title: 'The Haunting of Hill House',           author: 'Shirley Jackson',   coverPath: null }, requestedAt: '2 days ago' },
  { id: 3, requester: { fullName: 'Arthur Dent',     avatarUrl: null }, book: { title: "The Hitchhiker's Guide to the Galaxy", author: 'Douglas Adams',     coverPath: null }, requestedAt: '3 days ago' },
])

const stats = computed(() => [
  { label: 'Total Books', value: books.value.length },
  { label: 'Shared',      value: 28 },
  { label: 'Loaned',      value: 0 },
])

/* ── Actions ─────────────────────────────────────────────────────────── */
function handleApprove(id) {
  requests.value = requests.value.filter(r => r.id !== id)
}
function handleDecline(id) {
  requests.value = requests.value.filter(r => r.id !== id)
}

function handleAddBook() {
  /* TODO: open ManageBookModal */
  console.info('Add book modal — coming soon')
}
</script>

<template>
  <AppLayout>
    <div class="library-page">

      <!-- ── Profile header ────────────────────────────────────────────── -->
      <section class="profile-header">
        <div class="profile-header__info">
          <BaseAvatar
            :src="user?.avatarUrl"
            :name="user?.fullName"
            size="xl"
            class="profile-header__avatar"
          />
          <div>
            <h1 class="profile-header__name">{{ user?.fullName }}</h1>
            <p class="profile-header__bio">Curating a collection of rare fiction and 20th-century poetry.</p>
            <div class="profile-header__stats">
              <div v-for="stat in stats" :key="stat.label" class="stat">
                <span class="stat__value">{{ stat.value }}</span>
                <span class="stat__label">{{ stat.label }}</span>
              </div>
            </div>
          </div>
        </div>

        <button class="btn-add-book" @click="handleAddBook">
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
        <div v-if="activeTab === 'collection'" class="book-grid" role="tabpanel">
          <BookCard
            v-for="book in books"
            :key="book.id"
            :book="book"
          />
          <!-- "Add new book" placeholder card -->
          <div class="add-book-card" @click="handleAddBook" role="button" tabindex="0">
            <span class="material-symbols-outlined add-book-card__icon">add_circle</span>
            <h3 class="add-book-card__title">Catalog a New Book</h3>
            <p class="add-book-card__hint">Scan barcode or enter manually.</p>
          </div>
        </div>

        <!-- Lending tab -->
        <div v-else-if="activeTab === 'lending'" class="empty-state" role="tabpanel">
          <span class="material-symbols-outlined empty-state__icon">local_library</span>
          <p class="empty-state__text">No books currently lent out.</p>
        </div>

        <!-- Requests tab -->
        <div v-else-if="activeTab === 'requests'" class="request-grid" role="tabpanel">
          <p v-if="requests.length === 0" class="empty-requests">All caught up — no pending requests.</p>
          <RequestCard
            v-for="req in requests"
            :key="req.id"
            :request="req"
            @approve="handleApprove"
            @decline="handleDecline"
          />
        </div>

        <!-- History tab -->
        <div v-else class="empty-state" role="tabpanel">
          <span class="material-symbols-outlined empty-state__icon">history</span>
          <p class="empty-state__text">Your lending history will appear here.</p>
        </div>

      </section>
    </div>

    <!-- Mobile FAB (hidden on desktop) -->
    <button
      class="fab"
      aria-label="Add new book"
      @click="handleAddBook"
    >
      <span class="material-symbols-outlined">add</span>
    </button>
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
