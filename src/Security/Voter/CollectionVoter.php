<?php

namespace App\Security\Voter;

use App\Entity\BookCollection;
use App\Entity\User;
use App\Repository\CollectionRequestRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises mutations on a {@see BookCollection}.
 *
 * Two rules, both enforced server-side:
 *  - You must own the collection.
 *  - It must not be actively borrowed — while a collection request is approved or
 *    return-pending its member books are physically out, so the grouping is frozen
 *    (no edits, no deletes) until it comes back, mirroring {@see BookVoter}.
 *
 * @extends Voter<self::*, BookCollection>
 */
class CollectionVoter extends Voter
{
    public const EDIT = 'COLLECTION_EDIT';
    public const DELETE = 'COLLECTION_DELETE';

    public function __construct(
        private readonly CollectionRequestRepository $collectionRequests,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof BookCollection;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var BookCollection $collection */
        $collection = $subject;

        if ($collection->getOwner() !== $user) {
            return false;
        }

        // A collection that's out on loan is locked until it comes home.
        return !$this->collectionRequests->hasActiveForCollection($collection);
    }
}
