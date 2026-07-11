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
}
