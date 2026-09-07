<?php

namespace App\Tests\Service;

use App\Achievement\AchievementEvaluator;
use App\Entity\Category;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Enum\WishPriority;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\SubscriptionRepository;
use App\Service\AchievementProvider;
use App\Tests\Repository\RepositoryTestCase;

/**
 * DB-backed for the same reason StatsProviderTest is: the provider is thin, and
 * everything that can go wrong is in the DQL underneath it — the owned-shelf
 * predicate, the conditional SUM, COUNT ignoring nulls, the DISTINCT over a
 * to-many join, and whether a collection borrow's children are counted.
 * Stubbing four repositories would prove only that the assembly returns what
 * the stubs were told to.
 */
class AchievementProviderTest extends RepositoryTestCase
{
    /**
     * Built by hand rather than pulled from the test container: the provider has
     * a single consumer per surface and the compiler inlines it, so `get()` would
     * report it removed. Its wiring is covered by `lint:container` instead.
     */
    private function provider(): AchievementProvider
    {
        $container = self::getContainer();

        return new AchievementProvider(
            new AchievementEvaluator(),
            $container->get(BookRepository::class),
            $container->get(CollectionRepository::class),
            $container->get(LibraryRequestRepository::class),
            $container->get(SubscriptionRepository::class),
        );
    }

    /** @return array<string, array{key:string, tier:int, tiers:int, value:int, next:?int, thresholds:int[]}> */
    private function collection(User $user): array
    {
        $this->em->flush();

        $byKey = [];
        foreach ($this->provider()->forUser($user) as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        return $byKey;
    }

    private function makeCategory(string $name): Category
    {
        $category = (new Category())->setName($name . '-' . uniqid())->setColorHex('#E8F0EA');
        $this->em->persist($category);

        return $category;
    }

    /* ── shape ────────────────────────────────────────────────────────────── */

    public function testAFreshMemberGetsTheWholeCollectionLocked(): void
    {
        $collection = $this->collection($this->makeUser());

        self::assertCount(10, $collection);
        foreach ($collection as $key => $entry) {
            self::assertSame(0, $entry['tier'], $key . ' should be locked');
            self::assertSame(0, $entry['value'], $key . ' should count nothing');
        }
    }

    /* ── the owned shelf ──────────────────────────────────────────────────── */

    public function testWishListBooksDoNotInflateTheOwnedShelf(): void
    {
        $user = $this->makeUser();
        $this->makeBook($user);
        $this->makeBook($user)->setWish(true, WishPriority::Urgent);
        $this->makeBook($user)->setWish(true, WishPriority::CanWait);

        $collection = $this->collection($user);

        self::assertSame(1, $collection['collector']['value']);
        self::assertSame(2, $collection['dreamer']['value']);
    }

    /**
     * A read flag and a rating are orthogonal to each other and to the shelf's
     * size, so each figure has to come off its own predicate.
     */
    public function testReadAndReviewedCountOnlyTheBooksCarryingThoseFields(): void
    {
        $user = $this->makeUser();
        $this->makeBook($user)->setIsRead(true)->setRating(4);
        $this->makeBook($user)->setIsRead(true);
        $this->makeBook($user)->setRating(2);
        $this->makeBook($user);

        $collection = $this->collection($user);

        self::assertSame(4, $collection['collector']['value']);
        self::assertSame(2, $collection['reader']['value']);
        self::assertSame(2, $collection['critic']['value']);
    }

    /** An unrated book must not read as a reviewed one — null is meaningful here. */
    public function testAnUnratedShelfScoresNothingForCritic(): void
    {
        $user = $this->makeUser();
        $this->makeBook($user);
        $this->makeBook($user);

        self::assertSame(0, $this->collection($user)['critic']['value']);
    }

    public function testLanguagesAreCountedDistinctlyAndSkipTheUnset(): void
    {
        $user = $this->makeUser();
        $this->makeBook($user)->setLanguage('en');
        $this->makeBook($user)->setLanguage('en');
        $this->makeBook($user)->setLanguage('uk');
        $this->makeBook($user); // no language at all

        $collection = $this->collection($user);

        self::assertSame(2, $collection['polyglot']['value']);
        // Two distinct languages is the family's first threshold, exactly.
        self::assertSame(1, $collection['polyglot']['tier']);
        self::assertSame(4, $collection['polyglot']['next']);
    }

    /** One book in three categories broadens a shelf three ways. */
    public function testCategoriesAreCountedPerCategoryNotPerBook(): void
    {
        $user = $this->makeUser();
        $book = $this->makeBook($user);
        $book->addCategory($this->makeCategory('a'));
        $book->addCategory($this->makeCategory('b'));
        $book->addCategory($this->makeCategory('c'));

        $collection = $this->collection($user);

        self::assertSame(3, $collection['explorer']['value']);
        self::assertSame(1, $collection['explorer']['tier']);
    }

    public function testAWishListBooksCategoriesDoNotCount(): void
    {
        $user = $this->makeUser();
        $wanted = $this->makeBook($user)->setWish(true, WishPriority::CanWait);
        $wanted->addCategory($this->makeCategory('a'));

        self::assertSame(0, $this->collection($user)['explorer']['value']);
    }

    /* ── other members ────────────────────────────────────────────────────── */

    public function testAnotherMembersShelvesDoNotLeakIn(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $this->makeBook($other);
        $this->makeBook($other)->setIsRead(true);
        $this->makeCollection($other, [$this->makeBook($other), $this->makeBook($other)]);

        $collection = $this->collection($user);

        self::assertSame(0, $collection['collector']['value']);
        self::assertSame(0, $collection['reader']['value']);
        self::assertSame(0, $collection['curator']['value']);
    }

    /* ── loans ────────────────────────────────────────────────────────────── */

    public function testLoansAreCountedFromBothSidesOfTheMachine(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        $this->makeRequest($this->makeBook($owner, BookStatus::Lent, $borrower), $borrower, RequestStatus::Approved);
        $this->makeRequest($this->makeBook($owner), $borrower, RequestStatus::Returned);

        self::assertSame(2, $this->collection($owner)['lender']['value']);
        self::assertSame(2, $this->collection($borrower)['borrower']['value']);
    }

    /**
     * An unapproved or refused request is an intention, not a loan — the same
     * rule the dashboard's rankings apply through LOANED_STATUSES.
     */
    public function testPendingAndDeclinedRequestsAreNotLoans(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        $this->makeRequest($this->makeBook($owner), $borrower, RequestStatus::Pending);
        $this->makeRequest($this->makeBook($owner), $borrower, RequestStatus::Declined);

        self::assertSame(0, $this->collection($owner)['lender']['value']);
        self::assertSame(0, $this->collection($borrower)['borrower']['value']);
    }

    /**
     * The deliberate opposite of the inbox lists' `parentRequest IS NULL` rule:
     * a three-book collection borrow really is three loans.
     */
    public function testACollectionBorrowCountsOncePerBook(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();

        $collection = $this->makeCollection($owner, [
            $this->makeBook($owner),
            $this->makeBook($owner),
            $this->makeBook($owner),
        ]);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Returned);

        self::assertSame(3, $this->collection($owner)['lender']['value']);
        self::assertSame(3, $this->collection($borrower)['borrower']['value']);
    }

    /* ── the community ───────────────────────────────────────────────────── */

    public function testFollowingCountsWhoTheMemberFollowsNotWhoFollowsThem(): void
    {
        $user = $this->makeUser();
        $followed = $this->makeUser();
        $follower = $this->makeUser();

        $this->em->persist((new Subscription())->setSubscriber($user)->setSubscribedTo($followed));
        $this->em->persist((new Subscription())->setSubscriber($follower)->setSubscribedTo($user));

        $collection = $this->collection($user);

        self::assertSame(1, $collection['connector']['value']);
        self::assertSame(1, $collection['connector']['tier']);
        self::assertSame(5, $collection['connector']['next']);
    }

    /* ── tiers ────────────────────────────────────────────────────────────── */

    public function testCollectionsDriveTheCuratorFamily(): void
    {
        $user = $this->makeUser();
        $this->makeCollection($user, [$this->makeBook($user), $this->makeBook($user)]);

        $collection = $this->collection($user);

        self::assertSame(1, $collection['curator']['value']);
        self::assertSame(1, $collection['curator']['tier']);
        self::assertSame(5, $collection['curator']['next']);
    }
}
