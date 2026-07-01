/**
 * Static release notes for FolioShare. Newest version first.
 *
 * This is a hand-maintained, frontend-only list — there is no changelog API.
 * When you ship something notable, prepend a new entry (or add a note to the
 * current unreleased version) here. `date` is an ISO-8601 date string.
 */
export const CHANGELOG = [
  {
    version: '1.1.0',
    date: '2026-07-01',
    notes: [
      'Large collections now load page by page: your library, Discover, loan history, and the people you follow all page through results with a numbered pager instead of one endless list.',
      'The book status picker now matches the language picker for a consistent look in the add/edit dialog.',
    ],
  },
  {
    version: '1.0.0',
    date: '2026-06-30',
    notes: [
      'Real-time loan notifications: borrow requests, approvals, declines and returns now push live updates over Server-Sent Events.',
      'Production-grade Docker deployment with optimized two-stage images and a pre-built frontend.',
      'Added a project README and this Release Notes page.',
    ],
  },
  {
    version: '0.4.0',
    date: '2026-06-12',
    notes: [
      'Import and export your collection as CSV from the Library page.',
      'Pick a book’s language from a searchable list; filter Discover by language.',
      'Follow other readers: a Following page shows recent books grouped by the people you subscribe to.',
    ],
  },
  {
    version: '0.3.0',
    date: '2026-05-28',
    notes: [
      'Time-landing due dates: the lending side sets the return date at approval — no back-and-forth with the borrower.',
      'Full borrow-request timeline so both sides can follow a loan through its lifecycle.',
    ],
  },
  {
    version: '0.2.0',
    date: '2026-05-10',
    notes: [
      'Complete lending lifecycle: request, approve, decline, request-return and confirm-return.',
      'Audit trail across books, users, categories and requests.',
      'Rate limiting to keep the API healthy under load.',
    ],
  },
  {
    version: '0.1.0',
    date: '2026-04-22',
    notes: [
      'Initial release: sign in with Google, catalog your books, and browse the community in Discover.',
      'Curated category palette and per-book covers, statuses and details.',
    ],
  },
]
