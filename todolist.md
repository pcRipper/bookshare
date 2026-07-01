# Techical

## Backend
- [x] Enrich categories color palate
- [x] add path prefixes for all rest controller routes, rename dedicated controllers to have suffix REST, add for all of them this prefix in .yaml level
- [x] add audit tables to the system, currentyle for each table(except for migrations, and all these system tables), skip tables, which by themselfs represnt one time stan and wont change at all or change very rarely(like one field at most)
- [skip for now] implement server sent events
- [x] introduce versions / changelog (release notes) page — static list, surfaced via footer link (see Functional)
- add caching for all images at nginx(if possible)
- improve overall caching and optimizations
- [x] paginate browse/growing list endpoints (collection, discover books/accounts, loan history, following) behind a shared { items, pagination } envelope; in-flight lists stay bare arrays

## Security & Tests
- [x] add all possible unit tests
- [x] validate all possible security breaches
- [x] add ratelimiters for ip, user, ip + user


# UI
- [x] numbered pagination control (shared ui/Pagination.vue) across the paginated grids/lists
- [x] add loaders for buttons
- [x] add placeholders of ui elements(glowing or blinking blocks) instead of simple Loading... signs
- [x] add error page and not found page
- [x] Improve mobile layout, now it has horizontal scroll because of shifted elements. Revise positioning for mobile version and overall improve look of it


# Functional
- [x] Time landing: lending side is saying when will be the due date for book return, no approval from requester side needed, its the requirement of the lending side only
- [x] Add subscription page and functional of subscriptions. It should be instea of activity page/button. It should look like this: rows with recent books, grouppe by subscribed person, 10-15 books max, as a scrollable horizontal list. Subscriptions list is available at library page, there you can cancel subscription and view people you are subscribed on.
- [x] add language select for the book
- [x] add export / import for the book list
- [x] introduce functional of versions and change logs notes page(let it be just static list), it should be both in technical list and functional
- [x] add decline borrow request from borrower side(take validations in account: if borrow request was already approved - reject current action)
- [x] output on requests tab(my library page) both lending and borrowing requests
- [x] add optional message while declining borrowing request, right on card with borrowing
- [skip for now] add API support to fill book from template


# Deployment
- [x] add script to install all needed dependenices on droplet(like docker to start up, git to pull a branch etc)
- [x] droplet will be running prod-grade docker containers, they should be run from optimized two-stage images, cause memory on VHS is very low
- [x] frontend will be shipped built
- [x] docker folder should be split on local/production folders
- [x] separate compose file for production images
- [x] no debug or profiling for production, everything optimized
- [x] no need for mailpit or grafana in production
