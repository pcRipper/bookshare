<?php

namespace App\Security\Voter;

use App\Entity\Book;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises mutations on a {@see Book}.
 *
 * Two rules, both enforced server-side:
 *  - You must own the book.
 *  - The book must be *home* — i.e. `owner === currentHolder`. While it's out on
 *    loan (the holder differs from the owner) it's physically in someone else's
 *    hands, so the catalogue entry is frozen: no edits, no deletes, until it's
 *    returned. This keeps the record consistent with the copy a borrower holds.
 *
 * @extends Voter<self::*, Book>
 */
class BookVoter extends Voter
{
    public const EDIT = 'BOOK_EDIT';
    public const DELETE = 'BOOK_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof Book;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Book $book */
        $book = $subject;

        // Only the owner may ever mutate the book.
        if ($book->getOwner() !== $user) {
            return false;
        }

        // A book that's out on loan is locked until it comes home.
        return $book->isHome();
    }
}
