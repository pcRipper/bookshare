<?php

namespace App\Tests\Entity;

use App\Entity\LibraryRequest;
use App\Entity\LibraryRequestEvent;
use App\Entity\User;
use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use PHPUnit\Framework\TestCase;

class LibraryRequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $request = new LibraryRequest();

        self::assertNull($request->getId());
        self::assertSame(RequestStatus::Pending, $request->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $request->getRequestedAt());
        self::assertNull($request->getResolvedAt());
        self::assertNull($request->getDueDate());
        self::assertNull($request->getReturnedAt());
        self::assertCount(0, $request->getEvents());
    }

    public function testAddEventWiresBothSidesAndAppends(): void
    {
        $actor = new User();
        $request = new LibraryRequest();

        $event = $request->addEvent(LibraryRequestEventType::Requested, $actor);

        self::assertInstanceOf(LibraryRequestEvent::class, $event);
        self::assertSame($request, $event->getRequest(), 'event should point back at its request');
        self::assertSame(LibraryRequestEventType::Requested, $event->getType());
        self::assertSame($actor, $event->getActor());
        self::assertNull($event->getDueDate());
        self::assertCount(1, $request->getEvents());
        self::assertSame($event, $request->getEvents()->first());
    }

    public function testAddEventCarriesDueDateForApproval(): void
    {
        $actor = new User();
        $due = new \DateTimeImmutable('2030-01-01');
        $request = new LibraryRequest();

        $event = $request->addEvent(LibraryRequestEventType::Approved, $actor, $due);

        self::assertSame($due, $event->getDueDate());
    }

    public function testEventsPreserveInsertionOrder(): void
    {
        $actor = new User();
        $request = new LibraryRequest();

        $request->addEvent(LibraryRequestEventType::Requested, $actor);
        $request->addEvent(LibraryRequestEventType::Approved, $actor);
        $request->addEvent(LibraryRequestEventType::Returned, $actor);

        $types = array_map(
            static fn (LibraryRequestEvent $e) => $e->getType(),
            $request->getEvents()->toArray(),
        );

        self::assertSame(
            [LibraryRequestEventType::Requested, LibraryRequestEventType::Approved, LibraryRequestEventType::Returned],
            $types,
        );
    }

    public function testStatusAndDateSettersAreFluent(): void
    {
        $resolved = new \DateTimeImmutable('2029-05-05');
        $request = (new LibraryRequest())
            ->setStatus(RequestStatus::Approved)
            ->setResolvedAt($resolved);

        self::assertSame(RequestStatus::Approved, $request->getStatus());
        self::assertSame($resolved, $request->getResolvedAt());
    }
}
