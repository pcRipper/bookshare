<?php

namespace App\Service\Analytics;

use App\Api\ResponseMapper;
use App\Dto\StatsWindow;
use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\LibraryRequestEventType;
use App\Language\LanguageCatalog;
use App\Repository\ActivityItemRepository;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Repository\CollectionRepository;
use App\Repository\LibraryRequestEventRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\PageViewDailyRepository;
use App\Repository\PageViewVisitorRepository;
use App\Repository\UserRepository;

/**
 * Builds the whole operator dashboard payload in one pass.
 *
 * Shapes its own output rather than going through a StatsMapper: ResponseMapper
 * is the entity→JSON layer and every method there has a real entity behind it,
 * whereas this is aggregates with no entities. A mapper for it would be a class
 * that re-keys arrays. ResponseMapper is injected only for the two genuinely
 * entity-shaped bits — the activity stream and the lender summaries.
 *
 * No caching, deliberately: this is roughly fifteen indexed queries opened by one
 * person. A cache.analytics pool keyed by window is a small addition following
 * the cache.openlibrary convention if that ever stops being true.
 */
class StatsProvider
{
    private const TOP_CATEGORIES = 8;
    private const TOP_LANGUAGES = 8;
    private const TOP_BOOKS = 10;
    private const TOP_LENDERS = 10;
    private const TOP_WANTED = 10;
    private const RECENT_ACTIVITY = 20;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserRepository $users,
        private readonly BookRepository $books,
        private readonly CollectionRepository $collections,
        private readonly CategoryRepository $categories,
        private readonly LibraryRequestRepository $requests,
        private readonly LibraryRequestEventRepository $events,
        private readonly ActivityItemRepository $activity,
        private readonly PageViewDailyRepository $pageViews,
        private readonly PageViewVisitorRepository $visitors,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(StatsWindow $window): array
    {
        return [
            'window' => [
                'days' => $window->days,
                // Y-m-d rather than ATOM: these are calendar days, not instants,
                // and an ATOM value invites a client to shift it a timezone and
                // land on the previous day.
                'from' => $window->since()->format('Y-m-d'),
                'to'   => $window->until()->format('Y-m-d'),
            ],
            // The one x-axis every series below is aligned to.
            'days'       => $window->dayKeys(),
            'growth'     => $this->growth($window),
            'engagement' => $this->engagement($window),
            'traffic'    => $this->traffic($window),
            'library'    => $this->library(),
        ];
    }

    /** @return array<string, mixed> */
    private function growth(StatsWindow $window): array
    {
        $since = $window->since();

        return [
            'totals' => [
                'users'       => $this->users->countAll(),
                'books'       => $this->books->countAll(),
                'collections' => $this->collections->countAll(),
                'categories'  => $this->categories->countAll(),
            ],
            'series' => [
                'users'       => $window->fill($this->users->countCreatedByDay($since)),
                'books'       => $window->fill($this->books->countCreatedByDay($since)),
                'collections' => $window->fill($this->collections->countCreatedByDay($since)),
                // No categories series: Category has no createdAt (see
                // CategoryRepository::countAll).
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function engagement(StatsWindow $window): array
    {
        $since = $window->since();

        $byType = $this->events->countByTypeAndDay($since);
        $requested = $window->fill($byType[LibraryRequestEventType::Requested->value] ?? []);
        $approved = $window->fill($byType[LibraryRequestEventType::Approved->value] ?? []);
        $returned = $window->fill($byType[LibraryRequestEventType::Returned->value] ?? []);

        // Daily active users: signed-in visitors that loaded a counted page.
        // A presence metric, deliberately not derived from domain events — those
        // measure engagement, which is what the loan series below is for.
        $activeUsers = $window->fill($this->visitors->countsByDay($since, true));

        return [
            'totals' => [
                'requested'   => array_sum($requested),
                'approved'    => array_sum($approved),
                'returned'    => array_sum($returned),
                'activeToday' => (int) (end($activeUsers) ?: 0),
            ],
            'series' => [
                'activeUsers' => $activeUsers,
                'requested'   => $requested,
                'approved'    => $approved,
                'returned'    => $returned,
            ],
            'recentActivity' => array_map(
                fn (ActivityItem $item) => $this->mapper->activity($item),
                $this->activity->findRecentWithRelations(self::RECENT_ACTIVITY),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function traffic(StatsWindow $window): array
    {
        $since = $window->since();

        $views = $window->fill($this->pageViews->viewsByDay($since));
        $visitors = $window->fill($this->visitors->countsByDay($since));

        return [
            'totals' => [
                'views' => $this->pageViews->totalViews($since),
                // Summed per-day distinct counts, so a visitor returning on three
                // days counts three times. That is "visits by distinct people per
                // day", not distinct people over the window — which would need
                // the raw hashes across the whole range. The daily series is what
                // the dashboard actually plots.
                'visitors' => array_sum($visitors),
            ],
            'series' => [
                'views'    => $views,
                'visitors' => $visitors,
            ],
            // Raw route names — the SPA owns their display labels.
            'topRoutes' => $this->pageViews->topRoutes($since, \count(\App\Analytics\AnalyticsRoutes::NAMES)),
        ];
    }

    /** @return array<string, mixed> */
    private function library(): array
    {
        return [
            'booksByStatus' => $this->books->countByStatus(),
            'topCategories' => $this->books->countByCategory(self::TOP_CATEGORIES),
            'topLanguages'  => $this->topLanguages(),
            'mostBorrowed'  => $this->mostBorrowed(),
            'topLenders'    => $this->topLenders(),
            // Wish lists sit under "library health" rather than growth: they say
            // what the shelves are *missing*, which is the same question the
            // status and category breakdowns above ask from the other side.
            // Every other number on this dashboard excludes wish-list rows.
            'wishlist'      => [
                'total'      => $this->books->countWishedAll(),
                // Keyed by WishPriority value; the SPA owns the labels/colours.
                'byPriority' => $this->books->countByWishPriority(),
                'mostWanted' => $this->books->countMostWanted(self::TOP_WANTED),
            ],
        ];
    }

    /**
     * Language codes with their catalog names. The name is always English, the
     * same contract ResponseMapper::book() follows — the SPA re-derives the label
     * per locale from the code.
     *
     * @return list<array{code: string, name: ?string, books: int}>
     */
    private function topLanguages(): array
    {
        return array_map(
            static fn (array $row) => $row + ['name' => LanguageCatalog::name($row['code'])],
            $this->books->countByLanguage(self::TOP_LANGUAGES),
        );
    }

    /**
     * Ranked books, hydrated in a single follow-up query and re-ordered in PHP to
     * the ranking's order — find() per row would be an N+1 on the one page that
     * shows several rankings at once.
     *
     * @return list<array{book: array<string, mixed>, loans: int}>
     */
    private function mostBorrowed(): array
    {
        $counts = $this->requests->mostBorrowedBookIds(self::TOP_BOOKS);
        if ($counts === []) {
            return [];
        }

        $books = [];
        foreach ($this->books->findBy(['id' => array_keys($counts)]) as $book) {
            $books[$book->getId()] = $book;
        }

        $ranked = [];
        foreach ($counts as $id => $loans) {
            $book = $books[$id] ?? null;
            if (!$book instanceof Book) {
                continue;
            }

            $ranked[] = [
                'book' => [
                    'id'        => $book->getId(),
                    'title'     => $book->getTitle(),
                    'author'    => $book->getAuthor(),
                    'coverPath' => $book->getCoverPath(),
                ],
                'loans' => $loans,
            ];
        }

        return $ranked;
    }

    /** @return list<array{user: array<string, mixed>, loans: int}> */
    private function topLenders(): array
    {
        $counts = $this->requests->topLenderIds(self::TOP_LENDERS);
        if ($counts === []) {
            return [];
        }

        $users = [];
        foreach ($this->users->findBy(['id' => array_keys($counts)]) as $user) {
            $users[$user->getId()] = $user;
        }

        $ranked = [];
        foreach ($counts as $id => $loans) {
            $user = $users[$id] ?? null;
            if (!$user instanceof User) {
                continue;
            }

            $ranked[] = ['user' => $this->mapper->userSummary($user), 'loans' => $loans];
        }

        return $ranked;
    }
}
