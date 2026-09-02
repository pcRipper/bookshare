<script setup>
/**
 * One shelf of books, rendered whole: toolbar, loading skeleton, empty state,
 * the card grid or the table, and the pager.
 *
 * The owner's two shelves — Books and the Wish List — are the same entity split
 * by `is_wished`, and their panels were two copies of the same hundred lines,
 * differing only in the store slice behind them, the lead card's wording and
 * which extra controls sat in the toolbar. That is the duplication `LoanCard`
 * collapsed on the Sharing side, so it collapses the same way here: everything
 * structural lives once and the per-shelf parts arrive as props and two slots.
 *
 *   `filters` — controls that narrow the list (the wish list's priority pills
 *               and sort), placed left of the layout toggle.
 *   `actions` — whole-shelf actions (share / import / export), placed right of
 *               it.
 *
 * The search box stays uncontrolled and is seeded with `:initial`: this panel
 * is behind a `v-if`, so leaving the shelf unmounts the box while the store
 * keeps the filter, and without seeding the list returns filtered behind an
 * empty box.
 */
import BookCard from '@/components/library/BookCard.vue'
import BookGridSkeleton from '@/components/ui/BookGridSkeleton.vue'
import BookTable from '@/components/ui/BookTable.vue'
import BookTableSkeleton from '@/components/ui/BookTableSkeleton.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import ViewToggle from '@/components/ui/ViewToggle.vue'
import { useBookView } from '@/composables/useBookView'

defineProps({
  books: { type: Array, required: true },
  meta: { type: Object, required: true }, // { page, totalPages }
  loading: { type: Boolean, default: false },
  // Seeds the search box on mount (see the note above).
  query: { type: String, default: '' },
  // True when *any* narrowing is active — the query or a shelf-specific filter.
  // Gates both the "no matches" state and the grid's lead card, which must not
  // sit at the head of a filtered result.
  filtered: { type: Boolean, default: false },
  // Wish-list shelf: the table swaps its status column for the priority one.
  wish: { type: Boolean, default: false },
  searchPlaceholder: { type: String, default: null },
  noMatches: { type: String, default: null },
  addLabel: { type: String, required: true },
  addIcon: { type: String, default: 'add_circle' },
  addTitle: { type: String, required: true },
  addHint: { type: String, required: true },
})

defineEmits(['search', 'open', 'toggle-read', 'page', 'add'])

const { bookView, tableDetailed } = useBookView()
</script>

<template>
  <div>
    <div class="collection-toolbar">
      <SearchInput
        class="collection-toolbar__search"
        :placeholder="searchPlaceholder"
        :loading="loading"
        :initial="query"
        @search="$emit('search', $event)"
      />
      <div class="collection-toolbar__actions">
        <slot name="filters" />
        <ViewToggle v-model="bookView" v-model:detailed="tableDetailed" />
        <!-- The grid leads with an "add" placeholder card; the table has no such
             cell, so the affordance moves into the toolbar (desktop only —
             mobile already has the FAB). -->
        <button
          v-if="bookView === 'table'"
          class="toolbar-btn toolbar-btn--add"
          type="button"
          :aria-label="addLabel"
          @click="$emit('add')"
        >
          <span class="material-symbols-outlined">add</span>
          <span class="toolbar-btn__label">{{ addLabel }}</span>
        </button>
        <slot name="actions" />
      </div>
    </div>

    <template v-if="loading && !books.length">
      <BookTableSkeleton v-if="bookView === 'table'" :count="8" :detailed="tableDetailed" />
      <BookGridSkeleton v-else :count="8" class="collection-skeleton" />
    </template>

    <!-- No matches for an active search or filter -->
    <div v-else-if="filtered && !books.length" class="empty-state">
      <span class="material-symbols-outlined empty-state__icon">search_off</span>
      <p class="empty-state__text">{{ noMatches }}</p>
    </div>

    <BookTable
      v-else-if="bookView === 'table'"
      :books="books"
      :detailed="tableDetailed"
      :wish="wish"
      read-editable
      @open="$emit('open', $event)"
      @toggle-read="$emit('toggle-read', $event)"
    />

    <div v-else class="book-grid">
      <!-- Lead card: first page only, and never at the head of a filtered list. -->
      <div
        v-if="meta.page === 1 && !filtered"
        class="add-book-card"
        role="button"
        tabindex="0"
        @click="$emit('add')"
        @keydown.enter.prevent="$emit('add')"
        @keydown.space.prevent="$emit('add')"
      >
        <span class="material-symbols-outlined add-book-card__icon">{{ addIcon }}</span>
        <h3 class="add-book-card__title">{{ addTitle }}</h3>
        <p class="add-book-card__hint">{{ addHint }}</p>
      </div>
      <BookCard
        v-for="book in books"
        :key="book.id"
        :book="book"
        @click="$emit('open', $event)"
      />
    </div>

    <Pagination
      :page="meta.page"
      :total-pages="meta.totalPages"
      :disabled="loading"
      @change="$emit('page', $event)"
    />
  </div>
</template>

<style scoped>
/* ── Toolbar (search + shelf controls) ────────────────────────────────── */
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
  align-items: center;
  gap: var(--space-sm);
  /* Shrinkable on purpose: with flex-shrink:0 this row keeps its max-content
     width, so its own flex-wrap never engages and extra buttons overflow the
     viewport instead of wrapping. */
  min-width: 0;
}

/* ── Book grid ────────────────────────────────────────────────────────── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-md);
  padding-top: var(--space-sm);
}
@media (min-width: 600px) { .book-grid { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 960px) { .book-grid { grid-template-columns: repeat(4, 1fr); } }

/* Match the loaded grid's top offset so the skeleton doesn't sit flush against
   the toolbar. */
.collection-skeleton { padding-top: var(--space-sm); }

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

/* ── Empty state ──────────────────────────────────────────────────────── */
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
</style>
