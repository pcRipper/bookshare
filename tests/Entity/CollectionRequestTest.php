<?php

namespace App\Tests\Entity;

use App\Entity\Book;
use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use PHPUnit\Framework\TestCase;

class CollectionRequestTest extends TestCase
{
    public function testNewRequestDefaultsToPendingWithTimestamp(): void
    {
        $request = new CollectionRequest();

        self::assertSame(RequestStatus::Pending, $request->getStatus());
        self::assertNotNull($request->getRequestedAt());
        self::assertNull($request->getResolvedAt());
        self::assertNull($request->getDueDate());
        self::assertNull($request->getReturnedAt());
        self::assertNull($request->getDeclineMessage());
        self::assertCount(0, $request->getChildren());
    }

    public function testAddChildSetsBothSidesAndIsIdempotent(): void
    {
        $request = new CollectionRequest();
        $child = (new LibraryRequest())->setBook(new Book())->setRequester(new User());

        $request->addChild($child)->addChild($child);

        self::assertCount(1, $request->getChildren());
        self::assertSame($request, $child->getParentRequest());
    }

    public function testFluentStateSetters(): void
    {
        $due = new \DateTimeImmutable('2030-01-01');
        $request = (new CollectionRequest())
            ->setStatus(RequestStatus::Approved)
            ->setDueDate($due)
            ->setDeclineMessage(null);

        self::assertSame(RequestStatus::Approved, $request->getStatus());
        self::assertSame($due, $request->getDueDate());
    }
}
