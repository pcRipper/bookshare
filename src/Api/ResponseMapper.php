<?php

namespace App\Api;

use App\Entity\Book;
use App\Entity\ActivityItem;
use App\Entity\LibraryRequest;
use App\Entity\LibraryRequestEvent;
use App\Entity\Subscription;
use App\Entity\User;
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

    public function book(Book $book): array
    {
        return [
            'id'         => $book->getId(),
            'title'      => $book->getTitle(),
            'author'     => $book->getAuthor(),
            'isbn'       => $book->getIsbn(),
            'coverPath'  => $book->getCoverPath(),
            'status'     => $book->getStatus()->value,
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
