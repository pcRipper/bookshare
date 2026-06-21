<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useProfileStore } from '@/stores/profile'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'
import BorrowBookCard from '@/components/profile/BorrowBookCard.vue'
import EditProfileModal from '@/components/profile/EditProfileModal.vue'
import ManageBookModal from '@/components/library/ManageBookModal.vue'

const route = useRoute()
const store = useProfileStore()
const { profile, books, loading, error } = storeToRefs(store)

/* ── Tabs ─────────────────────────────────────────────────────────────── */
const activeTab = ref('available')

const availableBooks = computed(() => books.value.filter(b => b.status === 'own'))

const tabs = computed(() => [
  { key: 'available', label: 'Available to Borrow', count: availableBooks.value.length },
  { key: 'full',      label: 'Full Collection',     count: books.value.length },
])

const shownBooks = computed(() =>
  activeTab.value === 'available' ? availableBooks.value : books.value,
)

/* ── Stats ────────────────────────────────────────────────────────────── */
const statCards = computed(() => {
  const s = profile.value?.stats ?? {}
  return [
    { label: 'Books',     value: s.totalBooks ?? 0 },
    { label: 'Available', value: s.shared ?? 0 },
    { label: 'Lending',   value: s.loaned ?? 0 },
  ]
})

/* ── Derived category tags (most frequent across the collection) ──────── */
const topCategories = computed(() => {
  const map = new Map()
  for (const book of books.value) {
    for (const cat of book.categories ?? []) {
      const entry = map.get(cat.id) ?? { ...cat, count: 0 }
      entry.count += 1
      map.set(cat.id, entry)
    }
  }
  return [...map.values()].sort((a, b) => b.count - a.count)
})

const MOBILE_TAG_LIMIT = 3
const mobileTags = computed(() => topCategories.value.slice(0, MOBILE_TAG_LIMIT))
const extraTagCount = computed(() => Math.max(0, topCategories.value.length - MOBILE_TAG_LIMIT))

/* ── Loading ──────────────────────────────────────────────────────────── */
function load() {
  activeTab.value = 'available'
  store.fetchProfile(route.params.id)
}
onMounted(load)
watch(() => route.params.id, load)

function onRequest(bookId) {
  store.requestBorrow(bookId)
}

/* ── Own-profile editing (only when profile.isSelf) ───────────────────── */
const editProfileOpen = ref(false)

async function onProfileSave(payload) {
  await store.updateProfile(payload)
  editProfileOpen.value = false
}

/* ── Own-profile book management (reuses the library's Manage Book modal) ─ */
const bookModalOpen = ref(false)
const editingBook = ref(null)

function openAddBook() {
  editingBook.value = null
  bookModalOpen.value = true
}
function openEditBook(book) {
  editingBook.value = book
  bookModalOpen.value = true
}
async function onBookSave(payload) {
  if (editingBook.value) await store.updateBook(editingBook.value.id, payload)
  else await store.createBook(payload)
  bookModalOpen.value = false
}
async function onBookDelete(id) {
  await store.deleteBook(id)
  bookModalOpen.value = false
}
</script>

<template>
  <AppLayout>
    <div class="profile-page">

      <!-- Loading -->
      <div v-if="loading" class="profile-state">
        <span class="material-symbols-outlined profile-state__icon spin">progress_activity</span>
        <p>Loading profile…</p>
      </div>

      <!-- Not found / error -->
      <div v-else-if="error" class="profile-state">
        <span class="material-symbols-outlined profile-state__icon">{{ error === 'not-found' ? 'person_off' : 'error' }}</span>
        <p>{{ error === 'not-found' ? 'This reader could not be found.' : 'Something went wrong loading this profile.' }}</p>
        <RouterLink to="/discover" class="profile-state__link">Back to Discover</RouterLink>
      </div>

      <template v-else-if="profile">
        <!-- ── Profile header ──────────────────────────────────────────── -->
        <section class="profile-header">
          <BaseAvatar
            :src="profile.avatarUrl"
            :name="profile.fullName"
            size="xl"
            class="profile-header__avatar"
          />

          <div class="profile-header__main">
            <div class="profile-header__top">
              <h1 class="profile-header__name">{{ profile.fullName }}</h1>
              <button v-if="profile.isSelf" class="profile-header__edit" @click="editProfileOpen = true">
                <span class="material-symbols-outlined">edit</span> Edit Profile
              </button>
            </div>

            <p v-if="profile.location" class="profile-header__location">
              <span class="material-symbols-outlined">location_on</span> {{ profile.location }}
            </p>

            <p v-if="profile.bio" class="profile-header__bio">{{ profile.bio }}</p>
            <p v-else class="profile-header__bio profile-header__bio--muted">No bio yet.</p>

            <!-- Tags (desktop: full list + book count chip) -->
            <div class="profile-header__tags profile-header__tags--desktop">
              <CategoryTag
                v-for="cat in topCategories"
                :key="cat.id"
                :label="cat.name"
                :color="cat.colorHex"
              />
              <span class="count-chip">{{ profile.stats.totalBooks }} Books</span>
            </div>
          </div>
        </section>

        <!-- Stat bar -->
        <section class="profile-stats">
          <div v-for="stat in statCards" :key="stat.label" class="stat">
            <span class="stat__value">{{ stat.value }}</span>
            <span class="stat__label">{{ stat.label }}</span>
          </div>
        </section>

        <!-- Tags (mobile: top 3 + overflow) -->
        <div class="profile-header__tags profile-header__tags--mobile">
          <CategoryTag
            v-for="cat in mobileTags"
            :key="cat.id"
            :label="cat.name"
            :color="cat.colorHex"
          />
          <span v-if="extraTagCount" class="count-chip">+{{ extraTagCount }} more</span>
        </div>

        <!-- ── Tabs ───────────────────────────────────────────────────── -->
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
            <span class="tab-count">{{ tab.count }}</span>
          </button>
        </div>

        <!-- ── Book grid ──────────────────────────────────────────────── -->
        <div v-if="shownBooks.length || profile.isSelf" class="book-grid" role="tabpanel">
          <BorrowBookCard
            v-for="book in shownBooks"
            :key="book.id"
            :book="book"
            :is-self="profile.isSelf"
            @request="onRequest"
            @edit="openEditBook"
          />
          <!-- Add-book placeholder, own profile only -->
          <div
            v-if="profile.isSelf"
            class="add-book-card"
            role="button"
            tabindex="0"
            @click="openAddBook"
            @keydown.enter="openAddBook"
          >
            <span class="material-symbols-outlined add-book-card__icon">add_circle</span>
            <h3 class="add-book-card__title">Add a Book</h3>
            <p class="add-book-card__hint">Catalog a new title.</p>
          </div>
        </div>
        <div v-else class="empty-state">
          <span class="material-symbols-outlined empty-state__icon">auto_stories</span>
          <p>{{ activeTab === 'available' ? 'No books available to borrow right now.' : 'This collection is empty.' }}</p>
        </div>
      </template>
    </div>

    <!-- Own-profile editors -->
    <EditProfileModal
      :open="editProfileOpen"
      :profile="profile"
      @save="onProfileSave"
      @close="editProfileOpen = false"
    />
    <ManageBookModal
      :open="bookModalOpen"
      :book="editingBook"
      @save="onBookSave"
      @delete="onBookDelete"
      @close="bookModalOpen = false"
    />
  </AppLayout>
</template>

<style scoped>
.profile-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}
@media (max-width: 767px) {
  .profile-page { padding: var(--space-lg) var(--space-gutter) var(--space-xl); }
}

/* ── States ───────────────────────────────────────────────────────────── */
.profile-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  padding: var(--space-xl) 0;
  color: var(--color-on-surface-variant);
  text-align: center;
}
.profile-state__icon { font-size: 48px; opacity: 0.6; }
.profile-state__link { color: var(--color-primary); font-weight: 500; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Header ───────────────────────────────────────────────────────────── */
.profile-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-md);
  padding-bottom: var(--space-md);
}
@media (min-width: 768px) {
  .profile-header {
    flex-direction: row;
    align-items: center;
    text-align: left;
    gap: var(--space-lg);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-surface-container-highest);
  }
}

.profile-header__avatar { flex-shrink: 0; }
/* Larger avatar on desktop to match the reference proportions. The base size
   is set via inline style on BaseAvatar's root, so override needs !important. */
@media (min-width: 768px) {
  .profile-header__avatar { width: 128px !important; height: 128px !important; }
}

.profile-header__main {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
  flex: 1;
  align-items: center;
}
@media (min-width: 768px) {
  .profile-header__main { align-items: flex-start; gap: var(--space-base); }
}

.profile-header__top {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  flex-wrap: wrap;
  justify-content: center;
}

.profile-header__name {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-surface);
  margin: 0;
}
@media (min-width: 768px) {
  .profile-header__name {
    font-size: var(--text-headline-xl);
    line-height: var(--lh-headline-xl);
    letter-spacing: var(--ls-headline-xl);
  }
}

.profile-header__edit {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-primary);
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  padding: 6px 12px;
  transition: background 0.2s;
}
.profile-header__edit:hover { background: var(--color-surface-container-low); }
.profile-header__edit .material-symbols-outlined { font-size: 18px; }

.profile-header__location {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--text-label-md);
  color: var(--color-on-surface-variant);
  margin: 0;
}
.profile-header__location .material-symbols-outlined { font-size: 16px; }

.profile-header__bio {
  font-size: var(--text-body-md);
  line-height: var(--lh-body-md);
  color: var(--color-on-surface-variant);
  margin: 0;
  max-width: 42rem;
}
@media (min-width: 768px) { .profile-header__bio { font-size: var(--text-body-lg); } }
.profile-header__bio--muted { font-style: italic; opacity: 0.7; }

/* ── Tags ─────────────────────────────────────────────────────────────── */
.profile-header__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-base);
}
.profile-header__tags--desktop { display: none; margin-top: var(--space-xs); }
.profile-header__tags--mobile { justify-content: center; }
@media (min-width: 768px) {
  .profile-header__tags--desktop { display: flex; }
  .profile-header__tags--mobile { display: none; }
}

.count-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
  font-size: var(--text-label-sm);
  font-weight: 600;
  letter-spacing: var(--ls-label-sm);
}

/* ── Stat bar ─────────────────────────────────────────────────────────── */
.profile-stats {
  display: flex;
  justify-content: space-around;
  gap: var(--space-md);
  padding: var(--space-md) 0;
  border-top: 1px solid var(--color-outline-variant);
  border-bottom: 1px solid var(--color-outline-variant);
}
@media (min-width: 768px) {
  /* On desktop, stats sit left-aligned and borderless under the header. */
  .profile-stats {
    justify-content: flex-start;
    gap: var(--space-xl);
    border: none;
    padding: var(--space-base) 0 0;
  }
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
}
@media (min-width: 768px) { .stat { align-items: flex-start; } }

.stat__value {
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  font-weight: 700;
  color: var(--color-primary);
}

.stat__label {
  font-size: var(--text-label-sm);
  line-height: var(--lh-label-sm);
  letter-spacing: 0.05em;
  font-weight: 600;
  color: var(--color-on-surface-variant);
  text-transform: uppercase;
}

/* ── Tabs ─────────────────────────────────────────────────────────────── */
.tab-nav {
  display: flex;
  border-bottom: 1px solid var(--color-surface-container-highest);
  margin-top: var(--space-sm);
}
@media (max-width: 767px) {
  .tab-nav { position: sticky; top: 64px; background: var(--color-background); z-index: 30; }
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  flex: 1;
  justify-content: center;
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-label-md);
  font-weight: 500;
  letter-spacing: var(--ls-label-md);
  color: var(--color-secondary);
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color 0.2s, border-color 0.2s;
}
@media (min-width: 768px) { .tab-btn { flex: none; justify-content: flex-start; } }
.tab-btn:hover { color: var(--color-on-background); }
.tab-btn--active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  font-weight: 600;
}

.tab-count {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-on-surface-variant);
  background: var(--color-surface-container-high);
  border-radius: var(--radius-full);
  padding: 0 6px;
  line-height: 1.5;
}
.tab-btn--active .tab-count { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }

/* ── Book grid ────────────────────────────────────────────────────────── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
  padding-top: var(--space-sm);
}
@media (min-width: 600px) { .book-grid { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 960px) { .book-grid { grid-template-columns: repeat(4, 1fr); } }

/* Add-book placeholder (own profile) */
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
.add-book-card__icon { font-size: 40px; color: var(--color-primary); }
.add-book-card__title {
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--color-primary);
  margin: 0;
}
.add-book-card__hint { font-size: var(--text-label-md); color: var(--color-secondary); margin: 0; }

/* ── Empty ────────────────────────────────────────────────────────────── */
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
.empty-state p { margin: 0; }
</style>
