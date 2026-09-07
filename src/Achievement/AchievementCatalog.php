<?php

namespace App\Achievement;

use App\Enum\AchievementMetric;

/**
 * The achievement collection: ten families, three tiers each, and the single
 * source of truth for all of it — the same role CategoryPalette plays for chip
 * colours and LanguageCatalog for book languages.
 *
 * Achievements are **derived, not stored**: there is no entity and no write
 * path, so `AchievementProvider` counts a member's shelves on read and this
 * class says what those numbers are worth. That makes every badge retroactive
 * (a member who already holds 200 books has the badge without a backfill) and
 * makes progress toward the next tier expressible at all. The trade-off, taken
 * deliberately: a badge carries no "earned on" date, and shrinking a shelf can
 * take one back.
 *
 * Names, descriptions and icons are deliberately **absent**. They are the
 * frontend's (`assets/src/utils/achievements.js` for icons, the `achievements`
 * i18n namespace for prose), the same division of labour `status` and
 * `WishPriority` already make — and the reason this vocabulary needs no
 * `translations/` entry at all. `AchievementCatalogTest` fails when the two
 * halves drift, which is the only thing holding a duplicated vocabulary
 * together (see also AnalyticsRoutesTest).
 *
 * Thresholds are a product judgement, not a formula: the first tier of most
 * families is 1, because the interesting step is from never having done a thing
 * to having done it once. `polyglot` and `explorer` start higher — one language
 * and one category are what every single book already gives you.
 */
final class AchievementCatalog
{
    /** @var array<string, array{metric: AchievementMetric, thresholds: int[]}> */
    private const DEFINITIONS = [
        // What you hold, and what you've done with it.
        'collector' => ['metric' => AchievementMetric::BooksOwned,         'thresholds' => [1, 25, 100]],
        'reader'    => ['metric' => AchievementMetric::BooksRead,          'thresholds' => [1, 25, 100]],
        'critic'    => ['metric' => AchievementMetric::BooksReviewed,      'thresholds' => [1, 10, 50]],
        'curator'   => ['metric' => AchievementMetric::Collections,        'thresholds' => [1, 5, 15]],
        'dreamer'   => ['metric' => AchievementMetric::BooksWished,        'thresholds' => [1, 10, 30]],
        // The lending machine, from both sides.
        'lender'    => ['metric' => AchievementMetric::LoansLent,          'thresholds' => [1, 5, 25]],
        'borrower'  => ['metric' => AchievementMetric::LoansBorrowed,      'thresholds' => [1, 5, 25]],
        // The shape of a shelf, rather than its size. Both start above 1: every
        // book carries a category and most carry a language, so a first tier of
        // one would be awarded by the act of cataloguing anything at all.
        'polyglot'  => ['metric' => AchievementMetric::DistinctLanguages,  'thresholds' => [2, 4, 8]],
        'explorer'  => ['metric' => AchievementMetric::DistinctCategories, 'thresholds' => [3, 10, 25]],
        // The community.
        'connector' => ['metric' => AchievementMetric::Following,          'thresholds' => [1, 5, 20]],
    ];

    /**
     * Every family, in the order the collection is displayed. Callers must not
     * re-sort: the order is a curatorial choice made here (shelf, then loans,
     * then shape, then community) and the SPA renders what it is given.
     *
     * @return AchievementDefinition[]
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::DEFINITIONS as $key => $spec) {
            $definitions[] = new AchievementDefinition($key, $spec['metric'], $spec['thresholds']);
        }

        return $definitions;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
