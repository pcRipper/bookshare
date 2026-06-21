# Techical

## Backend
- Enrich categories color palate
- add path prefixes for all rest controller routes, rename dedicated controllers to have suffix REST, add for all of them this prefix in .yaml level
- add audit tables to the system, currentyle for each table(except for migrations, and all these system tables), skip tables, which by themselfs represnt one time stan and wont change at all or change very rarely(like one field at most)
- implement server sent events

## Security & Tests
- [x] add all possible unit tests
- [x] validate all possible security breaches
- [x] add ratelimiters for ip, user, ip + user


# UI
- [x] add loaders for buttons
- [x] add placeholders of ui elements(glowing or blinking blocks) instead of simple Loading... signs
- [x] add error page and not found page


# Functional
- [x] Time landing: lending side is saying when will be the due date for book return, no approval from requester side needed, its the requirement of the lending side only
