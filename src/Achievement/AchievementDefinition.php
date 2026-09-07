<?php

namespace App\Achievement;

use App\Enum\AchievementMetric;

/**
 * One achievement family: a countable metric and the thresholds at which it
 * levels up. Ten of these make the collection (see AchievementCatalog).
 *
 * A family rather than a flat badge per threshold, so a member's collection is
 * ten cells that grow instead of thirty that appear — and so an unearned cell
 * has something to say ("38 of 100") rather than only being absent.
 */
final class AchievementDefinition
{
    /**
     * @param string $key       stable identifier; the SPA hangs its icon, name
     *                          and description off this and never off the index
     * @param int[]  $thresholds ascending metric values, one per tier
     */
    public function __construct(
        public readonly string $key,
        public readonly AchievementMetric $metric,
        public readonly array $thresholds,
    ) {}

    /** How many tiers this family has. */
    public function tiers(): int
    {
        return \count($this->thresholds);
    }

    /**
     * The tier a metric value has reached: 0 when it hasn't met the first
     * threshold, `tiers()` when it has met the last.
     *
     * The comparison is `>=`, so reaching a threshold exactly earns it — a
     * catalogue that promised "25 books" and awarded nothing at 25 would be
     * lying about its own numbers.
     */
    public function tierFor(int $value): int
    {
        $tier = 0;
        foreach ($this->thresholds as $threshold) {
            if ($value < $threshold) {
                break;
            }
            ++$tier;
        }

        return $tier;
    }

    /** The value the next tier needs, or null when the family is maxed. */
    public function nextThreshold(int $value): ?int
    {
        return $this->thresholds[$this->tierFor($value)] ?? null;
    }
}
