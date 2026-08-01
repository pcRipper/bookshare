<?php

namespace App\Tests\Repository;

use App\Dto\Pagination;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Covers the Discover "Accounts" query, which serves two shapes from one builder:
 * a blank query browses the public membership newest-first, a real one searches
 * names alphabetically. Both must exclude the viewer and private profiles.
 */
class UserRepositoryTest extends RepositoryTestCase
{
    private function repo(): UserRepository
    {
        return $this->em->getRepository(User::class);
    }

    /** User::$createdAt is set in the constructor and has no setter. */
    private function createdAt(User $user, string $when): void
    {
        $property = new \ReflectionProperty(User::class, 'createdAt');
        $property->setValue($user, new \DateTimeImmutable($when));
    }

    /** @param User[] $users */
    private function ids(array $users): array
    {
        return array_map(static fn (User $u) => $u->getId(), $users);
    }

    public function testBlankQueryListsPublicReadersNewestFirst(): void
    {
        $viewer = $this->makeUser();
        $oldest = $this->makeUser();
        $newest = $this->makeUser();
        $middle = $this->makeUser();

        $this->createdAt($oldest, '2024-01-01 10:00:00');
        $this->createdAt($middle, '2024-06-01 10:00:00');
        $this->createdAt($newest, '2025-01-01 10:00:00');
        $this->em->flush();

        $result = $this->repo()->findPublicForDiscoverPaginated($viewer, null, new Pagination(1, 100));

        // Other tests' rows never leak in (each test runs in a rolled-back
        // transaction), but the DB may hold fixture users — assert on order of
        // this test's rows only.
        $ordered = array_values(array_filter(
            $this->ids($result->items),
            fn (int $id) => \in_array($id, $this->ids([$oldest, $middle, $newest]), true),
        ));

        self::assertSame($this->ids([$newest, $middle, $oldest]), $ordered);
    }

    public function testBlankQueryExcludesTheViewerAndPrivateProfiles(): void
    {
        $viewer = $this->makeUser();
        $public = $this->makeUser();
        $private = $this->makeUser(private: true);
        $this->em->flush();

        $ids = $this->ids(
            $this->repo()->findPublicForDiscoverPaginated($viewer, null, new Pagination(1, 100))->items,
        );

        self::assertContains($public->getId(), $ids);
        self::assertNotContains($viewer->getId(), $ids);
        self::assertNotContains($private->getId(), $ids);
    }

    public function testQueryFiltersByNameCaseInsensitivelyAndSortsAlphabetically(): void
    {
        $viewer = $this->makeUser();
        $zoe = $this->makeUser()->setFullName('Zoe Marlowe');
        $adam = $this->makeUser()->setFullName('Adam Marlowe');
        $other = $this->makeUser()->setFullName('Nina Halloway');
        $this->em->flush();

        $result = $this->repo()->findPublicForDiscoverPaginated($viewer, 'MARLOWE', new Pagination(1, 100));

        self::assertSame($this->ids([$adam, $zoe]), $this->ids($result->items));
        self::assertSame(2, $result->total);
        self::assertNotContains($other->getId(), $this->ids($result->items));
    }

    public function testQueryTreatsLikeWildcardsLiterally(): void
    {
        $viewer = $this->makeUser();
        $this->makeUser()->setFullName('Percy Underscore');
        $literal = $this->makeUser()->setFullName('Ada 100% Reader');
        $this->em->flush();

        $result = $this->repo()->findPublicForDiscoverPaginated($viewer, '100%', new Pagination(1, 100));

        self::assertSame([$literal->getId()], $this->ids($result->items));
    }

    public function testPaginationSlicesThePageButCountsEveryMatch(): void
    {
        $viewer = $this->makeUser();
        $a = $this->makeUser()->setFullName('Aaa Pagetest');
        $b = $this->makeUser()->setFullName('Bbb Pagetest');
        $c = $this->makeUser()->setFullName('Ccc Pagetest');
        $this->em->flush();

        $page1 = $this->repo()->findPublicForDiscoverPaginated($viewer, 'Pagetest', new Pagination(1, 2));
        $page2 = $this->repo()->findPublicForDiscoverPaginated($viewer, 'Pagetest', new Pagination(2, 2));

        self::assertSame($this->ids([$a, $b]), $this->ids($page1->items));
        self::assertSame($this->ids([$c]), $this->ids($page2->items));
        self::assertSame(3, $page1->total);
        self::assertSame(3, $page2->total);
    }
}
