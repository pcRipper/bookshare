<?php

namespace App\Tests\Repository;

use App\Entity\CollectionRequest;
use App\Enum\RequestStatus;
use App\Repository\CollectionRequestRepository;

/**
 * The grouped collection-request lists and the active-loan guard. findOutgoing in
 * particular once shipped with an unbound :statuses parameter that made every
 * borrower-side query throw — a DB-level bug no unit test could catch.
 */
class CollectionRequestRepositoryTest extends RepositoryTestCase
{
    private function repo(): CollectionRequestRepository
    {
        return $this->em->getRepository(CollectionRequest::class);
    }

    public function testFindOutgoingReturnsBorrowerRequestsFilteredByStatus(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $collection = $this->makeCollection($owner, [$this->makeBook($owner), $this->makeBook($owner)]);
        $parent = $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);
        $this->em->flush();

        $pending = $this->repo()->findOutgoing($borrower, [RequestStatus::Pending]);
        self::assertCount(1, $pending);
        self::assertSame($parent->getId(), $pending[0]->getId());
        // Children are hydrated for the card.
        self::assertCount(2, $pending[0]->getChildren());

        // A non-matching status returns nothing (proves :statuses is actually bound).
        self::assertCount(0, $this->repo()->findOutgoing($borrower, [RequestStatus::Approved]));
    }

    public function testFindIncomingReturnsOwnerRequestsFilteredByStatus(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $collection = $this->makeCollection($owner, [$this->makeBook($owner), $this->makeBook($owner)]);
        $parent = $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);
        $this->em->flush();

        $incoming = $this->repo()->findIncoming($owner, [RequestStatus::Pending]);
        self::assertCount(1, $incoming);
        self::assertSame($parent->getId(), $incoming[0]->getId());

        self::assertCount(0, $this->repo()->findIncoming($owner, [RequestStatus::Returned]));
    }

    public function testHasActiveForCollectionReflectsLiveBorrows(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $collection = $this->makeCollection($owner, [$this->makeBook($owner), $this->makeBook($owner)]);

        $pending = $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);
        $this->em->flush();
        // Pending is not "active" — the collection is still free to edit/delete.
        self::assertFalse($this->repo()->hasActiveForCollection($collection));

        $pending->setStatus(RequestStatus::Approved);
        $this->em->flush();
        self::assertTrue($this->repo()->hasActiveForCollection($collection));
    }
}
