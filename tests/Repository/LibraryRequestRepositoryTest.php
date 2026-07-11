<?php

namespace App\Tests\Repository;

use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;

/**
 * The individual request lists must EXCLUDE collection children (so a collection
 * borrow surfaces grouped, not duplicated), while the pending-book lookups must
 * still SEE them (so collection-borrowed books stay locked). These are pure DQL
 * behaviours with no unit-level coverage — exactly where a regression would hide.
 */
class LibraryRequestRepositoryTest extends RepositoryTestCase
{
    private function repo(): LibraryRequestRepository
    {
        return $this->em->getRepository(\App\Entity\LibraryRequest::class);
    }

    public function testIndividualIncomingExcludesCollectionChildren(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        // One individual request + a collection borrow, all for the owner's books.
        $solo = $this->makeBook($owner);
        $this->makeRequest($solo, $borrower, RequestStatus::Pending);

        $collection = $this->makeCollection($owner, [$this->makeBook($owner), $this->makeBook($owner)]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);

        $this->em->flush();

        $incoming = $this->repo()->findIncoming($owner, [RequestStatus::Pending]);

        // Only the individual request — the two collection children are excluded.
        self::assertCount(1, $incoming);
        self::assertSame($solo->getId(), $incoming[0]->getBook()->getId());
    }

    public function testIndividualOutgoingExcludesCollectionChildren(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        $solo = $this->makeBook($owner);
        $this->makeRequest($solo, $borrower, RequestStatus::Pending);

        $collection = $this->makeCollection($owner, [$this->makeBook($owner), $this->makeBook($owner)]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);

        $this->em->flush();

        $outgoing = $this->repo()->findOutgoing($borrower, [RequestStatus::Pending]);

        self::assertCount(1, $outgoing);
        self::assertSame($solo->getId(), $outgoing[0]->getBook()->getId());
    }

    public function testPendingBookLookupStillSeesCollectionChildren(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        $b1 = $this->makeBook($owner);
        $b2 = $this->makeBook($owner);
        $collection = $this->makeCollection($owner, [$b1, $b2]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Pending);

        $this->em->flush();

        // The lock lookup must include collection-borrowed books so they can't be
        // individually re-requested while pending in a collection.
        $pending = $this->repo()->findPendingBookIdsForRequester($borrower);

        self::assertContains($b1->getId(), $pending);
        self::assertContains($b2->getId(), $pending);
    }
}
