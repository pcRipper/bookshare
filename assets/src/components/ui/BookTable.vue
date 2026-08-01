<script setup>
/**
 * Compact, scannable table view for a book list — the alternative to the card
 * grid. Shows only essential fields (cover, title, author, language, status)
 * plus a "read" checkbox. The checkbox is an inline toggle on books the viewer
 * can edit (own + home, per the server's `canEdit`) and a read-only indicator
 * otherwise. A row click opens the same modal the card does (borrow/edit live
 * there); clicking the checkbox never opens it.
 */
const STATUS_LABELS = {
  own: 'Available',
  lent: 'On Loan',
  currently_reading: 'Reading',
  unavailable: 'Unavailable',
}

defineProps({
  books: { type: Array, required: true },
})

const emit = defineEmits(['open', 'toggle-read'])

function onToggle(book, e) {
  emit('toggle-read', { id: book.id, isRead: e.target.checked })
}
</script>

<template>
  <table class="book-table">
    <thead>
      <tr>
        <th class="book-table__col-read" scope="col">
          <span class="material-symbols-outlined" title="Read">check_circle</span>
        </th>
        <th class="book-table__col-cover" scope="col"><span class="sr-only">Cover</span></th>
        <th scope="col">Title</th>
        <th class="book-table__col-lang" scope="col">Language</th>
        <th class="book-table__col-status" scope="col">Status</th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="book in books"
        :key="book.id"
        class="book-table__row"
        @click="emit('open', book)"
      >
        <!-- Read checkbox: editable where canEdit, else a read-only indicator. -->
        <td class="book-table__col-read" @click.stop>
          <input
            type="checkbox"
            class="book-table__check"
            :checked="book.isRead"
            :disabled="!book.canEdit"
            :aria-label="`Mark “${book.title}” as read`"
            @change="onToggle(book, $event)"
          />
        </td>

        <td class="book-table__col-cover">
          <img
            v-if="book.coverPath"
            :src="book.coverPath"
            :alt="`Cover of ${book.title}`"
            class="book-table__cover"
            loading="lazy"
          />
          <span v-else class="book-table__cover book-table__cover--empty" aria-hidden="true">
            <span class="material-symbols-outlined">menu_book</span>
          </span>
        </td>

        <td>
          <span class="book-table__title">{{ book.title }}</span>
          <span class="book-table__author">{{ book.author }}</span>
        </td>

        <td class="book-table__col-lang">
          <span v-if="book.languageName" class="book-table__lang">{{ book.languageName }}</span>
          <span v-else class="book-table__muted">—</span>
        </td>

        <td class="book-table__col-status">
          <span class="book-table__status" :class="`book-table__status--${book.status}`">
            {{ STATUS_LABELS[book.status] ?? book.status }}
          </span>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.book-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: var(--space-sm);
  font-size: var(--text-body-md);
}

.book-table thead th {
  text-align: left;
  padding: var(--space-xs) var(--space-sm);
  font-size: var(--text-label-sm);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-on-surface-variant);
  border-bottom: 1px solid var(--color-outline-variant);
  white-space: nowrap;
}
.book-table thead .book-table__col-read .material-symbols-outlined {
  font-size: 18px;
  vertical-align: middle;
}

.book-table__row {
  cursor: pointer;
  border-bottom: 1px solid var(--color-surface-container-highest);
  transition: background 0.15s;
}
.book-table__row:hover { background: var(--color-surface-container-low); }
.book-table__row td { padding: var(--space-sm); vertical-align: middle; }

/* Read checkbox column */
.book-table__col-read { width: 40px; text-align: center; }
.book-table__check {
  width: 18px;
  height: 18px;
  accent-color: var(--color-primary);
  cursor: pointer;
}
.book-table__check:disabled { cursor: default; opacity: 0.85; }

/* Cover column */
.book-table__col-cover { width: 40px; }
.book-table__cover {
  display: block;
  width: 32px;
  height: 48px;
  object-fit: cover;
  border-radius: 3px;
  background: var(--color-surface-container-low);
}
.book-table__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--color-surface-container) 0%, var(--color-surface-variant) 100%);
}
.book-table__cover--empty .material-symbols-outlined {
  font-size: 20px;
  color: var(--color-outline);
  opacity: 0.6;
}

/* Title + author stacked */
.book-table__title {
  display: block;
  font-family: var(--font-display);
  font-size: 16px;
  line-height: 1.25;
  color: var(--color-on-background);
}
.book-table__author {
  display: block;
  font-size: 13px;
  color: var(--color-secondary);
}

.book-table__lang { color: var(--color-on-surface-variant); }
.book-table__muted { color: var(--color-outline); }

/* Status pill */
.book-table__status {
  display: inline-block;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-sm);
  font-weight: 600;
  white-space: nowrap;
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
.book-table__status--own { background: var(--color-primary-fixed); color: var(--color-on-primary-fixed-variant); }
.book-table__status--lent { background: var(--color-primary); color: var(--color-on-primary); }
.book-table__status--currently_reading { background: var(--color-tertiary); color: #fff; }

/* Non-essential columns collapse on narrow screens. */
@media (max-width: 599px) {
  .book-table__col-lang,
  .book-table__col-status { display: none; }
}

.sr-only {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
