<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;

/**
 * Derives the public-facing stat counters for a user's profile. Shared by the
 * "me" endpoint and the public profile endpoint so both report identical shapes.
 */
class UserStatsProvider
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CollectionRepository $collections,
    ) {}

    /** @return array{totalBooks:int, shared:int, loaned:int, collections:int, wished:int} */
    public function forUser(User $user): array
    {
        return [
            'totalBooks'  => $this->books->countByOwner($user),
            'shared'      => $this->books->countShareableByOwner($user),
            'loaned'      => $this->books->countByOwnerAndStatus($user, BookStatus::Lent),
            'collections' => $this->collections->countByOwner($user),
            // Sizes the Wish List tab's counter. Not shown as a headline stat —
            // the three above are about what a reader shares with the community,
            // and a wish list is about what they don't have.
            'wished'      => $this->books->countWishedByOwner($user),
        ];
    }

    /**
     * The same counters for a page of users, in four grouped queries instead of
     * four per user. Used by the Discover "Accounts" list, which now renders a
     * full page of reader cards on every visit rather than only after a search.
     *
     * @param  User[] $users
     * @return array<int, array{totalBooks:int, shared:int, loaned:int, collections:int, wished:int}> keyed by user id
     */
    public function forUsers(array $users): array
    {
        $totals      = $this->books->countByOwners($users);
        $shared      = $this->books->countShareableByOwners($users);
        $loaned      = $this->books->countByOwnersAndStatus($users, BookStatus::Lent);
        $collections = $this->collections->countByOwners($users);
        // Grouped like the rest rather than skipped, so a page of reader cards
        // and a single profile report the same shape.
        $wished      = $this->books->countWishedByOwners($users);

        $stats = [];
        foreach ($users as $user) {
            $id = $user->getId();
            $stats[$id] = [
                'totalBooks'  => $totals[$id] ?? 0,
                'shared'      => $shared[$id] ?? 0,
                'loaned'      => $loaned[$id] ?? 0,
                'collections' => $collections[$id] ?? 0,
                'wished'      => $wished[$id] ?? 0,
            ];
        }

        return $stats;
    }
}
