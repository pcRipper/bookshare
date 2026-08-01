<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;
use App\Service\UserStatsProvider;
use PHPUnit\Framework\TestCase;

class UserStatsProviderTest extends TestCase
{
    public function testForUserAggregatesTheCounters(): void
    {
        $user = new User();

        $books = $this->createMock(BookRepository::class);
        $books->expects($this->once())->method('countByOwner')->with($user)->willReturn(10);
        $books->expects($this->once())->method('countShareableByOwner')->with($user)->willReturn(6);
        $books->expects($this->once())->method('countByOwnerAndStatus')->with($user, BookStatus::Lent)->willReturn(2);

        $collections = $this->createMock(CollectionRepository::class);
        $collections->expects($this->once())->method('countByOwner')->with($user)->willReturn(3);

        $stats = (new UserStatsProvider($books, $collections))->forUser($user);

        self::assertSame(
            ['totalBooks' => 10, 'shared' => 6, 'loaned' => 2, 'collections' => 3],
            $stats,
        );
    }

    public function testForUsersKeysByIdAndDefaultsMissingOwnersToZero(): void
    {
        $one = $this->userWithId(7);
        $two = $this->userWithId(9);
        $users = [$one, $two];

        // Grouped counts omit owners with nothing to count — user 9 owns no books.
        $books = $this->createStub(BookRepository::class);
        $books->method('countByOwners')->willReturn([7 => 10]);
        $books->method('countShareableByOwners')->willReturn([7 => 6]);
        $books->method('countByOwnersAndStatus')->willReturn([7 => 2]);

        $collections = $this->createStub(CollectionRepository::class);
        $collections->method('countByOwners')->willReturn([7 => 3, 9 => 1]);

        $stats = (new UserStatsProvider($books, $collections))->forUsers($users);

        self::assertSame(
            [
                7 => ['totalBooks' => 10, 'shared' => 6, 'loaned' => 2, 'collections' => 3],
                9 => ['totalBooks' => 0, 'shared' => 0, 'loaned' => 0, 'collections' => 1],
            ],
            $stats,
        );
    }

    public function testForUsersOfAnEmptyPageIsEmpty(): void
    {
        $books = $this->createStub(BookRepository::class);
        $collections = $this->createStub(CollectionRepository::class);

        self::assertSame([], (new UserStatsProvider($books, $collections))->forUsers([]));
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
