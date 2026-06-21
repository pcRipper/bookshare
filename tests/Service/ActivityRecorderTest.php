<?php

namespace App\Tests\Service;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Service\ActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ActivityRecorderTest extends TestCase
{
    public function testRecordBuildsAndPersistsAnActivityItem(): void
    {
        $actor = new User();
        $targetUser = new User();
        $book = new Book();

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted) { $persisted = $entity; },
        );

        $item = (new ActivityRecorder($em))->record(
            $actor,
            ActivityType::Commented,
            targetBook: $book,
            targetUser: $targetUser,
            commentText: 'Great read',
        );

        self::assertInstanceOf(ActivityItem::class, $item);
        self::assertSame($item, $persisted, 'the same item is persisted and returned');
        self::assertSame($actor, $item->getActor());
        self::assertSame(ActivityType::Commented, $item->getActionType());
        self::assertSame($book, $item->getTargetBook());
        self::assertSame($targetUser, $item->getTargetUser());
        self::assertSame('Great read', $item->getCommentText());
    }

    public function testRecordDefaultsOptionalTargetsToNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $item = (new ActivityRecorder($em))->record(new User(), ActivityType::Followed);

        self::assertNull($item->getTargetBook());
        self::assertNull($item->getTargetUser());
        self::assertNull($item->getCommentText());
    }
}
