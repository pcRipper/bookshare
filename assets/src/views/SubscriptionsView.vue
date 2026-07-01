<script setup>
import { ref, reactive, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useSubscriptionsStore } from '@/stores/subscriptions'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import AppLayout from '@/components/layout/AppLayout.vue'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import DiscoverBookCard from '@/components/discover/DiscoverBookCard.vue'
import BookDetailModal from '@/components/ui/BookDetailModal.vue'

const store = useSubscriptionsStore()
const toast = useToastStore()
const { feed, loadingFeed, error } = storeToRefs(store)

onMounted(() => store.fetchFeed())

/* ── Borrow requests (per-book in-flight tracking for button loaders) ──── */
const requesting = reactive(new Set())
async function onRequest(bookId) {
  if (requesting.has(bookId)) return
  requesting.add(bookId)
  try {
    await store.requestBorrow(bookId)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Could not send your borrow request.'))
  } finally {
    requesting.delete(bookId)
  }
}

/* ── Book detail modal (opens on card click) ───────────────────────────── */
const detailBook = ref(null)
function openDetail(book) { detailBook.value = book }
</script>

<template>
  <AppLayout>
    <div class="subscriptions-page">
      <header class="subscriptions-header">
        <h1 class="subscriptions-header__title">Following</h1>
        <p class="subscriptions-header__subtitle">Recent books from readers you follow.</p>
      </header>

      <!-- Loading -->
      <div v-if="loadingFeed && !feed.length" class="feed">
        <section v-for="n in 2" :key="n" class="feed-row">
          <div class="feed-row__head">
            <BaseSkeleton width="40px" height="40px" circle />
            <BaseSkeleton width="160px" height="18px" />
          </div>
          <div class="feed-row__scroller">
            <BaseSkeleton
              v-for="m in 5"
              :key="m"
              width="160px"
              height="280px"
              radius="var(--radius-default)"
            />
          </div>
        </section>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="feed-state">
        <span class="material-symbols-outlined feed-state__icon">error</span>
        <p>Something went wrong loading your feed.</p>
        <button class="feed-state__link" @click="store.fetchFeed()">Try again</button>
      </div>

      <!-- Empty: not following anyone (or none of them have books) -->
      <div v-else-if="!feed.length" class="feed-state">
        <span class="material-symbols-outlined feed-state__icon">group</span>
        <p>You're not following anyone with books to show yet.</p>
        <RouterLink to="/discover" class="feed-state__link">Discover readers to follow</RouterLink>
      </div>

      <!-- Feed: one row per followed reader -->
      <div v-else class="feed">
        <section v-for="group in feed" :key="group.user.id" class="feed-row">
          <RouterLink :to="`/profile/${group.user.id}`" class="feed-row__head">
            <BaseAvatar :src="group.user.avatarUrl" :name="group.user.fullName" size="md" />
            <span class="feed-row__name">{{ group.user.fullName }}</span>
            <span class="material-symbols-outlined feed-row__chevron">chevron_right</span>
          </RouterLink>

          <div v-hscroll class="feed-row__scroller hide-scrollbar">
            <div v-for="book in group.books" :key="book.id" class="feed-row__item">
              <DiscoverBookCard
                :book="book"
                :pending="requesting.has(book.id)"
                @request="onRequest"
                @open="openDetail"
              />
            </div>
          </div>
        </section>
      </div>
    </div>

    <BookDetailModal
      :open="!!detailBook"
      :book="detailBook"
      :pending="!!detailBook && requesting.has(detailBook.id)"
      @request="onRequest"
      @close="detailBook = null"
    />
  </AppLayout>
</template>

<style scoped>
.subscriptions-page {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: var(--space-xl) var(--space-gutter);
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}
@media (max-width: 767px) {
  .subscriptions-page { padding: var(--space-lg) var(--space-gutter) var(--space-xl); }
}

.subscriptions-header__title {
  font-family: var(--font-display);
  font-size: var(--text-headline-lg-mobile);
  line-height: var(--lh-headline-lg-mobile);
  font-weight: 700;
  color: var(--color-on-background);
  margin: 0;
}
@media (min-width: 768px) {
  .subscriptions-header__title {
    font-size: var(--text-headline-xl);
    line-height: var(--lh-headline-xl);
    letter-spacing: var(--ls-headline-xl);
  }
}
.subscriptions-header__subtitle {
  font-size: var(--text-body-md);
  color: var(--color-secondary);
  margin: var(--space-xs) 0 0;
}

/* ── Feed ─────────────────────────────────────────────────────────────── */
.feed { display: flex; flex-direction: column; gap: var(--space-xl); }

.feed-row { display: flex; flex-direction: column; gap: var(--space-sm); }

.feed-row__head {
  display: inline-flex;
  align-items: center;
  gap: var(--space-sm);
  align-self: flex-start;
  color: var(--color-on-background);
}
.feed-row__name {
  font-family: var(--font-display);
  font-size: var(--text-title-md, 18px);
  font-weight: 600;
  transition: color 0.15s;
}
.feed-row__head:hover .feed-row__name { color: var(--color-primary); }
.feed-row__chevron { font-size: 20px; color: var(--color-secondary); }

/* Horizontally scrollable row of book cards (mirrors Discover's pill scroller). */
.feed-row__scroller {
  display: flex;
  gap: var(--space-md);
  overflow-x: auto;
  padding-bottom: var(--space-xs);
  scroll-snap-type: x proximity;
}
.feed-row__item {
  flex: 0 0 auto;
  width: 160px;
  scroll-snap-align: start;
}
@media (min-width: 768px) {
  .feed-row__item { width: 200px; }
}

.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.hide-scrollbar::-webkit-scrollbar { display: none; }

/* ── States ───────────────────────────────────────────────────────────── */
.feed-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  padding: var(--space-xl) 0;
  color: var(--color-on-surface-variant);
  text-align: center;
}
.feed-state__icon { font-size: 48px; opacity: 0.5; }
.feed-state__link {
  color: var(--color-primary);
  font-weight: 500;
}
.feed-state__link:hover { text-decoration: underline; }
</style>
