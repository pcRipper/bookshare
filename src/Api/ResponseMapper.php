<?php

namespace App\Api;

use App\Entity\Book;
use App\Entity\ActivityItem;
use App\Entity\LibraryRequest;
use App\Entity\User;

/**
 * Produces the exact JSON shapes the Vue frontend components expect.
 * Centralised so nested shapes (e.g. book inside request) stay consistent.
 */
class ResponseMapper
{
    public function book(Book $book): array
    {
        return [
            'id'         => $book->getId(),
            'title'      => $book->getTitle(),
            'author'     => $book->getAuthor(),
            'isbn'       => $book->getIsbn(),
            'coverPath'  => $book->getCoverPath(),
            'status'     => $book->getStatus()->value,
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
     */
    public function profile(User $user, array $stats, bool $isSelf): array
    {
        return [
            'id'        => $user->getId(),
            'fullName'  => $user->getFullName(),
            'avatarUrl' => $user->getAvatarUrl(),
            'bio'       => $user->getBio(),
            'location'  => $user->getLocation(),
            'isSelf'    => $isSelf,
            'stats'     => $stats,
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
