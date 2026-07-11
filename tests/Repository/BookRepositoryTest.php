<?php

namespace App\Tests\Repository;

use App\Dto\Pagination;
use App\Entity\Book;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Repository\BookRepository;

/**
 * Covers the excludeCollectionHeld filter on the owner's paginated book list: a
 * book out on loan as part of a collection borrow must be hideable from the
 * per-book Lending grid (it's shown grouped in the collection card instead).
 */
class BookRepositoryTest extends RepositoryTestCase
{
    private function repo(): BookRepository
    {
        return $this->em->getRepository(Book::class);
    }

    public function testExcludeCollectionHeldHidesOnlyCollectionLentBooks(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        // A book lent individually (approved request, parentRequest = null).
        $solo = $this->makeBook($owner, BookStatus::Lent, $borrower);
        $this->makeRequest($solo, $borrower, RequestStatus::Approved);

        // Two books lent as a collection (approved children, parentRequest set).
        $c1 = $this->makeBook($owner, BookStatus::Lent, $borrower);
        $c2 = $this->makeBook($owner, BookStatus::Lent, $borrower);
        $collection = $this->makeCollection($owner, [$c1, $c2]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Approved);

        $this->em->flush();

        $pagination = new Pagination(1, 100);

        // Without the flag: all three lent books.
        $all = $this->repo()->findByOwnerPaginated($owner, BookStatus::Lent, $pagination);
        self::assertSame(3, $all->total);

        // With the flag: only the individually-lent book survives.
        $filtered = $this->repo()->findByOwnerPaginated($owner, BookStatus::Lent, $pagination, null, true);
        self::assertSame(1, $filtered->total);
        self::assertSame($solo->getId(), $filtered->items[0]->getId());
    }

    public function testExcludeCollectionHeldKeepsReturnedCollectionBooks(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        // A book that WAS in a collection borrow but has been returned is home and
        // available again — a later individual loan of it must not be hidden.
        $book = $this->makeBook($owner, BookStatus::Lent, $borrower);
        $collection = $this->makeCollection($owner, [$book, $this->makeBook($owner)]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Returned);
        // Its current (individual) loan is active.
        $this->makeRequest($book, $borrower, RequestStatus::Approved);

        $this->em->flush();

        $filtered = $this->repo()->findByOwnerPaginated($owner, BookStatus::Lent, new Pagination(1, 100), null, true);

        // Only the finished collection borrow references it → not excluded.
        self::assertContains($book->getId(), array_map(static fn (Book $b) => $b->getId(), $filtered->items));
    }
}
