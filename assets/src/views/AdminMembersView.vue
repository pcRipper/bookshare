<script setup>
/**
 * The admin panel's Members tab: who is here, and the two things an operator can
 * do about one of them.
 *
 * A table rather than the reader-card grid Discover uses. The card is built to
 * make somebody look worth following — avatar-forward, counts as social proof —
 * and that is the opposite of what this screen is for. Here the operator is
 * scanning for one row among many on attributes cards deliberately omit: the
 * email address, when they joined, whether they are suspended.
 */
import { onMounted, computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminUsersStore } from '@/stores/adminUsers'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import { relativeTime } from '@/utils/time'
import BaseAvatar from '@/components/ui/BaseAvatar.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import Pagination from '@/components/ui/Pagination.vue'
import BanMemberModal from '@/components/admin/BanMemberModal.vue'
import DeleteMemberModal from '@/components/admin/DeleteMemberModal.vue'

const store = useAdminUsersStore()
const auth = useAuthStore()
const toast = useToastStore()
const { t } = useI18n()

const { items, page, total, totalPages, query, status, loading, error } = storeToRefs(store)

onMounted(() => {
  // Refetched on every entry, with no `loaded` flag — the Sharing panel's rule
  // rather than the shelves': this list changes when *other* people sign up,
  // and nothing on this page writes to it.
  load()
})

async function load(nextPage) {
  try {
    await store.fetchUsers(nextPage)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.members.loadFailed')))
  }
}

/* ── filters ───────────────────────────────────────────────────────────── */

const statusFilters = computed(() => [
  { key: 'all', label: t('admin.members.filterAll') },
  { key: 'active', label: t('admin.members.filterActive') },
  { key: 'banned', label: t('admin.members.filterBanned') },
  { key: 'deleted', label: t('admin.members.filterDeleted') },
])

function onSearch(value) {
  store.setQuery(value)?.catch(e => toast.error(apiErrorMessage(e, t('admin.members.loadFailed'))))
}

function onStatus(key) {
  store.setStatus(key)?.catch(e => toast.error(apiErrorMessage(e, t('admin.members.loadFailed'))))
}

/* ── row state ─────────────────────────────────────────────────────────── */

const memberState = member =>
  member.deletedAt ? 'deleted' : member.bannedAt ? 'banned' : 'active'

/**
 * Whether the operator may act on this row at all. Mirrors the server's guards
 * rather than trusting the button to be the only path — the API refuses either
 * way; this is so a disabled control explains itself instead of producing a 409
 * on click.
 */
const actionable = member =>
  member.id !== auth.user?.id && !member.isAdmin && !member.deletedAt

function lockReason(member) {
  if (member.deletedAt) return t('admin.members.lockedDeleted')
  if (member.id === auth.user?.id) return t('admin.members.lockedSelf')
  if (member.isAdmin) return t('admin.members.lockedAdmin')
  return null
}

/* ── actions ───────────────────────────────────────────────────────────── */

// Per-row rather than a single flag: the table stays interactive while one row
// is mid-request, and the spinner lands on the row that is actually working.
const busyId = ref(null)
const banTarget = ref(null)
const deleteTarget = ref(null)

async function run(member, action, successKey) {
  busyId.value = member.id
  try {
    await action()
    toast.success(t(successKey, { name: member.fullName }))
    banTarget.value = null
    deleteTarget.value = null
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

const confirmBan = reason =>
  run(banTarget.value, () => store.ban(banTarget.value.id, reason), 'admin.members.banned')

const confirmDelete = () =>
  run(deleteTarget.value, () => store.remove(deleteTarget.value.id), 'admin.members.deleted')

const unban = member =>
  run(member, () => store.unban(member.id), 'admin.members.unbanned')
</script>

<template>
  <div class="members">
    <div class="members__toolbar">
      <SearchInput
        class="members__search"
        :placeholder="t('admin.members.searchPlaceholder')"
        :loading="loading"
        :initial="query"
        @search="onSearch"
      />
      <p v-if="!loading" class="members__total">
        {{ t('admin.members.total', { count: total }, total) }}
      </p>
    </div>

    <div class="filter-row" role="group" :aria-label="t('admin.members.filterLabel')">
      <button
        v-for="f in statusFilters"
        :key="f.key"
        class="filter-pill"
        :class="{ 'filter-pill--active': status === f.key }"
        type="button"
        @click="onStatus(f.key)"
      >
        {{ f.label }}
      </button>
    </div>

    <div v-if="error === 'forbidden'" class="members__state">
      <span class="material-symbols-outlined members__state-icon">lock</span>
      <p>{{ t('admin.forbidden') }}</p>
    </div>

    <div v-else-if="error && !items.length" class="members__state">
      <span class="material-symbols-outlined members__state-icon">error</span>
      <p>{{ t('admin.members.loadFailed') }}</p>
      <button class="members__state-link" @click="load()">{{ t('common.retry') }}</button>
    </div>

    <div v-else-if="loading && !items.length" class="members__loading">
      <BaseSpinner size="lg" />
    </div>

    <div v-else-if="!items.length" class="members__state">
      <span class="material-symbols-outlined members__state-icon">group_off</span>
      <p>{{ t('admin.members.empty') }}</p>
    </div>

    <template v-else>
      <!-- Scrolls sideways rather than dropping columns, the rule BookTable
           already follows: an operator on a narrow screen still needs the email
           and the state, which are the two widest things here. -->
      <div class="members__scroll">
        <table class="members__table" :class="{ 'members__table--busy': loading }">
          <thead>
            <tr>
              <th scope="col">{{ t('admin.members.colMember') }}</th>
              <th scope="col">{{ t('admin.members.colJoined') }}</th>
              <th scope="col" class="members__num">{{ t('admin.members.colBooks') }}</th>
              <th scope="col" class="members__num">{{ t('admin.members.colCollections') }}</th>
              <th scope="col">{{ t('admin.members.colState') }}</th>
              <th scope="col"><span class="sr-only">{{ t('admin.members.colActions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in items" :key="member.id" :class="`members__row--${memberState(member)}`">
              <td>
                <div class="members__identity">
                  <BaseAvatar :src="member.avatarUrl" :name="member.fullName" size="sm" />
                  <div class="members__names">
                    <span class="members__name">
                      {{ member.fullName }}
                      <span v-if="member.isAdmin" class="members__admin-tag">
                        {{ t('admin.members.adminTag') }}
                      </span>
                    </span>
                    <span class="members__email">{{ member.email }}</span>
                  </div>
                </div>
              </td>
              <td class="members__muted">{{ relativeTime(member.createdAt) }}</td>
              <td class="members__num">{{ member.stats.totalBooks }}</td>
              <td class="members__num">{{ member.stats.collections }}</td>
              <td>
                <span class="members__state-pill" :class="`members__state-pill--${memberState(member)}`">
                  {{ t(`admin.members.state.${memberState(member)}`) }}
                </span>
                <p v-if="member.banReason" class="members__reason">{{ member.banReason }}</p>
              </td>
              <td class="members__actions-cell">
                <div class="members__actions">
                <BaseSpinner v-if="busyId === member.id" size="sm" />
                <template v-else-if="actionable(member)">
                  <button
                    v-if="member.bannedAt"
                    class="members__action"
                    type="button"
                    @click="unban(member)"
                  >
                    {{ t('admin.members.unban') }}
                  </button>
                  <button
                    v-else
                    class="members__action"
                    type="button"
                    @click="banTarget = member"
                  >
                    {{ t('admin.members.ban') }}
                  </button>
                  <button
                    class="members__action members__action--danger"
                    type="button"
                    @click="deleteTarget = member"
                  >
                    {{ t('admin.members.delete') }}
                  </button>
                </template>
                <span v-else class="members__locked">{{ lockReason(member) }}</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination
        :page="page"
        :total-pages="totalPages"
        :disabled="loading"
        @change="load"
      />
    </template>

    <BanMemberModal
      :open="!!banTarget"
      :member="banTarget"
      :busy="busyId === banTarget?.id"
      @confirm="confirmBan"
      @close="banTarget = null"
    />

    <DeleteMemberModal
      :open="!!deleteTarget"
      :member="deleteTarget"
      :busy="busyId === deleteTarget?.id"
      @confirm="confirmDelete"
      @close="deleteTarget = null"
    />
  </div>
</template>

<style scoped>
.members {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.members__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}
.members__search { flex: 1 1 260px; max-width: 420px; }
.members__total {
  font-size: var(--text-body-sm);
  color: var(--color-on-surface-variant);
}

.members__scroll {
  overflow-x: auto;
  /* Positioned so the absolutely-positioned .sr-only label in the actions header
     is contained. Without it that span's containing block is the page, and a
     1px element parked at the table's full 900px width makes the whole document
     scroll sideways on a phone — the table scrolls correctly, and the page
     scrolls too. */
  position: relative;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-default);
  background: var(--color-surface);
}
.members__table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  font-size: var(--text-body-sm);
}
/* A page change keeps the old rows on screen while the next one loads, so the
   table doesn't collapse to a spinner and back on every click. */
.members__table--busy { opacity: 0.6; }
.members__table th {
  text-align: left;
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-label-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-on-surface-variant);
  border-bottom: 1px solid var(--color-outline-variant);
  white-space: nowrap;
}
.members__table td {
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-surface-container-high);
  vertical-align: middle;
}
.members__table tbody tr:last-child td { border-bottom: none; }
/* A deleted row is history, not a member — it recedes rather than competing for
   attention with the accounts an operator can still act on. */
.members__row--deleted { opacity: 0.55; }

.members__num { text-align: right; font-variant-numeric: tabular-nums; }
.members__muted { color: var(--color-on-surface-variant); white-space: nowrap; }

.members__identity {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.members__names {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.members__name {
  font-weight: 600;
  color: var(--color-on-surface);
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
}
.members__admin-tag {
  padding: 1px 6px;
  border-radius: var(--radius-full);
  background: var(--color-tertiary);
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}
.members__email {
  color: var(--color-on-surface-variant);
  font-size: var(--text-label-md);
  overflow-wrap: anywhere;
}

.members__state-pill {
  display: inline-block;
  padding: 2px 10px;
  border-radius: var(--radius-full);
  font-size: var(--text-label-sm);
  font-weight: 600;
  white-space: nowrap;
}
.members__state-pill--active {
  background: var(--color-surface-container-high);
  color: var(--color-on-surface-variant);
}
.members__state-pill--banned {
  background: var(--color-tertiary);
  color: #ffffff;
}
.members__state-pill--deleted {
  background: var(--color-error);
  color: #ffffff;
}
.members__reason {
  margin-top: 4px;
  max-width: 26ch;
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

/* The flex row goes on a wrapper, never on the <td> itself: a table cell made
   `display: flex` stops being a table cell, so it drops out of the column
   layout and its content spills past the table's right edge. */
.members__actions-cell { text-align: right; }
.members__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-xs);
  white-space: nowrap;
}
.members__action {
  padding: 5px 12px;
  border: 1px solid var(--color-outline-variant);
  border-radius: var(--radius-full);
  background: var(--color-surface-container-lowest);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.members__action:hover {
  background: var(--color-surface-container-low);
  color: var(--color-on-background);
  border-color: var(--color-outline);
}
.members__action--danger { color: var(--color-error); border-color: var(--color-error); }
.members__action--danger:hover { background: var(--color-error); color: #ffffff; }
.members__locked {
  font-size: var(--text-label-sm);
  color: var(--color-on-surface-variant);
}

.members__loading {
  display: flex;
  justify-content: center;
  padding: var(--space-xl) 0;
  color: var(--color-primary);
}
.members__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xl) var(--space-md);
  text-align: center;
  color: var(--color-on-surface-variant);
}
.members__state-icon { font-size: 40px; opacity: 0.6; }
.members__state-link {
  font-weight: 600;
  color: var(--color-primary);
}

/* Scoped copy, as in BookTable — this is one utility rule, and hoisting it to
   tokens.css for two callers buys less than it costs in indirection. */
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
