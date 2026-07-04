/**
 * Static release notes for FolioShare. Newest version first.
 *
 * This is a hand-maintained, frontend-only list — there is no changelog API.
 * When you ship something notable, prepend a new entry (or add a note to the
 * current unreleased version) here. `date` is an ISO-8601 date string.
 */
export const CHANGELOG = [
  {
    version: '1.7.1',
    date: '2026-07-04',
    notes: [
      'Fixed template search sometimes showing no results while typing quickly in an external source — matches now appear reliably without switching the source.',
      'External template searches now wait a little longer after you stop typing and need at least 3 characters, so they no longer fire a burst of throwaway lookups while you type.',
    ],
  },
  {
    version: '1.7.0',
    date: '2026-07-04',
    notes: [
      'Template search results now load as you scroll — Open Library and Ukrainian stores no longer stop at a fixed number of matches.',
      'External results are cached longer, so scrolling back through pages and repeating searches is instant.',
    ],
  },
  {
    version: '1.6.0',
    date: '2026-07-04',
    notes: [
      'The “Find a template” tab can now search Ukrainian bookstores (bookfinder.com.ua) — handy for editions Open Library doesn’t list.',
      'Pick where to search from a new source dropdown: this site, Open Library, or Ukrainian stores.',
      'Template search now shows many more matches to scroll through, so the book you want is less likely to be cut off.',
    ],
  },
  {
    version: '1.5.0',
    date: '2026-07-01',
    notes: [
      'Click any book in Discover, your Following feed, or another reader’s profile to open a detailed overview — a large cover, its categories, language and the full description, laid out to read top to bottom (long blurbs are no longer cut off).',
      'Borrow straight from the overview: the “Request to Borrow” button lives right in the modal.',
      'The old hover-to-peek description overlay has been retired in favour of this clearer view.',
    ],
  },
  {
    version: '1.4.0',
    date: '2026-07-01',
    notes: [
      'Books now have a description. Add one in the Add/Edit dialog, and it comes along automatically when you fill a book from a template or an Open Library match.',
      'See a book’s description right on its cover — hover on desktop, or tap the info button on mobile — across your library, Discover and profiles.',
      'CSV export and import now include the description column.',
    ],
  },
  {
    version: '1.3.0',
    date: '2026-07-01',
    notes: [
      'The “Find a template” tab can now search Open Library: switch to “External sources” to look up books by title or ISBN from the world’s open catalogue and fill your new book in one click.',
      'Covers, authors and languages come through automatically when a match is found.',
      'External searches are now cached, so repeating a popular title or ISBN returns instantly.',
    ],
  },
  {
    version: '1.2.0',
    date: '2026-07-01',
    notes: [
      'Add a book faster: the “Add New Book” dialog now has a “Find a template” tab that searches existing books by title or ISBN and fills the form for you — just tweak and save.',
      'Switch the template search between books already on the site and external sources (external lookup is coming soon).',
    ],
  },
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
