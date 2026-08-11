<?php

namespace App\Tests\Service\Analytics;

use App\Dto\StatsWindow;
use App\Entity\LibraryRequestEvent;
use App\Enum\BookStatus;
use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use App\Repository\PageViewDailyRepository;
use App\Repository\PageViewVisitorRepository;
use App\Service\Analytics\StatsProvider;
use App\Tests\Repository\RepositoryTestCase;

/**
 * DB-backed rather than stubbed, deliberately.
 *
 * StatsProvider is mostly composition — it calls fifteen repository methods and
 * assembles their output. Stubbing all ten repositories would assert that the
 * assembly code returns what the stubs were told to return, while proving
 * nothing about the queries themselves, several of which use a custom DQL
 * function and enum/IDENTITY projections that only behave under a real driver.
 * Running it against the database tests the contract the SPA depends on.
 */
class StatsProviderTest extends RepositoryTestCase
{
    private function provider(): StatsProvider
    {
        return self::getContainer()->get(StatsProvider::class);
    }

    private function dashboard(int $days = 30): array
    {
        return $this->provider()->dashboard(new StatsWindow($days));
    }

    /* ── shape ────────────────────────────────────────────────────────────── */

    public function testTheTopLevelShapeIsStable(): void
    {
        self::assertSame(
            ['window', 'days', 'growth', 'engagement', 'traffic', 'library'],
            array_keys($this->dashboard()),
        );
    }

    public function testEachSectionCarriesItsExpectedKeys(): void
    {
        $data = $this->dashboard();

        self::assertSame(['days', 'from', 'to'], array_keys($data['window']));
        self::assertSame(['totals', 'series'], array_keys($data['growth']));
        self::assertSame(['totals', 'series', 'recentActivity'], array_keys($data['engagement']));
        self::assertSame(['totals', 'series', 'topRoutes'], array_keys($data['traffic']));
        self::assertSame(
            ['booksByStatus', 'topCategories', 'topLanguages', 'mostBorrowed', 'topLenders'],
            array_keys($data['library']),
        );
    }

    /**
     * The density contract: a chart handed a short or sparse series silently
     * draws the wrong shape instead of failing, so every series must be exactly
     * as long as the axis.
     */
    public function testEverySeriesIsDenseAndMatchesTheDayAxis(): void
    {
        foreach ([7, 30, 90] as $days) {
            $data = $this->dashboard($days);

            self::assertCount($days, $data['days'], "axis for a $days-day window");

            foreach (['growth', 'engagement', 'traffic'] as $section) {
                foreach ($data[$section]['series'] as $name => $series) {
                    self::assertCount(
                        $days,
                        $series,
                        "$section.$name must have one entry per day in a $days-day window",
                    );
                    self::assertSame(array_values($series), $series, "$section.$name must be a list");
                }
            }
        }
    }

    /** Calendar days, not instants — an ATOM value invites a timezone shift. */
    public function testWindowDatesAreCalendarDays(): void
    {
        $data = $this->dashboard(7);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['window']['from']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['window']['to']);
        self::assertSame($data['days'][0], $data['window']['from']);
        self::assertSame(end($data['days']), $data['window']['to']);
    }

    /** Nothing is pre-translated or pre-formatted; the SPA owns display. */
    public function testStatusKeysAreRawEnumValues(): void
    {
        $byStatus = $this->dashboard()['library']['booksByStatus'];

        foreach (BookStatus::cases() as $case) {
            self::assertArrayHasKey($case->value, $byStatus);
        }
    }

    /** An empty install must render, not explode. */
    public function testAnEmptyWindowProducesZeroesRatherThanGaps(): void
    {
        $data = $this->dashboard(7);

        self::assertSame(array_fill(0, 7, 0), $data['traffic']['series']['views']);
        self::assertSame(0, $data['traffic']['totals']['views']);
        self::assertIsArray($data['library']['mostBorrowed']);
        self::assertIsArray($data['engagement']['recentActivity']);
    }

    /* ── content ──────────────────────────────────────────────────────────── */

    public function testGrowthCountsLandOnTheRightDay(): void
    {
        $this->makeUser();
        $this->makeUser();
        $this->em->flush();

        $data = $this->dashboard(7);
        $today = array_key_last($data['days']);

        self::assertGreaterThanOrEqual(2, $data['growth']['series']['users'][$today]);
        self::assertGreaterThanOrEqual(2, $data['growth']['totals']['users']);
    }

    public function testTrafficReflectsRecordedViews(): void
    {
        $today = new \DateTimeImmutable('today');
        /** @var PageViewDailyRepository $daily */
        $daily = $this->em->getRepository(\App\Entity\PageViewDaily::class);
        $daily->increment('library', $today);
        $daily->increment('library', $today);
        $daily->increment('discover', $today);

        /** @var PageViewVisitorRepository $visitors */
        $visitors = $this->em->getRepository(\App\Entity\PageViewVisitor::class);
        $visitors->touch('member-hash', $today, true);
        $visitors->touch('anon-hash', $today, false);

        $data = $this->dashboard(7);
        $last = \count($data['days']) - 1;

        self::assertSame(3, $data['traffic']['totals']['views']);
        self::assertSame(3, $data['traffic']['series']['views'][$last]);
        self::assertSame(2, $data['traffic']['series']['visitors'][$last]);
        // DAU counts only the signed-in visitor.
        self::assertSame(1, $data['engagement']['series']['activeUsers'][$last]);
        self::assertSame(1, $data['engagement']['totals']['activeToday']);
        self::assertSame('library', $data['traffic']['topRoutes'][0]['route']);
    }

    public function testLoanTotalsComeFromTheEventLog(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $request = $this->makeRequest($this->makeBook($owner), $borrower, RequestStatus::Returned);

        foreach ([
            LibraryRequestEventType::Requested,
            LibraryRequestEventType::Approved,
            LibraryRequestEventType::Returned,
        ] as $type) {
            $this->em->persist(
                (new LibraryRequestEvent())->setRequest($request)->setActor($owner)->setType($type),
            );
        }
        $this->em->flush();

        $totals = $this->dashboard(7)['engagement']['totals'];

        self::assertSame(1, $totals['requested']);
        self::assertSame(1, $totals['approved']);
        self::assertSame(1, $totals['returned']);
    }

    public function testRankingsCarryTheirHydratedEntities(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $book = $this->makeBook($owner);
        $this->makeRequest($book, $borrower, RequestStatus::Returned);
        $this->em->flush();

        $library = $this->dashboard()['library'];

        self::assertSame($book->getId(), $library['mostBorrowed'][0]['book']['id']);
        self::assertSame($book->getTitle(), $library['mostBorrowed'][0]['book']['title']);
        self::assertSame(1, $library['mostBorrowed'][0]['loans']);

        self::assertSame($owner->getId(), $library['topLenders'][0]['user']['id']);
        self::assertSame(1, $library['topLenders'][0]['loans']);
    }

    /** Codes stay raw; the English name rides along only as a fallback label. */
    public function testTopLanguagesCarryCodeAndCatalogName(): void
    {
        $owner = $this->makeUser();
        $this->makeBook($owner)->setLanguage('uk');
        $this->em->flush();

        $row = $this->dashboard()['library']['topLanguages'][0];

        self::assertSame('uk', $row['code']);
        self::assertSame('Ukrainian', $row['name']);
        self::assertSame(1, $row['books']);
    }
}
