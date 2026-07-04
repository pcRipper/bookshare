<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useProfileStore } from '@/stores/profile'
import { useSubscriptionsStore } from '@/stores/subscriptions'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'
import BorrowBookCard from '@/components/profile/BorrowBookCard.vue'
import EditProfileModal from '@/components/profile/EditProfileModal.vue'
import BookDetailModal from '@/components/ui/BookDetailModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SearchInput from '@/components/ui/SearchInput.vue'

const route = useRoute()
const store = useProfileStore()
const subscriptions = useSubscriptionsStore()
const toast = useToastStore()
const { profile, books, booksMeta, booksLoading, availableCount, shelf, booksQuery, loading, error } = storeToRefs(store)

/* ── Follow / unfollow (other readers' profiles only) ─────────────────── */
const subscribed = ref(false)
const subscribeBusy = ref(false)

// Sync the local toggle to the server-authoritative flag whenever the profile
// (re)loads — the button reflects reality across reloads and profile switches.
watch(profile, p => { subscribed.value = !!p?.isSubscribed })

async function toggleSubscription() {
  if (subscribeBusy.value || !profile.value) return
  subscribeBusy.value = true
  const id = profile.value.id
  const wasSubscribed = subscribed.value
  try {
    if (wasSubscribed) {
      await subscriptions.unsubscribe(id)
      subscribed.value = false
    } else {
      await subscriptions.subscribe(id)
      subscribed.value = true
      toast.success(`Following ${profile.value.fullName}`)
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, wasSubscribed ? 'Could not unfollow.' : 'Could not follow this reader.'))
  } finally {
    subscribeBusy.value = false
  }
}

/* ── Tabs (shelves) ───────────────────────────────────────────────────── */
// "Available to Borrow" and "Full Collection" are two server-paginated shelves
// (the former filters to status=own). Counts come from each shelf's total: the
// available shelf reports its own total; the full shelf uses the profile stat.
const tabs = computed(() => [
  { key: 'available', label: 'Available to Borrow', count: availableCount.value },
  { key: 'full',      label: 'Full Collection',     count: profile.value?.stats?.totalBooks ?? 0 },
])

/* ── Stats ────────────────────────────────────────────────────────────── */
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

/* ── Error states ─────────────────────────────────────────────────────── */
const stateIcon = computed(() => ({
  'not-found': 'person_off',
  private: 'lock',
}[error.value] ?? 'error'))

const stateMessage = computed(() => ({
  'not-found': 'This reader could not be found.',
  private: 'This reader keeps their library private.',
}[error.value] ?? 'Something went wrong loading this profile.'))

/* ── Loading ──────────────────────────────────────────────────────────── */
function load() {
  // fetchProfile resets to page 1 of the "available" shelf.
  store.fetchProfile(route.params.id)
}
onMounted(load)
watch(() => route.params.id, load)

/* ── Borrow requests (per-book in-flight tracking for button loaders) ──── */
const requesting = reactive(new Set())
async function onRequest(bookId) {
  if (requesting.has(bookId)) return
  requesting.add(bookId)
  try {
    await store.requestBorrow(bookId)
  } finally {
    requesting.delete(bookId)
  }
}

/* ── Book detail modal (read-only preview for all profiles, own included ─
   book management lives in the library, not here) ─────────────────────── */
const detailBook = ref(null)
function openDetail(book) { detailBook.value = book }

/* ── Own-profile editing (only when profile.isSelf) ───────────────────── */
const editProfileOpen = ref(false)
const editBusy = ref(false)

async function onProfileSave(payload) {
  editBusy.value = true
  try {
    await store.updateProfile(payload)
    editProfileOpen.value = false
  } finally {
    editBusy.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="profile-page">

      <!-- Loading -->
      <div v-if="loading" class="profile-skeleton">
        <section class="profile-skeleton__header">
          <BaseSkeleton width="96px" height="96px" circle />
          <div class="profile-skeleton__lines">
            <BaseSkeleton width="55%" height="28px" />
            <BaseSkeleton width="35%" height="14px" />
            <BaseSkeleton width="90%" height="14px" />
            <BaseSkeleton width="70%" height="14px" />
          </div>
        </section>
        <BookGridSkeleton :count="8" />
      </div>

      <!-- Not found / private / error -->
      <div v-else-if="error" class="profile-state">
        <span class="material-symbols-outlined profile-state__icon">{{ stateIcon }}</span>
        <p>{{ stateMessage }}</p>
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
              <button
                v-else
                class="profile-header__follow"
                :class="{ 'profile-header__follow--active': subscribed }"
                :disabled="subscribeBusy"
                @click="toggleSubscription"
              >
                <BaseSpinner v-if="subscribeBusy" size="sm" />
                <span v-else class="material-symbols-outlined">{{ subscribed ? 'how_to_reg' : 'person_add' }}</span>
                {{ subscribed ? 'Following' : 'Follow' }}
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
        <div v-hscroll class="tab-nav" role="tablist">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            class="tab-btn"
            :class="{ 'tab-btn--active': shelf === tab.key }"
            role="tab"
            :aria-selected="shelf === tab.key"
            @click="store.setShelf(tab.key)"
          >
            {{ tab.label }}
            <span class="tab-count">{{ tab.count }}</span>
          </button>
        </div>

        <!-- ── Search ─────────────────────────────────────────────────── -->
        <SearchInput
          :key="`${profile.id}-${shelf}`"
          class="profile-search"
          placeholder="Search by title, author or ISBN"
          :loading="booksLoading"
          @search="store.setBooksSearch"
        />

        <!-- ── Book grid ──────────────────────────────────────────────── -->
        <BookGridSkeleton v-if="booksLoading" :count="8" />
        <div v-else-if="books.length" class="book-grid" role="tabpanel">
          <BorrowBookCard
            v-for="book in books"
            :key="book.id"
            :book="book"
            :is-self="profile.isSelf"
            :pending="requesting.has(book.id)"
            @request="onRequest"
            @open="openDetail"
          />
        </div>
        <div v-else class="empty-state">
          <span class="material-symbols-outlined empty-state__icon">{{ booksQuery ? 'search_off' : 'auto_stories' }}</span>
          <p v-if="booksQuery">No books match “{{ booksQuery }}”.</p>
          <p v-else>{{ shelf === 'available' ? 'No books available to borrow right now.' : 'This collection is empty.' }}</p>
        </div>

        <Pagination
          v-if="!booksLoading"
          :page="booksMeta.page"
          :total-pages="booksMeta.totalPages"
          :disabled="booksLoading"
          @change="store.fetchBooksPage"
        />
      </template>
    </div>

    <!-- Own-profile editors -->
    <EditProfileModal
      :open="editProfileOpen"
      :profile="profile"
      :busy="editBusy"
      @save="onProfileSave"
      @close="editProfileOpen = false"
    />

    <!-- Read-only detail (all profiles; own books have no borrow action) -->
    <BookDetailModal
      :open="!!detailBook"
      :book="detailBook"
      :is-self="profile?.isSelf"
      :pending="!!detailBook && requesting.has(detailBook.id)"
      @request="onRequest"
      @close="detailBook = null"
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

/* ── Loading skeleton ─────────────────────────────────────────────────── */
.profile-skeleton { display: flex; flex-direction: column; gap: var(--space-lg); }
.profile-skeleton__header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-md);
  padding-bottom: var(--space-md);
}
@media (min-width: 768px) {
  .profile-skeleton__header { flex-direction: row; align-items: center; gap: var(--space-lg); }
}
.profile-skeleton__lines {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  width: 100%;
  max-width: 32rem;
  align-items: center;
}
@media (min-width: 768px) { .profile-skeleton__lines { align-items: flex-start; } }

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
  overflow-wrap: anywhere;
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

/* Follow toggle: solid primary while "Follow", outlined "Following" once active. */
.profile-header__follow {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-primary);
  background: var(--color-primary);
  border: 1px solid var(--color-primary);
  border-radius: var(--radius-default);
  padding: 6px 14px;
  transition: background 0.2s, color 0.2s, opacity 0.2s;
}
.profile-header__follow:hover:not(:disabled) { background: var(--color-primary-container); }
.profile-header__follow:disabled { opacity: 0.7; cursor: default; }
.profile-header__follow .material-symbols-outlined { font-size: 18px; }
.profile-header__follow--active {
  color: var(--color-primary);
  background: transparent;
  border-color: var(--color-outline-variant);
}
.profile-header__follow--active:hover:not(:disabled) {
  background: var(--color-error-container);
  color: var(--color-error);
  border-color: var(--color-error-container);
}

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

/* ── Tabs ─────────────────────────────────────────────────────────────── */
.tab-nav {
  display: flex;
  border-bottom: 1px solid var(--color-surface-container-highest);
  margin-top: var(--space-sm);
}
@media (max-width: 767px) {
  .tab-nav { position: sticky; top: 64px; background: var(--color-background); z-index: 30; overflow-x: auto; scrollbar-width: none; }
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

/* ── Search ───────────────────────────────────────────────────────────── */
.profile-search { margin-top: var(--space-sm); max-width: 420px; }

/* ── Book grid ────────────────────────────────────────────────────────── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
  padding-top: var(--space-sm);
}
@media (min-width: 600px) { .book-grid { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 960px) { .book-grid { grid-template-columns: repeat(4, 1fr); } }

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
