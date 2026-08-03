<script setup>
/**
 * Compact, scannable table view for a book list — the alternative to the card
 * grid.
 *
 * Two densities: the default shows the essential fields (read, cover, title,
 * author, language, status); `detailed` adds the rest of the record
 * (categories, description, ISBN, holder, added date). `showOwner` adds the
 * owner column, which only Discover needs — elsewhere every row shares one
 * owner. Wide modes scroll horizontally rather than dropping columns, so no
 * data silently disappears on a narrow screen.
 *
 * The read column is an inline toggle only where `readEditable` is set (the
 * owner's own Library) and the server says the book is editable; everywhere
 * else it's a static indicator, never a dead disabled control.
 *
 * A row click opens the same modal the card does (borrow/edit live there);
 * clicking the read checkbox or the owner link never opens it.
 */
import { useI18n } from 'vue-i18n'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import CategoryTag from '@/components/ui/CategoryTag.vue'
import { currentLocale } from '@/i18n'
import { useCoverFallback } from '@/composables/useCoverFallback'
import { languageLabel } from '@/utils/languages'
import { relativeTime } from '@/utils/time'

const { hasCover, onCoverError } = useCoverFallback()
const { t } = useI18n()

const STATUS_KEYS = {
  own: 'book.statusOption.own',
  lent: 'book.status.lent',
  currently_reading: 'book.status.reading',
  unavailable: 'book.status.unavailable',
}

function statusLabel(status) {
  const key = STATUS_KEYS[status]
  return key ? t(key) : status
}

defineProps({
  books: { type: Array, required: true },
  // Show the full record instead of only the essential columns.
  detailed: { type: Boolean, default: false },
  // Discover only: whose book is this?
  showOwner: { type: Boolean, default: false },
  // Allow ticking "read" inline (owner's own library; still gated on canEdit).
  readEditable: { type: Boolean, default: false },
})

const emit = defineEmits(['open', 'toggle-read'])

function onToggle(book, e) {
  emit('toggle-read', { id: book.id, isRead: e.target.checked })
}

function absoluteDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString(currentLocale(), { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- Scroll container: wide (detailed) tables and narrow screens pan sideways
       instead of losing columns. -->
  <div class="book-table__scroll">
    <table class="book-table" :class="{ 'book-table--detailed': detailed }">
      <thead>
        <tr>
          <th class="book-table__col-read" scope="col">{{ t('table.read') }}</th>
          <th class="book-table__col-cover" scope="col"><span class="sr-only">{{ t('table.cover') }}</span></th>
          <th class="book-table__col-title" scope="col">{{ t('table.title') }}</th>
          <template v-if="detailed">
            <th class="book-table__col-categories" scope="col">{{ t('table.categories') }}</th>
            <th class="book-table__col-desc" scope="col">{{ t('table.description') }}</th>
            <th class="book-table__col-isbn" scope="col">{{ t('table.isbn') }}</th>
          </template>
          <th class="book-table__col-lang" scope="col">{{ t('table.language') }}</th>
          <th class="book-table__col-status" scope="col">{{ t('table.status') }}</th>
          <th v-if="detailed" class="book-table__col-person" scope="col">{{ t('table.holder') }}</th>
          <th v-if="showOwner" class="book-table__col-person" scope="col">{{ t('table.owner') }}</th>
          <th v-if="detailed" class="book-table__col-added" scope="col">{{ t('table.added') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="book in books"
          :key="book.id"
          class="book-table__row"
          @click="emit('open', book)"
        >
          <!-- Read: an inline toggle on your own editable books, a static
               indicator anywhere else. -->
          <td class="book-table__col-read" @click.stop>
            <input
              v-if="readEditable && book.canEdit"
              type="checkbox"
              class="book-table__check"
              :checked="book.isRead"
              :title="book.isRead ? t('table.toggleToUnread') : t('table.toggleToRead')"
              :aria-label="t('table.markRead', { title: book.title })"
              @change="onToggle(book, $event)"
            />
            <span
              v-else
              class="material-symbols-outlined book-table__read-icon"
              :class="{ 'book-table__read-icon--on': book.isRead }"
              :title="book.isRead ? t('table.isRead') : t('table.notRead')"
            >{{ book.isRead ? 'check_circle' : 'radio_button_unchecked' }}</span>
          </td>

          <td class="book-table__col-cover">
            <img
              v-if="hasCover(book)"
              :src="book.coverPath"
              :alt="t('book.coverAlt', { title: book.title })"
              class="book-table__cover"
              loading="lazy"
              @error="onCoverError(book.id)"
            />
            <span v-else class="book-table__cover book-table__cover--empty" aria-hidden="true">
              <span class="material-symbols-outlined">menu_book</span>
            </span>
          </td>

          <td class="book-table__col-title">
            <span class="book-table__title">{{ book.title }}</span>
            <span class="book-table__author">{{ book.author }}</span>
          </td>

          <template v-if="detailed">
            <td class="book-table__col-categories">
              <span v-if="book.categories?.length" class="book-table__chips">
                <CategoryTag
                  v-for="c in book.categories"
                  :key="c.id"
                  :label="c.name"
                  :color="c.colorHex"
                />
              </span>
              <span v-else class="book-table__muted">—</span>
            </td>

            <td class="book-table__col-desc">
              <span v-if="book.description" class="book-table__desc" :title="book.description">
                {{ book.description }}
              </span>
              <span v-else class="book-table__muted">—</span>
            </td>

            <td class="book-table__col-isbn">
              <span v-if="book.isbn">{{ book.isbn }}</span>
              <span v-else class="book-table__muted">—</span>
            </td>
          </template>

          <td class="book-table__col-lang">
            <span v-if="book.language" class="book-table__lang">{{ languageLabel(book.language, book.languageName) }}</span>
            <span v-else class="book-table__muted">—</span>
          </td>

          <td class="book-table__col-status">
            <span class="book-table__status" :class="`book-table__status--${book.status}`">
              {{ statusLabel(book.status) }}
            </span>
          </td>

          <!-- Holder: only interesting once the book has left its shelf. -->
          <td v-if="detailed" class="book-table__col-person">
            <RouterLink
              v-if="!book.isHome && book.currentHolder"
              :to="`/profile/${book.currentHolder.id}`"
              class="book-table__person"
              @click.stop
            >
              <BaseAvatar :src="book.currentHolder.avatarUrl" :name="book.currentHolder.fullName" size="sm" />
              <span class="book-table__person-name">{{ book.currentHolder.fullName }}</span>
            </RouterLink>
            <span v-else class="book-table__muted">—</span>
          </td>

          <td v-if="showOwner" class="book-table__col-person">
            <RouterLink
              v-if="book.owner"
              :to="`/profile/${book.owner.id}`"
              class="book-table__person"
              @click.stop
            >
              <BaseAvatar :src="book.owner.avatarUrl" :name="book.owner.fullName" size="sm" />
              <span class="book-table__person-name">{{ book.owner.fullName }}</span>
            </RouterLink>
            <span v-else class="book-table__muted">—</span>
          </td>

          <td v-if="detailed" class="book-table__col-added">
            <span v-if="book.createdAt" :title="absoluteDate(book.createdAt)">
              {{ relativeTime(book.createdAt) }}
            </span>
            <span v-else class="book-table__muted">—</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.book-table__scroll {
  overflow-x: auto;
  /* Room for the focus ring / hover row edge while scrolling. */
  margin-inline: calc(var(--space-xs) * -1);
  padding-inline: var(--space-xs);
}

.book-table {
  width: 100%;
  min-width: 520px;
  border-collapse: collapse;
  margin-top: var(--space-sm);
  font-size: var(--text-body-md);
}
/* The full record needs more room than a phone has — the wrapper scrolls. */
.book-table--detailed { min-width: 1080px; }

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

.book-table__row {
  cursor: pointer;
  border-bottom: 1px solid var(--color-surface-container-highest);
  transition: background 0.15s;
}
.book-table__row:hover { background: var(--color-surface-container-low); }
.book-table__row td { padding: var(--space-sm); vertical-align: middle; }

/* Read column */
.book-table__col-read { width: 48px; text-align: center; }
.book-table__check {
  width: 18px;
  height: 18px;
  accent-color: var(--color-primary);
  cursor: pointer;
}
.book-table__read-icon {
  font-size: 20px;
  color: var(--color-outline);
  vertical-align: middle;
}
.book-table__read-icon--on {
  color: var(--color-primary);
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
}

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
.book-table__col-title { min-width: 180px; }
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

/* Detailed columns */
.book-table__col-categories { max-width: 220px; }
.book-table__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
}
.book-table__col-desc { max-width: 280px; }
.book-table__desc {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-on-surface-variant);
}
.book-table__col-isbn {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  color: var(--color-on-surface-variant);
}
.book-table__col-added {
  white-space: nowrap;
  color: var(--color-on-surface-variant);
}

/* Person (owner / holder) */
/* min-width matters as much as max: the table is allowed to outgrow its scroll
   container, so without a floor the browser squeezes this column and ellipsis
   turns a name into "Mal" instead of scrolling. */
.book-table__col-person { min-width: 128px; max-width: 180px; }
.book-table__person {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  color: var(--color-secondary);
  min-width: 0;
}
.book-table__person:hover .book-table__person-name { color: var(--color-primary); }
.book-table__person-name {
  font-size: var(--text-label-sm);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.15s;
}

.book-table__lang { color: var(--color-on-surface-variant); white-space: nowrap; }
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
