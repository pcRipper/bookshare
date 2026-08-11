/**
 * Static release notes for FolioShare. Newest version first.
 *
 * This is a hand-maintained, frontend-only list — there is no changelog API.
 * When you ship something notable, prepend a new entry (or add a note to the
 * current unreleased version) here. `date` is an ISO-8601 date string.
 */
export const CHANGELOG = [
  {
    version: '1.22.0',
    date: '2026-08-12',
    notes: [
      'FolioShare now counts page visits, on our own servers. We record how many times each page was opened and roughly how many different people visited each day — there is no third-party tracker, nothing is stored about individual visits, and the identifier used to tell one visitor from another is scrambled fresh every day, so it cannot be used to follow anyone from one day to the next. Pages are counted by name: opening someone\'s profile records "a profile was viewed", never whose.',
      'The site owner has a new private dashboard showing how the community is growing, how much borrowing is happening, which pages people actually visit, and how the shared shelves look overall. It is visible only to an administrator, and nothing about it changes what anyone else sees.',
    ],
  },
  {
    version: '1.21.0',
    date: '2026-08-11',
    notes: [
      'You can now find a book template by its author. Searching Open Library only ever looked at book titles, so an author\'s name found either nothing or a handful of odd box sets that happened to mention them — it now searches properly, and a name like "Sapkowski" brings back their books.',
      'Searching the Ukrainian stores is no longer slow the first time. Every search for something new was quietly abandoned partway through and started over, adding several seconds before any result appeared.',
      'More template results arrive with a cover instead of the placeholder icon, and a Ukrainian result now takes the cover and description from whichever shop listing has them rather than only the first one.',
      'Scrolling template results no longer promises more results and then hands you an empty page at the end of the list.',
      'When an external catalogue is down, the search now says so quickly instead of making every keystroke wait for it.',
    ],
  },
  {
    version: '1.20.1',
    date: '2026-08-09',
    notes: [
      'Searching your library no longer forgets itself. Filter your books, visit another tab and come back, and the search box still shows what you typed instead of leaving you with a filtered list and no explanation.',
      'The "Find a template" search can reach Open Library again — it had been returning no results for every search, whatever you looked for.',
      'Adding a book without a title or author now tells you so where you can see it, right above the buttons, and marks the fields it means.',
      'Book language names on your library shelf are shown in the language you are reading the site in, like everywhere else.',
      'The release notes page now greets signed-out visitors properly, with a way to sign in instead of links they cannot follow.',
      'Building a collection keeps reminding you that two books are needed until you have picked them.',
      'Settings stops offering to save changes you have already saved.',
    ],
  },
  {
    version: '1.20.0',
    date: '2026-08-09',
    notes: [
      'Signing in now lasts a full day. FolioShare used to quietly log you out after an hour and drop you back on the sign-in page, sometimes in the middle of what you were doing.',
      'Dialogs make proper use of a desktop screen. Adding or editing a book puts the cover preview beside the details instead of stacking everything into one narrow column, and building a collection shows the books you have picked next to the ones you can still add.',
      'Book and collection previews are wider, with a larger cover.',
      'On a phone, every dialog gained a little width back from the margins around it.',
    ],
  },
  {
    version: '1.19.0',
    date: '2026-08-08',
    notes: [
      'Changing language no longer means a trip to Settings. The globe button in the header switches FolioShare between English, German, Spanish, French and Ukrainian from wherever you are, on desktop and on your phone.',
      'The same picker sits on the sign-in page, so you can put the site into your language before you sign in — and once you do, that choice is saved to your account.',
      'Your language still follows you: pick it on one device and FolioShare opens in it on the next one you sign in on.',
      'Settings no longer has a Language section — the header button replaces it.',
    ],
  },
  {
    version: '1.18.0',
    date: '2026-08-08',
    notes: [
      'You can now share your library with people who don’t have a FolioShare account. “Share” in your library’s toolbar gives you a link and a QR code — print it, put it on a shelf, or send it to a friend.',
      'The shared page shows your name, photo and bio alongside your books and collections, read-only. Searching, paging and the card/table views all work exactly as they do for members.',
      'Nothing is shared until you allow it: the link follows your existing privacy setting. Keep your profile private and the link simply won’t open — the Share dialog tells you so and points you at the setting.',
      'Details that aren’t yours to publish stay private. Anyone currently borrowing one of your books is never named on the shared page, and neither is your email or location.',
    ],
  },
  {
    version: '1.17.0',
    date: '2026-08-03',
    notes: [
      'FolioShare now speaks five languages: English, German, Spanish, French and Ukrainian. Pick yours under Settings → Language — the whole app changes straight away, and saving keeps the choice on your other devices.',
      'Messages from the server are translated too, so a declined borrow or a private library explains itself in your language rather than falling back to English.',
      'Dates, “3 days ago”-style timestamps and counted labels now follow your language’s own rules, including Ukrainian’s three plural forms.',
      'Book languages are shown in your language as well — a Ukrainian book reads as “Ukrainian”, “Ukrainisch” or “ucraniano” depending on how you’re reading the site, and the language picker is sorted accordingly.',
      'If you haven’t chosen a language yet, FolioShare starts in your browser’s language when we ship it, and English otherwise.',
    ],
  },
  {
    version: '1.16.0',
    date: '2026-08-01',
    notes: [
      'Discover’s Readers tab no longer waits for you to type: it now lists community members, newest first, so you can browse and follow people without knowing their name in advance. Searching still works exactly as before.',
      'CSV exports now carry the cover links you actually provided, instead of FolioShare’s internal image paths. Covers saved before this change export as full links to our copy, so every row stays usable outside the site.',
      'On a phone your library’s tabs now show a shadow at the edge when there are more of them off-screen — Lending, Requests, Following and History no longer look like they’re missing.',
      'A collection that’s out on loan keeps both of its badges readable on a narrow screen, and a book whose cover image can’t be loaded falls back to the placeholder icon instead of showing the image’s description text.',
    ],
  },
  {
    version: '1.15.1',
    date: '2026-08-01',
    notes: [
      'The table view has a new “all columns” switch: expand any list to the full record — categories, description, ISBN, who’s holding the book and when you added it — and collapse it back to the essentials.',
      'Discover’s table now shows who owns each book, so you can tell whose shelf you’re browsing without opening it.',
      'On a phone the table scrolls sideways instead of quietly hiding the language and status columns — nothing is left out any more.',
      'The read column is labelled, and on books that aren’t yours it shows as a plain marker rather than a checkbox you can’t use.',
    ],
  },
  {
    version: '1.15.0',
    date: '2026-08-01',
    notes: [
      'Switch any book list between the cover grid and a new compact table view — a scannable layout showing cover, title, author, language and status at a glance. Your choice is remembered across visits and applies in your Library, on reader profiles and in Discover.',
      'The table view has an inline “read” checkbox: tick your own books as read (or unread) right from the list, without opening each one.',
    ],
  },
  {
    version: '1.14.0',
    date: '2026-08-01',
    notes: [
      'Book covers you add from a template or a pasted link are now saved to FolioShare’s own servers, so they load faster, stay cached and don’t break if the original image host goes down.',
    ],
  },
  {
    version: '1.13.0',
    date: '2026-07-25',
    notes: [
      'Mark a book as read: the Manage Book form now has an “I’ve read this book” checkbox, and a “Read” badge shows on your books wherever they appear — your library, Discover, reader profiles and the book detail view.',
      'The read status travels with your CSV export/import, so it’s preserved when you move your collection around.',
    ],
  },
  {
    version: '1.12.0',
    date: '2026-07-18',
    notes: [
      'You can now add any of your books to a collection, not just the available ones — a book that’s on loan, unavailable or currently being read can be grouped too. Borrowers still only borrow the titles that are available at the time.',
    ],
  },
  {
    version: '1.11.0',
    date: '2026-07-11',
    notes: [
      'Introducing collections — group two or more of your books and lend them together. Create and manage them from the new Collections tab in your Library.',
      'Borrow a whole collection, or just the titles you want: the borrow dialog lets you pick from the set (unavailable books are locked) and needs at least two.',
      'Owners approve, decline and confirm the return of a borrowed collection in one step, with a single due date for the whole set. Collections are clearly marked everywhere so they’re never confused with single books.',
    ],
  },
  {
    version: '1.10.0',
    date: '2026-07-05',
    notes: [
      'Template search now fills in a language even when the source doesn’t provide one — it’s inferred from the title’s script (Ukrainian by default for Cyrillic titles, matching the Ukrainian book stores).',
      'Books on reader profiles now show their language, matching the Library and Discover book cards.',
    ],
  },
  {
    version: '1.9.2',
    date: '2026-07-05',
    notes: [
      'Fixed the “Ukrainian stores” (and Open Library) template search returning no results on the very first try — a transient upstream hiccup is now retried automatically instead of showing an empty list.',
    ],
  },
  {
    version: '1.9.1',
    date: '2026-07-04',
    notes: [
      'The “Catalog a New Book” card now leads your Library collection instead of trailing it, so adding a book is always the first thing you see.',
    ],
  },
  {
    version: '1.9.0',
    date: '2026-07-04',
    notes: [
      'Search your Library collection and any reader’s profile by title, author or ISBN — a simple search box now sits above each book list.',
    ],
  },
  {
    version: '1.8.0',
    date: '2026-07-04',
    notes: [
      'Your own profile page is now a true preview — its book section is read-only, so you can see exactly how your collection looks to other readers.',
      'Clicking one of your books on your profile opens the same read-only overview visitors see, instead of the edit dialog. Add, edit and delete your books from your Library as before.',
    ],
  },
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
