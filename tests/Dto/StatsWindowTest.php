<?php

namespace App\Tests\Dto;

use App\Dto\StatsWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class StatsWindowTest extends TestCase
{
    private function fromQuery(mixed $window): StatsWindow
    {
        return StatsWindow::fromRequest(
            Request::create('/api/admin/stats', 'GET', $window === null ? [] : ['window' => $window]),
        );
    }

    public function testAMissingWindowFallsBackToTheDefault(): void
    {
        self::assertSame(StatsWindow::DEFAULT, $this->fromQuery(null)->days);
    }

    public function testTheOfferedWindowsAreHonoured(): void
    {
        foreach (StatsWindow::ALLOWED as $days) {
            self::assertSame($days, $this->fromQuery((string) $days)->days);
        }
    }

    /**
     * Clamped, never rejected — the Pagination contract. A dashboard must not
     * 422 on a stray query param, which is also why there is no "invalid window"
     * message needing four translations.
     *
     * @param int|string $raw
     */
    #[DataProvider('outOfRangeWindows')]
    public function testOutOfRangeInputSnapsToAnOfferedWindowWithoutThrowing(
        int|string $raw,
        int $expected,
    ): void {
        self::assertSame($expected, $this->fromQuery((string) $raw)->days);
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function outOfRangeWindows(): iterable
    {
        yield 'zero' => [0, 7];
        yield 'one' => [1, 7];
        yield 'just below the middle option' => [20, 30];
        yield 'just above the middle option' => [45, 30];
        yield 'far too large' => [9999, 90];
        yield 'not a number' => ['abc', StatsWindow::DEFAULT];
        yield 'negative reads as non-numeric' => ['-5', StatsWindow::DEFAULT];
        yield 'empty' => ['', StatsWindow::DEFAULT];
        yield 'float' => ['7.5', StatsWindow::DEFAULT];
    }

    public function testDayKeysCoverTheWindowOldestFirstAndEndToday(): void
    {
        $keys = new StatsWindow(7)->dayKeys();

        self::assertCount(7, $keys);
        self::assertSame(array_values(array_unique($keys)), $keys);

        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys, 'The axis must run oldest to newest.');

        self::assertSame(new \DateTimeImmutable('today')->format('Y-m-d'), end($keys));
    }

    public function testSinceIsTheFirstDayOfTheWindowAtMidnight(): void
    {
        $window = new StatsWindow(30);

        self::assertSame('00:00:00', $window->since()->format('H:i:s'));
        self::assertSame($window->dayKeys()[0], $window->since()->format('Y-m-d'));
    }

    /**
     * The gap-fill is a response contract, not a nicety: a chart handed a sparse
     * series closes up the missing days and draws the wrong shape.
     */
    public function testFillProjectsASparseMapOntoTheFullAxis(): void
    {
        $window = new StatsWindow(7);
        $keys = $window->dayKeys();

        $filled = $window->fill([$keys[0] => 4, $keys[3] => 9]);

        self::assertSame([4, 0, 0, 9, 0, 0, 0], $filled);
    }

    public function testFillOfAnEmptyMapIsAllZeroesOfTheRightLength(): void
    {
        self::assertSame(array_fill(0, 90, 0), new StatsWindow(90)->fill([]));
    }

    /** A day outside the window can't leak into the series. */
    public function testFillIgnoresDaysOutsideTheWindow(): void
    {
        $window = new StatsWindow(7);

        $filled = $window->fill(['1999-01-01' => 100]);

        self::assertSame(array_fill(0, 7, 0), $filled);
    }
}
