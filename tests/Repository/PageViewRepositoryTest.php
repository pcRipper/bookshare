<?php

namespace App\Tests\Repository;

use App\Entity\PageViewDaily;
use App\Entity\PageViewVisitor;
use App\Repository\PageViewDailyRepository;
use App\Repository\PageViewVisitorRepository;

/**
 * The counters are written by DQL UPDATE / insert-on-miss rather than by loading
 * and mutating an entity, so nothing about them is visible to a unit test: the
 * atomic `views + 1`, the insert fallback on the first hit of a day, and the DATE
 * grouping all only exist against a real database.
 *
 * This is also the test that keeps the increment honest if it is ever swapped
 * for an `INSERT ... ON CONFLICT DO UPDATE`.
 */
class PageViewRepositoryTest extends RepositoryTestCase
{
    private function daily(): PageViewDailyRepository
    {
        return $this->em->getRepository(PageViewDaily::class);
    }

    private function visitors(): PageViewVisitorRepository
    {
        return $this->em->getRepository(PageViewVisitor::class);
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 00:00:00');
    }

    /**
     * The first call takes the insert branch, the second the UPDATE branch. One
     * row, counted twice — a read-modify-write would leave views at 1 here under
     * concurrency, and a plain insert would violate the unique constraint.
     */
    public function testRepeatedIncrementsAccumulateOnOneRow(): void
    {
        $day = $this->day('2026-08-11');

        $this->daily()->increment('library', $day);
        $this->daily()->increment('library', $day);
        $this->daily()->increment('library', $day);

        // The counter is advanced by DQL UPDATE, which the ORM cannot see: the
        // instance the insert branch left in the identity map still reads 1, and
        // hydration won't overwrite a managed entity's fields. Clear first so
        // this asserts the column rather than the stale object.
        $this->em->clear();
        $rows = $this->daily()->findBy(['route' => 'library', 'day' => $day]);

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->getViews());
    }

    public function testEachRouteAndDayGetsItsOwnCounter(): void
    {
        $this->daily()->increment('library', $this->day('2026-08-10'));
        $this->daily()->increment('library', $this->day('2026-08-11'));
        $this->daily()->increment('discover', $this->day('2026-08-11'));

        self::assertCount(3, $this->daily()->findAll());
    }

    public function testTopRoutesSumsAcrossDaysAndOrdersByViews(): void
    {
        $this->daily()->increment('discover', $this->day('2026-08-10'));
        $this->daily()->increment('discover', $this->day('2026-08-11'));
        $this->daily()->increment('library', $this->day('2026-08-11'));
        $this->daily()->increment('library', $this->day('2026-08-11'));
        $this->daily()->increment('library', $this->day('2026-08-11'));

        $top = $this->daily()->topRoutes($this->day('2026-08-01'), 10);

        self::assertSame(
            [['route' => 'library', 'views' => 3], ['route' => 'discover', 'views' => 2]],
            $top,
        );
    }

    public function testTopRoutesExcludesDaysBeforeTheWindow(): void
    {
        $this->daily()->increment('library', $this->day('2026-07-01'));
        $this->daily()->increment('discover', $this->day('2026-08-11'));

        $top = $this->daily()->topRoutes($this->day('2026-08-01'), 10);

        self::assertSame([['route' => 'discover', 'views' => 1]], $top);
    }

    /** Grouping on a real DATE column, keyed back to Y-m-d for the caller. */
    public function testViewsByDayGroupsByCalendarDay(): void
    {
        $this->daily()->increment('library', $this->day('2026-08-10'));
        $this->daily()->increment('discover', $this->day('2026-08-10'));
        $this->daily()->increment('library', $this->day('2026-08-11'));

        $byDay = $this->daily()->viewsByDay($this->day('2026-08-01'));

        self::assertSame(2, $byDay['2026-08-10']);
        self::assertSame(1, $byDay['2026-08-11']);
    }

    public function testTotalViewsIsZeroRatherThanNullWithNoTraffic(): void
    {
        self::assertSame(0, $this->daily()->totalViews($this->day('2026-08-01')));
    }

    /** The visitor is counted once a day however many pages they open. */
    public function testTouchingTheSameVisitorTwiceLeavesOneRow(): void
    {
        $day = $this->day('2026-08-11');

        $this->visitors()->touch('hash-a', $day, true);
        $this->visitors()->touch('hash-a', $day, true);

        self::assertCount(1, $this->visitors()->findBy(['visitorHash' => 'hash-a']));
    }

    public function testTheSameVisitorCountsAgainOnTheNextDay(): void
    {
        $this->visitors()->touch('hash-a', $this->day('2026-08-10'), true);
        $this->visitors()->touch('hash-a', $this->day('2026-08-11'), true);

        self::assertCount(2, $this->visitors()->findBy(['visitorHash' => 'hash-a']));
    }

    /** DAU is this table filtered to authenticated visitors — nothing more. */
    public function testCountsByDaySeparatesAuthenticatedVisitors(): void
    {
        $day = $this->day('2026-08-11');
        $this->visitors()->touch('member-1', $day, true);
        $this->visitors()->touch('member-2', $day, true);
        $this->visitors()->touch('anon-1', $day, false);

        $all = $this->visitors()->countsByDay($this->day('2026-08-01'));
        $dau = $this->visitors()->countsByDay($this->day('2026-08-01'), true);

        self::assertSame(3, $all['2026-08-11']);
        self::assertSame(2, $dau['2026-08-11']);
    }

    public function testPruningRemovesOnlyRowsBeforeTheCutoff(): void
    {
        $this->visitors()->touch('old', $this->day('2026-01-01'), false);
        $this->visitors()->touch('recent', $this->day('2026-08-11'), false);

        $cutoff = $this->day('2026-06-01');
        self::assertSame(1, $this->visitors()->countOlderThan($cutoff));
        self::assertSame(1, $this->visitors()->deleteOlderThan($cutoff));

        $remaining = $this->visitors()->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('recent', $remaining[0]->getVisitorHash());
    }
}
