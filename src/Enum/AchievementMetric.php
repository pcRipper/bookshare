<?php

namespace App\Enum;

/**
 * The countable facts an achievement can be earned against.
 *
 * An enum rather than free-text metric names, so a catalogue entry cannot name
 * something nothing computes: `AchievementProvider` builds a map keyed by these
 * cases, `AchievementEvaluator` throws on a case it wasn't handed, and
 * `AchievementCatalogTest` fails on a case no family uses. A typo'd string
 * would instead have shown every member a permanently locked badge, which reads
 * as a product decision rather than a bug.
 *
 * String-backed rather than integer-backed (the opposite of `WishPriority`,
 * whose value *is* its ranking): nothing sorts on these, and a readable value
 * is what makes the provider's array legible in a test failure.
 */
enum AchievementMetric: string
{
    case BooksOwned         = 'books_owned';
    case BooksRead          = 'books_read';
    case BooksReviewed      = 'books_reviewed';
    case BooksWished        = 'books_wished';
    case Collections        = 'collections';
    case DistinctLanguages  = 'distinct_languages';
    case DistinctCategories = 'distinct_categories';
    case LoansLent          = 'loans_lent';
    case LoansBorrowed      = 'loans_borrowed';
    case Following          = 'following';
}
