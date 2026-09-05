<?php

namespace App\Tests\Repository;

use App\Dto\Pagination;
use App\Entity\Book;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every community surface, asked the same question: does a suspended or deleted
 * member's content still show up?
 *
 * This is the test that has to exist. VisibleUsers is applied query by query on
 * purpose — a Doctrine filter would be a default, and defaults get inherited
 * silently — but the cost of that choice is that a new query can simply forget,
 * and forgetting produces no error, just a banned member quietly back in
 * Discover. One case per surface is the only thing that notices.
 */
class VisibleUsersTest extends RepositoryTestCase
{
    private function books(): BookRepository
    {
        return self::getContainer()->get(BookRepository::class);
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function subscriptions(): SubscriptionRepository
    {
        return self::getContainer()->get(SubscriptionRepository::class);
    }

    private function page(int $perPage = 100): Pagination
    {
        return Pagination::fromRequest(new Request(['perPage' => $perPage]), $perPage);
    }

    /* ── Discover: books ──────────────────────────────────────────────────── */

    /** @return string[] */
    private function discoverTitles(User $viewer): array
    {
        $result = $this->books()->findForDiscoverPaginated($viewer, null, null, null, $this->page());

        return array_map(static fn (Book $b) => $b->getTitle(), $result->items);
    }

    public function testASuspendedMembersBooksLeaveDiscover(): void
    {
        $viewer = $this->makeUser();
        $owner = $this->makeUser();
        $book = $this->makeBook($owner);
        $this->em->flush();

        self::assertContains($book->getTitle(), $this->discoverTitles($viewer));

        $owner->ban('Spam');
        $this->em->flush();

        self::assertNotContains($book->getTitle(), $this->discoverTitles($viewer));
    }

    public function testADeletedMembersBooksLeaveDiscover(): void
    {
        $viewer = $this->makeUser();
        $owner = $this->makeUser();
        $book = $this->makeBook($owner);
        $this->em->flush();

        $owner->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertNotContains($book->getTitle(), $this->discoverTitles($viewer));
    }

    public function testLiftingASuspensionPutsTheBooksBack(): void
    {
        $viewer = $this->makeUser();
        $owner = $this->makeUser();
        $book = $this->makeBook($owner);
        $owner->ban('Mistake');
        $this->em->flush();

        $owner->unban();
        $this->em->flush();

        self::assertContains($book->getTitle(), $this->discoverTitles($viewer));
    }

    /* ── Discover: readers ────────────────────────────────────────────────── */

    /** @return int[] */
    private function discoverReaderIds(User $viewer): array
    {
        $result = $this->users()->findPublicForDiscoverPaginated($viewer, null, $this->page());

        return array_map(static fn (User $u) => $u->getId(), $result->items);
    }

    public function testASuspendedMemberLeavesTheReaderDirectory(): void
    {
        $viewer = $this->makeUser();
        $other = $this->makeUser();
        $this->em->flush();

        self::assertContains($other->getId(), $this->discoverReaderIds($viewer));

        $other->ban('Spam');
        $this->em->flush();

        self::assertNotContains($other->getId(), $this->discoverReaderIds($viewer));
    }

    public function testADeletedMemberLeavesTheReaderDirectory(): void
    {
        $viewer = $this->makeUser();
        $other = $this->makeUser();
        $other->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertNotContains($other->getId(), $this->discoverReaderIds($viewer));
    }

    /* ── Template search ──────────────────────────────────────────────────── */

    /**
     * The template source deliberately spans *every* library, private ones
     * included, because it copies bibliographic fields and never names an owner.
     * That makes it the easiest surface on which to forget this predicate — and
     * the one where a suspended member's catalogue would keep circulating.
     */
    public function testASuspendedMembersBooksLeaveTheTemplateSearch(): void
    {
        $owner = $this->makeUser();
        $book = $this->makeBook($owner);
        $book->setTitle('Unmistakable Template Title');
        $this->em->flush();

        $found = static fn (array $books) => array_filter(
            $books,
            static fn (Book $b) => $b->getTitle() === 'Unmistakable Template Title',
        );

        self::assertNotEmpty($found($this->books()->searchTemplates('Unmistakable Template', 50)));

        $owner->ban('Spam');
        $this->em->flush();

        self::assertEmpty($found($this->books()->searchTemplates('Unmistakable Template', 50)));
    }

    /** A private owner's books stay searchable — this predicate is not that one. */
    public function testAPrivateMembersBooksRemainInTheTemplateSearch(): void
    {
        $owner = $this->makeUser(private: true);
        $this->makeBook($owner)->setTitle('Private Shelf Template');
        $this->em->flush();

        $titles = array_map(
            static fn (Book $b) => $b->getTitle(),
            $this->books()->searchTemplates('Private Shelf Template', 50),
        );

        self::assertContains('Private Shelf Template', $titles);
    }

    /* ── Following ────────────────────────────────────────────────────────── */

    public function testASuspendedMemberDropsOutOfTheFollowingList(): void
    {
        $subscriber = $this->makeUser();
        $followed = $this->makeUser();
        $this->em->persist((new Subscription())->setSubscriber($subscriber)->setSubscribedTo($followed));
        $this->em->flush();

        self::assertCount(1, $this->subscriptions()->findFollowing($subscriber));

        $followed->ban('Spam');
        $this->em->flush();

        self::assertCount(0, $this->subscriptions()->findFollowing($subscriber));
        self::assertCount(0, $this->subscriptions()->findFollowingPaginated($subscriber, $this->page())->items);
        self::assertCount(0, $this->subscriptions()->findFollowedUsers($subscriber));
    }

    /**
     * The edge itself survives, so lifting the ban restores the follow rather
     * than making the member re-find somebody they never stopped following.
     */
    public function testTheFollowEdgeSurvivesASuspension(): void
    {
        $subscriber = $this->makeUser();
        $followed = $this->makeUser();
        $this->em->persist((new Subscription())->setSubscriber($subscriber)->setSubscribedTo($followed));
        $followed->ban('Spam');
        $this->em->flush();

        $followed->unban();
        $this->em->flush();

        self::assertCount(1, $this->subscriptions()->findFollowing($subscriber));
    }

    /* ── The admin list is the deliberate exception ───────────────────────── */

    public function testTheAdminListShowsExactlyTheRowsEverythingElseHides(): void
    {
        $banned = $this->makeUser();
        $banned->ban('Spam');
        $deleted = $this->makeUser();
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $active = $this->makeUser();
        $this->em->flush();

        $ids = static fn (string $status) => array_map(
            static fn (User $u) => $u->getId(),
            self::getContainer()->get(UserRepository::class)
                ->findForAdminPaginated(null, $status, Pagination::fromRequest(new Request(['perPage' => 100]), 100))
                ->items,
        );

        self::assertContains($banned->getId(), $ids('banned'));
        self::assertNotContains($banned->getId(), $ids('active'));

        self::assertContains($deleted->getId(), $ids('deleted'));
        self::assertNotContains($deleted->getId(), $ids('active'));

        self::assertContains($active->getId(), $ids('active'));

        $all = $ids('all');
        self::assertContains($banned->getId(), $all);
        self::assertContains($deleted->getId(), $all);
        self::assertContains($active->getId(), $all);
    }

    /**
     * The admin list is also the only search allowed to match on email — every
     * community-facing search is name-only, because an email lookup would turn
     * Discover into an address-book oracle.
     */
    public function testTheAdminSearchMatchesEmailAsWellAsName(): void
    {
        $user = $this->makeUser();
        $user->setEmail('findme-unique@test.local')->setFullName('Nothing Alike');
        $this->em->flush();

        $result = $this->users()->findForAdminPaginated('findme-unique', 'all', $this->page());

        self::assertSame([$user->getId()], array_map(static fn (User $u) => $u->getId(), $result->items));
    }

    /** Clamp-don't-reject, as everywhere else a keyword filter is parsed. */
    public function testAnUnknownStatusKeywordBehavesLikeAll(): void
    {
        $banned = $this->makeUser();
        $banned->ban('Spam');
        $this->em->flush();

        $ids = array_map(
            static fn (User $u) => $u->getId(),
            $this->users()->findForAdminPaginated(null, 'nonsense', $this->page())->items,
        );

        self::assertContains($banned->getId(), $ids);
    }
}
