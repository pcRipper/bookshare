<?php

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\Category;
use App\Entity\LibraryRequest;
use App\Entity\LibraryRequestEvent;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use App\Repository\BookRepository;
use App\Repository\LibraryRequestEventRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\UserRepository;

/**
 * The dashboard's aggregate queries, against a real database.
 *
 * Nothing here is visible to a unit test. DATE_TRUNC is a custom DQL function
 * registered in doctrine.yaml — if the registration is wrong or the function
 * doesn't exist on the platform, the only symptom is a query that throws at
 * runtime. Enum columns come back as raw backing values through
 * getScalarResult() rather than as enum instances, and IDENTITY() projections
 * likewise only behave under a real driver.
 */
class AnalyticsAggregateTest extends RepositoryTestCase
{
    private function books(): BookRepository
    {
        return $this->em->getRepository(Book::class);
    }

    private function users(): UserRepository
    {
        return $this->em->getRepository(User::class);
    }

    private function requests(): LibraryRequestRepository
    {
        return $this->em->getRepository(LibraryRequest::class);
    }

    private function events(): LibraryRequestEventRepository
    {
        return $this->em->getRepository(LibraryRequestEvent::class);
    }

    /** Entities stamp createdAt in their constructor, so back-date by reflection. */
    private function backdate(object $entity, string $when): void
    {
        new \ReflectionProperty($entity::class, 'createdAt')
            ->setValue($entity, new \DateTimeImmutable($when));
    }

    /* ── growth series (DATE_TRUNC) ───────────────────────────────────────── */

    public function testCreatedByDayBucketsRowsOntoCalendarDays(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeUser();
        $this->backdate($a, '2026-08-10 09:00:00');
        $this->backdate($b, '2026-08-10 23:30:00');
        $this->backdate($c, '2026-08-11 01:00:00');
        $this->em->flush();

        $byDay = $this->users()->countCreatedByDay(new \DateTimeImmutable('2026-08-01'));

        // Two rows nine hours apart on the same date collapse into one bucket;
        // ninety minutes apart across midnight do not.
        self::assertSame(2, $byDay['2026-08-10'] ?? 0);
        self::assertSame(1, $byDay['2026-08-11'] ?? 0);
    }

    public function testCreatedByDayExcludesRowsBeforeTheWindow(): void
    {
        $old = $this->makeUser();
        $this->backdate($old, '2020-01-01 12:00:00');
        $this->em->flush();

        $byDay = $this->users()->countCreatedByDay(new \DateTimeImmutable('2026-08-01'));

        self::assertArrayNotHasKey('2020-01-01', $byDay);
    }

    public function testCreatedByDayWorksForBooksAndCollections(): void
    {
        $owner = $this->makeUser();
        $one = $this->makeBook($owner);
        $two = $this->makeBook($owner);
        $collection = $this->makeCollection($owner, [$one, $two]);
        $this->backdate($one, '2026-08-11 10:00:00');
        $this->backdate($two, '2026-08-11 11:00:00');
        $this->backdate($collection, '2026-08-11 12:00:00');
        $this->em->flush();

        $since = new \DateTimeImmutable('2026-08-01');

        self::assertSame(2, $this->books()->countCreatedByDay($since)['2026-08-11'] ?? 0);
        self::assertSame(
            1,
            $this->em->getRepository(BookCollection::class)->countCreatedByDay($since)['2026-08-11'] ?? 0,
        );
    }

    /* ── library health ───────────────────────────────────────────────────── */

    public function testCountByStatusReturnsEveryCaseZeroFilled(): void
    {
        $owner = $this->makeUser();
        $this->makeBook($owner, BookStatus::Own);
        $this->makeBook($owner, BookStatus::Own);
        $this->makeBook($owner, BookStatus::CurrentlyReading);
        $this->em->flush();

        $counts = $this->books()->countByStatus();

        foreach (BookStatus::cases() as $case) {
            self::assertArrayHasKey($case->value, $counts, 'every status must be present');
        }
        self::assertSame(2, $counts[BookStatus::Own->value]);
        self::assertSame(1, $counts[BookStatus::CurrentlyReading->value]);
        self::assertSame(0, $counts[BookStatus::Lent->value]);
    }

    public function testCountByLanguageExcludesBooksWithNoLanguage(): void
    {
        $owner = $this->makeUser();
        $this->makeBook($owner)->setLanguage('uk');
        $this->makeBook($owner)->setLanguage('uk');
        $this->makeBook($owner)->setLanguage('en');
        $this->makeBook($owner); // no language set
        $this->em->flush();

        $rows = $this->books()->countByLanguage(10);
        $byCode = array_column($rows, 'books', 'code');

        self::assertSame(2, $byCode['uk']);
        self::assertSame(1, $byCode['en']);
        self::assertArrayNotHasKey('', $byCode);
        self::assertCount(2, $rows);
        // Ordered most-used first.
        self::assertSame('uk', $rows[0]['code']);
    }

    /** Book→Category is unidirectional, so this must query from the owning side. */
    public function testCountByCategoryCountsFromTheOwningSide(): void
    {
        $owner = $this->makeUser();
        $fantasy = (new Category())->setName('Fantasy ' . uniqid())->setColorHex('#E8F0EA');
        $poetry = (new Category())->setName('Poetry ' . uniqid())->setColorHex('#E8F0EA');
        $this->em->persist($fantasy);
        $this->em->persist($poetry);

        $this->makeBook($owner)->addCategory($fantasy);
        $this->makeBook($owner)->addCategory($fantasy);
        $this->makeBook($owner)->addCategory($poetry);
        $this->em->flush();

        $rows = $this->books()->countByCategory(10);
        $byName = array_column($rows, 'books', 'name');

        self::assertSame(2, $byName[$fantasy->getName()]);
        self::assertSame(1, $byName[$poetry->getName()]);
        self::assertSame($fantasy->getName(), $rows[0]['name']);
        self::assertArrayHasKey('colorHex', $rows[0]);
    }

    public function testCountByCategoryHonoursItsLimit(): void
    {
        $owner = $this->makeUser();
        foreach (range(1, 4) as $n) {
            $category = (new Category())->setName("Cat {$n} " . uniqid())->setColorHex('#E8F0EA');
            $this->em->persist($category);
            $this->makeBook($owner)->addCategory($category);
        }
        $this->em->flush();

        self::assertCount(2, $this->books()->countByCategory(2));
    }

    /* ── loan rankings ────────────────────────────────────────────────────── */

    public function testMostBorrowedCountsOnlyRequestsThatBecameLoans(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $lent = $this->makeBook($owner);
        $neverLent = $this->makeBook($owner);

        $this->makeRequest($lent, $borrower, RequestStatus::Returned);
        $this->makeRequest($lent, $borrower, RequestStatus::Approved);
        // Neither of these is a loan.
        $this->makeRequest($neverLent, $borrower, RequestStatus::Pending);
        $this->makeRequest($neverLent, $borrower, RequestStatus::Declined);
        $this->em->flush();

        $counts = $this->requests()->mostBorrowedBookIds(10);

        self::assertSame(2, $counts[$lent->getId()]);
        self::assertArrayNotHasKey($neverLent->getId(), $counts);
    }

    /**
     * A collection borrow fans out into one child request per book, and each of
     * those really is a loan — so the rankings count them, unlike the inbox list
     * queries which exclude children to avoid duplicating one action.
     */
    public function testCollectionChildrenCountTowardsTheRankings(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $one = $this->makeBook($owner);
        $two = $this->makeBook($owner);
        $collection = $this->makeCollection($owner, [$one, $two]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Approved);
        $this->em->flush();

        $counts = $this->requests()->mostBorrowedBookIds(10);

        self::assertSame(1, $counts[$one->getId()] ?? 0);
        self::assertSame(1, $counts[$two->getId()] ?? 0);
        self::assertSame(2, $this->requests()->topLenderIds(10)[$owner->getId()] ?? 0);
    }

    public function testTopLendersRanksByLoansOfTheirOwnBooks(): void
    {
        $busy = $this->makeUser();
        $quiet = $this->makeUser();
        $borrower = $this->makeUser();

        $this->makeRequest($this->makeBook($busy), $borrower, RequestStatus::Returned);
        $this->makeRequest($this->makeBook($busy), $borrower, RequestStatus::Returned);
        $this->makeRequest($this->makeBook($quiet), $borrower, RequestStatus::Returned);
        $this->em->flush();

        $counts = $this->requests()->topLenderIds(10);

        self::assertSame(2, $counts[$busy->getId()]);
        self::assertSame(1, $counts[$quiet->getId()]);
        self::assertSame($busy->getId(), array_key_first($counts));
    }

    /* ── loan activity series ─────────────────────────────────────────────── */

    public function testEventsAreCountedByTypeAndDay(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $request = $this->makeRequest($this->makeBook($owner), $borrower, RequestStatus::Returned);

        foreach ([
            [LibraryRequestEventType::Requested, '2026-08-10 09:00:00'],
            [LibraryRequestEventType::Requested, '2026-08-10 17:00:00'],
            [LibraryRequestEventType::Approved, '2026-08-10 18:00:00'],
            [LibraryRequestEventType::Returned, '2026-08-11 08:00:00'],
        ] as [$type, $when]) {
            $event = (new LibraryRequestEvent())
                ->setRequest($request)
                ->setActor($owner)
                ->setType($type);
            $this->backdate($event, $when);
            $this->em->persist($event);
        }
        $this->em->flush();

        $series = $this->events()->countByTypeAndDay(new \DateTimeImmutable('2026-08-01'));

        self::assertSame(2, $series['requested']['2026-08-10'] ?? 0);
        self::assertSame(1, $series['approved']['2026-08-10'] ?? 0);
        self::assertSame(1, $series['returned']['2026-08-11'] ?? 0);
        self::assertSame(0, $series['returned']['2026-08-10'] ?? 0);
    }

    /* ── activity feed ────────────────────────────────────────────────────── */

    public function testRecentActivityLoadsItsRelationsInOneQuery(): void
    {
        $actor = $this->makeUser();
        $book = $this->makeBook($actor);
        $item = (new \App\Entity\ActivityItem())
            ->setActor($actor)
            ->setActionType(\App\Enum\ActivityType::AddedBook)
            ->setTargetBook($book);
        $this->em->persist($item);
        $this->em->flush();
        $this->em->clear();

        $items = $this->em->getRepository(\App\Entity\ActivityItem::class)->findRecentWithRelations(20);

        self::assertNotEmpty($items);
        foreach ($items as $loaded) {
            // Initialized eagerly by the fetch-join rather than on first touch.
            self::assertTrue($this->em->getUnitOfWork()->isInIdentityMap($loaded->getActor()));
        }
    }

    /** The targets are nullable by design, so the joins must not drop those rows. */
    public function testRecentActivityKeepsItemsWithNoTarget(): void
    {
        $actor = $this->makeUser();
        $item = (new \App\Entity\ActivityItem())
            ->setActor($actor)
            ->setActionType(\App\Enum\ActivityType::Followed);
        $this->em->persist($item);
        $this->em->flush();

        $ids = array_map(
            static fn ($i) => $i->getId(),
            $this->em->getRepository(\App\Entity\ActivityItem::class)->findRecentWithRelations(50),
        );

        self::assertContains($item->getId(), $ids);
    }
}
