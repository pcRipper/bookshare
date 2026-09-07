<?php

namespace App\Tests\Achievement;

use App\Achievement\AchievementCatalog;
use App\Achievement\AchievementEvaluator;
use App\Enum\AchievementMetric;
use PHPUnit\Framework\TestCase;

/**
 * Pins the catalogue's own invariants. The front/back vocabulary parity — every
 * key having an icon and translated prose in the SPA — is asserted separately in
 * AchievementFrontendParityTest, which reads the frontend files.
 */
class AchievementCatalogTest extends TestCase
{
    public function testKeysAreUniqueNonEmptyAndMatchTheDefinitions(): void
    {
        $keys = AchievementCatalog::keys();

        self::assertNotEmpty($keys);
        self::assertSame($keys, array_unique($keys));
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key);
        }

        self::assertSame(
            $keys,
            array_map(static fn ($d) => $d->key, AchievementCatalog::definitions()),
        );
    }

    /** Thresholds are a ladder: a tier that needs no more than the one below it is not a tier. */
    public function testEveryFamilyHasStrictlyAscendingPositiveThresholds(): void
    {
        foreach (AchievementCatalog::definitions() as $definition) {
            $thresholds = $definition->thresholds;

            self::assertNotEmpty($thresholds, $definition->key . ' has no tiers');
            self::assertSame(array_values($thresholds), $thresholds, $definition->key . ' has gaps');

            $previous = 0;
            foreach ($thresholds as $threshold) {
                self::assertIsInt($threshold, $definition->key);
                self::assertGreaterThan($previous, $threshold, $definition->key . ' does not ascend');
                $previous = $threshold;
            }

            self::assertSame(\count($thresholds), $definition->tiers());
        }
    }

    /**
     * A metric no family uses is a query AchievementProvider would run for
     * nothing; the enum exists so both halves of that stay honest.
     */
    public function testEveryMetricIsUsedByAtLeastOneFamily(): void
    {
        $used = array_map(static fn (AchievementMetric $m) => $m->value, AchievementEvaluator::requiredMetrics());

        foreach (AchievementMetric::cases() as $case) {
            self::assertContains($case->value, $used, $case->value . ' is defined but unused');
        }
    }
}
