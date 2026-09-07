<?php

namespace App\Achievement;

use App\Enum\AchievementMetric;

/**
 * Turns a member's raw metric counts into the collection the API publishes.
 *
 * Pure: no repositories, no entities, no clock. Gathering the counts is
 * `AchievementProvider`'s job, and keeping the two apart is what lets the rules
 * — which is the part with off-by-one risk in it — be unit-tested without a
 * database.
 */
final class AchievementEvaluator
{
    /**
     * @param  array<string, int> $metrics keyed by AchievementMetric value
     * @return list<array{key: string, tier: int, tiers: int, value: int, next: int|null, thresholds: int[]}>
     */
    public function evaluate(array $metrics): array
    {
        $collection = [];

        foreach (AchievementCatalog::definitions() as $definition) {
            $metric = $definition->metric;

            // Loudly, rather than defaulting to 0: a metric the provider forgot
            // would otherwise render as a locked badge on every member's
            // profile, which looks like a deliberate threshold nobody has met
            // and so would never be reported as a bug.
            if (!\array_key_exists($metric->value, $metrics)) {
                throw new \InvalidArgumentException(\sprintf(
                    'No value supplied for achievement metric "%s" (needed by "%s").',
                    $metric->value,
                    $definition->key,
                ));
            }

            $value = $metrics[$metric->value];

            $collection[] = [
                'key'   => $definition->key,
                // 0 means locked; `tiers` is emitted so the SPA's pips aren't
                // hardcoded to three and a fourth tier needs no frontend change.
                'tier'  => $definition->tierFor($value),
                'tiers' => $definition->tiers(),
                'value' => $value,
                // null when maxed — the progress bar has nothing left to aim at.
                'next'  => $definition->nextThreshold($value),
                // The whole ladder, so the modal can show what each tier costs
                // without duplicating the catalogue in JavaScript.
                'thresholds' => $definition->thresholds,
            ];
        }

        return $collection;
    }

    /**
     * The metrics the catalogue actually asks for. `AchievementProvider` builds
     * its map against this rather than against every enum case, so a metric
     * that no family uses costs no query.
     *
     * @return AchievementMetric[]
     */
    public static function requiredMetrics(): array
    {
        $metrics = [];
        foreach (AchievementCatalog::definitions() as $definition) {
            $metrics[$definition->metric->value] = $definition->metric;
        }

        return array_values($metrics);
    }
}
