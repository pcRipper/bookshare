<?php

namespace App\Tests\Entity;

use App\Entity\LibraryRequest;
use App\Entity\LibraryRequestEvent;
use App\Entity\User;
use App\Enum\LibraryRequestEventType;
use PHPUnit\Framework\TestCase;

class LibraryRequestEventTest extends TestCase
{
    public function testCreatedAtIsStampedOnConstruction(): void
    {
        $event = new LibraryRequestEvent();

        self::assertInstanceOf(\DateTimeImmutable::class, $event->getCreatedAt());
        self::assertNull($event->getId());
    }

    public function testSettersAreFluentAndStore(): void
    {
        $request = new LibraryRequest();
        $actor = new User();
        $due = new \DateTimeImmutable('2031-12-31');

        $event = (new LibraryRequestEvent())
            ->setRequest($request)
            ->setType(LibraryRequestEventType::Approved)
            ->setActor($actor)
            ->setDueDate($due);

        self::assertSame($request, $event->getRequest());
        self::assertSame(LibraryRequestEventType::Approved, $event->getType());
        self::assertSame($actor, $event->getActor());
        self::assertSame($due, $event->getDueDate());
    }
}
