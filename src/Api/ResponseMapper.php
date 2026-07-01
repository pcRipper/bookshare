<?php

namespace App\Api;

use App\Entity\Book;
use App\Entity\ActivityItem;
use App\Entity\LibraryRequest;
use App\Entity\LibraryRequestEvent;
use App\Entity\Subscription;
use App\Entity\User;
use App\Dto\Pagination;
use App\Security\Voter\BookVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Produces the exact JSON shapes the Vue frontend components expect.
 * Centralised so nested shapes (e.g. book inside request) stay consistent.
 */
class ResponseMapper
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authChecker,
    ) {}

    /**
     * Wraps one page of raw items in the standard list envelope, mapping each
     * item through $mapItem. Every paginated endpoint returns this shape:
     *
     *   { items: [...], pagination: { page, perPage, total, totalPages, hasMore } }
     *
     * @param array<int, mixed> $items    the current page's un-mapped items
     * @param int               $total    total matching rows across all pages
     * @param callable          $mapItem  fn(mixed): array — shapes one item
     */
    public function paginated(array $items, int $total, Pagination $pagination, callable $mapItem): array
    {
        $perPage = $pagination->perPage;

        return [
            'items'      => array_values(array_map($mapItem, $items)),
            'pagination' => [
                'page'       => $pagination->page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
                'hasMore'    => ($pagination->offset() + count($items)) < $total,
            ],
        ];
    }

    public function book(Book $book): array
    {
        return [
            'id'         => $book->getId(),
            'title'      => $book->getTitle(),
            'author'     => $book->getAuthor(),
            'description'=> $book->getDescription(),
            'isbn'       => $book->getIsbn(),
            'coverPath'  => $book->getCoverPath(),
            'status'     => $book->getStatus()->value,
            // Language as both the stored ISO code and its display name (null when unset).
            'language'     => $book->getLanguage(),
            'languageName' => \App\Language\LanguageCatalog::name($book->getLanguage()),
            // Who currently holds the book — owner while home, borrower while lent.
            'currentHolder' => $this->userSummary($book->getCurrentHolder()),
            'isHome'        => $book->isHome(),
            // Server-authoritative: may the current viewer edit this book? False
            // for books they don't own and for their own books that are on loan.
            'canEdit'       => $this->authChecker->isGranted(BookVoter::EDIT, $book),
            'categories' => array_map(
                fn ($c) => ['id' => $c->getId(), 'name' => $c->getName(), 'colorHex' => $c->getColorHex()],
                $book->getCategories()->toArray(),
            ),
        ];
    }

    /** @param Book[] $books */
    public function books(array $books): array
    {
        return array_map(fn (Book $b) => $this->book($b), $books);
    }

    /**
     * Book shape for Discover: the standard book plus its owner, since browsing
     * the community is fundamentally about *whose* book you could borrow.
     */
    public function discoverBook(Book $book): array
    {
        return $this->book($book) + ['owner' => $this->userSummary($book->getOwner())];
    }

    /**
     * A book template for the "Add New Book" search: only the copyable
     * bibliographic fields, plus the resolved language label. No owner, id or
     * lending state — a template is metadata to seed a new book, not a resource.
     */
    public function bookTemplate(\App\Dto\BookTemplate $template): array
    {
        return [
            'title'        => $template->title,
            'author'       => $template->author,
            'description'  => $template->description,
            'isbn'         => $template->isbn,
            'coverPath'    => $template->coverPath,
            'language'     => $template->language,
            'languageName' => \App\Language\LanguageCatalog::name($template->language),
        ];
    }

    /** Compact user shape for nesting in other payloads. */
    public function userSummary(User $user): array
    {
        return [
            'id'        => $user->getId(),
            'fullName'  => $user->getFullName(),
            'avatarUrl' => $user->getAvatarUrl(),
        ];
    }

    public function request(LibraryRequest $request): array
    {
        $book = $request->getBook();

        return [
            'id'          => $request->getId(),
            'status'      => $request->getStatus()->value,
            'requestedAt' => $request->getRequestedAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt'  => $request->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'dueDate'     => $request->getDueDate()?->format(\DateTimeInterface::ATOM),
            'returnedAt'  => $request->getReturnedAt()?->format(\DateTimeInterface::ATOM),
            // Full ordered lifecycle so the UI can render the step-by-step history.
            'events'      => array_map(
                fn (LibraryRequestEvent $e) => $this->requestEvent($e),
                $request->getEvents()->toArray(),
            ),
            'requester'   => $this->userSummary($request->getRequester()),
            'book'        => [
                'id'        => $book->getId(),
                'title'     => $book->getTitle(),
                'author'    => $book->getAuthor(),
                'coverPath' => $book->getCoverPath(),
                // The book's owner — the lender, shown on the borrower's outgoing list.
                'owner'     => $this->userSummary($book->getOwner()),
            ],
        ];
    }

    /** @param LibraryRequest[] $requests */
    public function requests(array $requests): array
    {
        return array_map(fn (LibraryRequest $r) => $this->request($r), $requests);
    }

    public function requestEvent(LibraryRequestEvent $event): array
    {
        return [
            'id'        => $event->getId(),
            'type'      => $event->getType()->value,
            'createdAt' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'dueDate'   => $event->getDueDate()?->format(\DateTimeInterface::ATOM),
            'message'   => $event->getMessage(),
            'actor'     => $this->userSummary($event->getActor()),
        ];
    }

    public function me(User $user, array $stats): array
    {
        return [
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName(),
            'avatarUrl' => $user->getAvatarUrl(),
            'bio'       => $user->getBio(),
            'location'  => $user->getLocation(),
            'isPrivate' => $user->isPrivate(),
            'stats'     => $stats,
        ];
    }

    /**
     * Public profile payload: just the user resource — identity, stats and the
     * viewer-relative `isSelf` flag. The owner's books are a separate resource,
     * fetched via `GET /api/books?owner={id}`.
     *
     * @param array{totalBooks:int, shared:int, loaned:int} $stats
     * @param bool $showLocation Whether the viewer may see the location; the
     *   owner always sees their own, others honour the user's `showLocation` pref.
     */
    public function profile(User $user, array $stats, bool $isSelf, bool $showLocation = true, bool $isSubscribed = false): array
    {
        return [
            'id'           => $user->getId(),
            'fullName'     => $user->getFullName(),
            'avatarUrl'    => $user->getAvatarUrl(),
            'bio'          => $user->getBio(),
            'location'     => $showLocation ? $user->getLocation() : null,
            'isSelf'       => $isSelf,
            // Whether the viewer currently follows this reader (false on own profile).
            'isSubscribed' => $isSubscribed,
            'stats'        => $stats,
        ];
    }

    /**
     * Compact user card for the Discover "Accounts" search results: identity,
     * a bio snippet, stats and the viewer's follow state. No location — it isn't
     * shown on the card and respects the user's location-privacy preference.
     *
     * @param array{totalBooks:int, shared:int, loaned:int} $stats
     */
    public function userCard(User $user, array $stats, bool $isSubscribed): array
    {
        return [
            'id'           => $user->getId(),
            'fullName'     => $user->getFullName(),
            'avatarUrl'    => $user->getAvatarUrl(),
            'bio'          => $user->getBio(),
            'stats'        => $stats,
            'isSubscribed' => $isSubscribed,
        ];
    }

    public function settings(\App\Entity\UserSettings $settings): array
    {
        return [
            'allowRequests'        => $settings->allowsRequests(),
            'showLocation'         => $settings->showsLocation(),
            'notifyBorrowRequests' => $settings->notifiesBorrowRequests(),
            'notifyRequestUpdates' => $settings->notifiesRequestUpdates(),
            'notifyActivity'       => $settings->notifiesActivity(),
            'notifyNewsletter'     => $settings->notifiesNewsletter(),
        ];
    }

    public function category(\App\Entity\Category $category): array
    {
        return [
            'id'       => $category->getId(),
            'name'     => $category->getName(),
            'colorHex' => $category->getColorHex(),
        ];
    }

    /** A single "following" row: the edge id plus the followed reader. */
    public function subscription(Subscription $subscription): array
    {
        return [
            'id'        => $subscription->getId(),
            'createdAt' => $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user'      => $this->userSummary($subscription->getSubscribedTo()),
        ];
    }

    /**
     * One feed group: a followed reader and their recent books.
     *
     * @param Book[]            $books
     * @param array<int, mixed> $pendingBookIds Lookup (book id => any) of books the
     *   viewer already has a pending request for, so the CTA shows "Requested".
     */
    public function subscriptionFeed(User $user, array $books, array $pendingBookIds = []): array
    {
        return [
            'user'  => $this->userSummary($user),
            // Discover shape (book + owner) so the feed reuses DiscoverBookCard,
            // whose "Request to Borrow" CTA and owner link work unchanged.
            'books' => array_map(
                fn (Book $b) => $this->discoverBook($b) + ['requested' => isset($pendingBookIds[$b->getId()])],
                $books,
            ),
        ];
    }

    public function activity(ActivityItem $item): array
    {
        $book = $item->getTargetBook();
        $targetUser = $item->getTargetUser();

        return [
            'id'         => $item->getId(),
            'actionType' => $item->getActionType()->value,
            'createdAt'  => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'commentText'=> $item->getCommentText(),
            'actor'      => $this->userSummary($item->getActor()),
            'targetBook' => $book ? [
                'id'        => $book->getId(),
                'title'     => $book->getTitle(),
                'author'    => $book->getAuthor(),
                'coverPath' => $book->getCoverPath(),
            ] : null,
            'targetUser' => $targetUser ? $this->userSummary($targetUser) : null,
        ];
    }
}
