# Techical

## Backend
- [x] Enrich categories color palate
- [x] add path prefixes for all rest controller routes, rename dedicated controllers to have suffix REST, add for all of them this prefix in .yaml level
- [x] add audit tables to the system, currentyle for each table(except for migrations, and all these system tables), skip tables, which by themselfs represnt one time stan and wont change at all or change very rarely(like one field at most)
- [ ] implement server sent events

## Security & Tests
- [x] add all possible unit tests
- [x] validate all possible security breaches
- [x] add ratelimiters for ip, user, ip + user


# UI
- [x] add loaders for buttons
- [x] add placeholders of ui elements(glowing or blinking blocks) instead of simple Loading... signs
- [x] add error page and not found page
- [x] Improve mobile layout, now it has horizontal scroll because of shifted elements. Revise positioning for mobile version and overall improve look of it


# Functional
- [x] Time landing: lending side is saying when will be the due date for book return, no approval from requester side needed, its the requirement of the lending side only
- Add subscription page and functional of subscriptions. It should be instea of activity page/button. It should look like this: rows with recent books, grouppe by subscribed person, 10-15 books max, as a scrollable horizontal list. Subscriptions list is available at library page, there you can cancel subscription and view people you are subscribed on.


# Deployment
- add script to install all needed dependenices on droplet
- droplet will be running prod-grade docker containers, they should be run from optimized two-stage images, cause memory on VHS is very low
- frontend will be shipped built
