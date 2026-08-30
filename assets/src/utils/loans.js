/**
 * One shape for a loan, whichever end of it you are on and whether it covers a
 * single book or a whole collection.
 *
 * The Sharing panel shows every loan in one list with one card, so the two API
 * payloads — `ResponseMapper::request()` and `::collectionRequest()` — have to
 * meet somewhere. They meet here, in plain functions, rather than in the card:
 * a component that sniffs which payload it was handed grows a branch per field,
 * and the two shapes are already near-parallel (`id`, `status`, `requestedAt`,
 * `resolvedAt`, `dueDate`, `returnedAt`, `requester`, `events` — then `book` vs
 * `collection` + `books`).
 */

/** Statuses still in play. Ordered, so a card can say where it sits. */
export const LIVE_STATUSES = ['pending', 'approved', 'return_pending']
/** Statuses that will never change again. */
export const FINISHED_STATUSES = ['declined', 'returned']

/** Filter keys, in the order the pills render. `all` is the default. */
export const LOAN_FILTERS = ['all', 'awaiting', 'onLoan', 'finished']

export function isFinished(loan) {
  return FINISHED_STATUSES.includes(loan.status)
}

/**
 * Normalise one API payload.
 *
 * @param payload     a request from either endpoint
 * @param kind        'book' | 'collection'
 * @param perspective 'borrowing' | 'lending' — decides who the counterpart is
 */
export function toLoan(payload, kind, perspective) {
  const isCollection = kind === 'collection'
  const subject = isCollection ? payload.collection : payload.book

  return {
    // Ids collide across the two tables, so the render key carries the kind.
    key: `${kind}:${payload.id}`,
    id: payload.id,
    kind,
    status: payload.status,
    // Borrowing: the other party is whoever owns the thing. Lending: whoever
    // asked for it.
    counterpart: perspective === 'lending' ? payload.requester : subject?.owner ?? null,
    title: isCollection ? subject?.name : subject?.title,
    author: isCollection ? null : subject?.author,
    // A collection's image field is named differently and may be absent, in
    // which case the card falls back to the first member book's cover.
    coverPath: isCollection ? (subject?.coverUrl ?? payload.books?.[0]?.coverPath ?? null) : subject?.coverPath,
    // Only collections carry members; an empty array keeps the card branchless.
    books: isCollection ? (payload.books ?? []) : [],
    requestedAt: payload.requestedAt,
    resolvedAt: payload.resolvedAt,
    dueDate: payload.dueDate,
    returnedAt: payload.returnedAt,
    events: payload.events ?? [],
  }
}

/** Normalise a whole list. */
export function toLoans(payloads, kind, perspective) {
  return (payloads ?? []).map(p => toLoan(p, kind, perspective))
}

/**
 * When this loan last did anything — the sort key. A request that has been
 * resolved is stamped with `resolvedAt`; one still waiting has only the moment
 * it was made.
 */
export function lastActivityAt(loan) {
  return loan.resolvedAt ?? loan.requestedAt
}

/**
 * How far down the list each state sits. This is a triage order, not a
 * chronology: what is waiting on *you* comes first (a decision to make, then a
 * return to confirm), then the loans quietly running, then the finished tail.
 *
 * Sorting the live loans purely by recency put a loan you had just approved
 * above a request nobody had answered yet — the thing needing attention sank
 * below the thing that needed none.
 *
 * Keeping `finished` last is also load-bearing: it is the only paginated
 * source, so it has to form a contiguous tail for one pager at the bottom of
 * the list to make sense.
 */
const STATUS_RANK = { pending: 0, return_pending: 1, approved: 2, declined: 3, returned: 3 }

export function sortLoans(loans) {
  return [...loans].sort((a, b) => {
    const rankDiff = (STATUS_RANK[a.status] ?? 3) - (STATUS_RANK[b.status] ?? 3)
    if (rankDiff !== 0) return rankDiff
    return Date.parse(lastActivityAt(b)) - Date.parse(lastActivityAt(a))
  })
}

/** Does this loan belong under the given filter pill? */
export function matchesFilter(loan, filter) {
  switch (filter) {
    case 'awaiting':  return loan.status === 'pending'
    case 'onLoan':    return loan.status === 'approved' || loan.status === 'return_pending'
    case 'finished':  return isFinished(loan)
    default:          return true
  }
}
