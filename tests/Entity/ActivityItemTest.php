<?php

namespace App\Tests\Entity;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\ActivityType;
use PHPUnit\Framework\TestCase;

class ActivityItemTest extends TestCase
{
    public function testDefaults(): void
    {
        $item = new ActivityItem();

        self::assertNull($item->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
        self::assertNull($item->getTargetBook());
        self::assertNull($item->getTargetUser());
        self::assertNull($item->getCommentText());
    }

    public function testSettersAreFluentAndStore(): void
    {
        $actor = new User();
        $targetUser = new User();
        $book = new Book();

        $item = (new ActivityItem())
            ->setActor($actor)
            ->setActionType(ActivityType::Commented)
            ->setTargetBook($book)
            ->setTargetUser($targetUser)
            ->setCommentText('Loved this one.');

        self::assertSame($actor, $item->getActor());
        self::assertSame(ActivityType::Commented, $item->getActionType());
        self::assertSame($book, $item->getTargetBook());
        self::assertSame($targetUser, $item->getTargetUser());
        self::assertSame('Loved this one.', $item->getCommentText());
    }
}
