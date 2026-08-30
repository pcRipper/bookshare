<?php

namespace App\Tests\Repository;

use App\Dto\Pagination;
use App\Entity\Book;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Enum\WishPriority;
use App\Repository\BookRepository;

/**
 * Covers the two shelf filters on the owner's paginated book list, both of which
 * are pure DQL and so can only fail against a real database:
 *
 *  - excludeCollectionHeld: a book out on loan as part of a collection borrow
 *    must be hideable from the per-book Lending grid (it's shown grouped in the
 *    collection card instead);
 *  - the wish-list split, including the integer priority ordering, which is the
 *    whole reason WishPriority is int-backed.
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

    public function testTheTwoShelvesNeverMix(): void
    {
        $owner = $this->makeUser();
        $owned = $this->makeBook($owner);
        $wanted = $this->makeBook($owner)->setWish(true, WishPriority::CanWait);

        $this->em->flush();

        $pagination = new Pagination(1, 100);

        $shelf = $this->repo()->findByOwnerPaginated($owner, null, $pagination);
        self::assertSame([$owned->getId()], array_map(static fn (Book $b) => $b->getId(), $shelf->items));

        $list = $this->repo()->findByOwnerPaginated($owner, null, $pagination, wished: true);
        self::assertSame([$wanted->getId()], array_map(static fn (Book $b) => $b->getId(), $list->items));

        // The counters follow the same split.
        self::assertSame(1, $this->repo()->countByOwner($owner));
        self::assertSame(1, $this->repo()->countWishedByOwner($owner));
    }

    public function testTheWishListLeadsWithTheMostWanted(): void
    {
        $owner = $this->makeUser();
        // Created in ascending order of urgency, so a createdAt sort would give
        // the exact opposite of the expected result.
        $canWait = $this->makeBook($owner)->setWish(true, WishPriority::CanWait);
        $keen    = $this->makeBook($owner)->setWish(true, WishPriority::VeryInterested);
        $urgent  = $this->makeBook($owner)->setWish(true, WishPriority::Urgent);

        $this->em->flush();

        $ids = static fn (array $items) => array_map(static fn (Book $b) => $b->getId(), $items);

        $ranked = $this->repo()->findByOwnerPaginated($owner, null, new Pagination(1, 100), wished: true);
        self::assertSame([$urgent->getId(), $keen->getId(), $canWait->getId()], $ids($ranked->items));

        // …and the filter narrows it to a single level.
        $only = $this->repo()->findByOwnerPaginated(
            $owner,
            null,
            new Pagination(1, 100),
            wished: true,
            priority: WishPriority::Urgent,
        );
        self::assertSame([$urgent->getId()], $ids($only->items));
    }

    public function testCommunityWideCountsIgnoreWishListRows(): void
    {
        $owner = $this->makeUser();
        $this->makeBook($owner);
        $this->makeBook($owner)->setWish(true, WishPriority::Urgent);

        $this->em->flush();

        // Analytics reports libraries; the wish list gets its own counters.
        self::assertSame(1, $this->repo()->countAll());
        self::assertSame(1, $this->repo()->countWishedAll());
        self::assertSame(1, $this->repo()->countByStatus()[BookStatus::Own->value]);
        self::assertSame(1, $this->repo()->countByWishPriority()[WishPriority::Urgent->value]);
    }
}
