<?php

namespace App\Tests\Achievement;

use App\Achievement\AchievementCatalog;
use App\Achievement\AchievementEvaluator;
use App\Enum\AchievementMetric;
use PHPUnit\Framework\TestCase;

class AchievementEvaluatorTest extends TestCase
{
    /** @return array<string, int> every required metric at the same value */
    private function metrics(int $value = 0, array $overrides = []): array
    {
        $metrics = [];
        foreach (AchievementEvaluator::requiredMetrics() as $metric) {
            $metrics[$metric->value] = $value;
        }

        return array_merge($metrics, $overrides);
    }

    private function family(array $collection, string $key): array
    {
        foreach ($collection as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        self::fail('No such family: ' . $key);
    }

    public function testEveryFamilyIsEmittedInCatalogueOrder(): void
    {
        $collection = (new AchievementEvaluator())->evaluate($this->metrics());

        self::assertSame(
            AchievementCatalog::keys(),
            array_map(static fn ($e) => $e['key'], $collection),
        );
    }

    /** A member with nothing gets the whole collection, all of it locked. */
    public function testZeroMetricsLockEveryFamilyAndAimAtTheFirstThreshold(): void
    {
        foreach ((new AchievementEvaluator())->evaluate($this->metrics()) as $entry) {
            self::assertSame(0, $entry['tier'], $entry['key']);
            self::assertSame(0, $entry['value'], $entry['key']);
            self::assertSame($entry['thresholds'][0], $entry['next'], $entry['key']);
        }
    }

    /**
     * Reaching a threshold exactly earns the tier. A catalogue that advertises
     * "25 books" and awards nothing at 25 is lying about its own numbers.
     */
    public function testATierIsEarnedAtItsThresholdNotAfterIt(): void
    {
        $evaluator = new AchievementEvaluator();

        $at24 = $this->family($evaluator->evaluate($this->metrics(overrides: [AchievementMetric::BooksOwned->value => 24])), 'collector');
        $at25 = $this->family($evaluator->evaluate($this->metrics(overrides: [AchievementMetric::BooksOwned->value => 25])), 'collector');

        self::assertSame(1, $at24['tier']);
        self::assertSame(25, $at24['next']);
        self::assertSame(2, $at25['tier']);
        self::assertSame(100, $at25['next']);
    }

    public function testAMaxedFamilyHasNoNextThreshold(): void
    {
        $collection = (new AchievementEvaluator())->evaluate(
            $this->metrics(overrides: [AchievementMetric::BooksOwned->value => 5000]),
        );

        $collector = $this->family($collection, 'collector');

        self::assertSame($collector['tiers'], $collector['tier']);
        self::assertNull($collector['next']);
        self::assertSame(5000, $collector['value']);
    }

    /**
     * A forgotten metric must not read as 0: that renders a locked badge on
     * every member's profile, which looks like a threshold nobody has met and
     * would never be reported as a bug.
     */
    public function testAMissingMetricThrowsRatherThanCountingAsZero(): void
    {
        $metrics = $this->metrics();
        unset($metrics[AchievementMetric::LoansLent->value]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loans_lent/');

        (new AchievementEvaluator())->evaluate($metrics);
    }

    public function testRequiredMetricsIsDuplicateFree(): void
    {
        $values = array_map(static fn (AchievementMetric $m) => $m->value, AchievementEvaluator::requiredMetrics());

        self::assertSame($values, array_unique($values));
    }
}
