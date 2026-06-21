<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;

/**
 * Derives the public-facing stat counters for a user's profile. Shared by the
 * "me" endpoint and the public profile endpoint so both report identical shapes.
 */
class UserStatsProvider
{
    public function __construct(private readonly BookRepository $books) {}

    /** @return array{totalBooks:int, shared:int, loaned:int} */
    public function forUser(User $user): array
    {
        return [
            'totalBooks' => $this->books->countByOwner($user),
            'shared'     => $this->books->countShareableByOwner($user),
            'loaned'     => $this->books->countByOwnerAndStatus($user, BookStatus::Lent),
        ];
    }
}
