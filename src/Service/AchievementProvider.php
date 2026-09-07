<?php

namespace App\Service;

use App\Achievement\AchievementEvaluator;
use App\Entity\User;
use App\Enum\AchievementMetric;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\SubscriptionRepository;

/**
 * Derives a member's achievement collection from their shelves and their loans.
 *
 * The same "derived, not stored" call UserStatsProvider makes, and it sits here
 * next to it for that reason. Nothing is persisted, so a badge is retroactive
 * for members who earned it before the feature existed and no write path had to
 * learn about achievements.
 *
 * Cost: seven queries per member — the owned shelf's four figures come back in
 * one aggregate row, the rest are a count each. That is the same order as
 * UserStatsProvider::forUser()'s five, and it is paid on every `/me`, every
 * profile view and every public-profile hit — all three show the collection in
 * the header, so there is nothing to defer. If that ever stops being cheap the
 * answer is a cache pool keyed by user, following the cache.openlibrary
 * convention, not a stored table.
 *
 * There is deliberately **no forUsers()**: nothing renders a page of these.
 * Discover's reader cards were considered and left out — a page of eighteen
 * would be a hundred and twenty-six queries, and a card exists to make somebody look worth
 * following, which a badge count does not help with.
 */
class AchievementProvider
{
    public function __construct(
        private readonly AchievementEvaluator $evaluator,
        private readonly BookRepository $books,
        private readonly CollectionRepository $collections,
        private readonly LibraryRequestRepository $requests,
        private readonly SubscriptionRepository $subscriptions,
    ) {}

    /**
     * @return list<array{key: string, tier: int, tiers: int, value: int, next: int|null, thresholds: int[]}>
     */
    public function forUser(User $user): array
    {
        return $this->evaluator->evaluate($this->metrics($user));
    }

    /**
     * Every metric the catalogue asks for. Keyed by the enum's backing value,
     * which is the contract the evaluator checks — a metric added to the
     * catalogue and forgotten here throws rather than reading as zero.
     *
     * @return array<string, int>
     */
    private function metrics(User $user): array
    {
        $shelf = $this->books->achievementCountsForOwner($user);
        $loans = $this->requests->countLoansFor($user);

        return [
            AchievementMetric::BooksOwned->value         => $shelf['owned'],
            AchievementMetric::BooksRead->value          => $shelf['read'],
            AchievementMetric::BooksReviewed->value      => $shelf['reviewed'],
            AchievementMetric::DistinctLanguages->value  => $shelf['languages'],
            // The wish list is the other shelf, and BookRepository makes every
            // query say which one it means — so this is its own count, not a
            // figure the owned-shelf aggregate could have carried.
            AchievementMetric::BooksWished->value        => $this->books->countWishedByOwner($user),
            AchievementMetric::DistinctCategories->value => $this->books->countDistinctCategoriesForOwner($user),
            AchievementMetric::Collections->value        => $this->collections->countByOwner($user),
            AchievementMetric::LoansLent->value          => $loans['lent'],
            AchievementMetric::LoansBorrowed->value      => $loans['borrowed'],
            AchievementMetric::Following->value          => $this->subscriptions->countFollowing($user),
        ];
    }
}
