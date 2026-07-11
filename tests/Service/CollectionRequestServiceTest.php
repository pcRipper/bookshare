<?php

namespace App\Tests\Service;

use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;
use App\Service\ActivityRecorder;
use App\Service\CollectionRequestService;
use App\Service\LibraryRequestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exercises the collection-borrow (whole-group) state machine: a borrow fans out
 * into one child LibraryRequest per selected book, and each owner/borrower
 * transition cascades to every child while the aggregate lives on the parent.
 * Security boundaries mirror the per-book machine.
 */
class CollectionRequestServiceTest extends TestCase
{
    /* ───────────────────────── createBorrow ───────────────────────── */

    public function testCreateBorrowFansOutIntoOneChildPerSelectedBook(): void
    {
        $owner = new User();
        $requester = new User();
        $b1 = $this->book($owner, BookStatus::Own, 1);
        $b2 = $this->book($owner, BookStatus::Own, 2);
        $collection = $this->collection($owner, [$b1, $b2]);

        $parent = $this->service()->createBorrow($requester, $collection, [1, 2]);

        self::assertSame($collection, $parent->getCollection());
        self::assertSame($requester, $parent->getRequester());
        self::assertSame(RequestStatus::Pending, $parent->getStatus());
        self::assertCount(2, $parent->getChildren());
        foreach ($parent->getChildren() as $child) {
            self::assertSame($parent, $child->getParentRequest());
            self::assertSame($requester, $child->getRequester());
            self::assertSame(RequestStatus::Pending, $child->getStatus());
        }
    }

    public function testCreateBorrowIgnoresBooksNotInTheCollection(): void
    {
        $owner = new User();
        $requester = new User();
        $b1 = $this->book($owner, BookStatus::Own, 1);
        $b2 = $this->book($owner, BookStatus::Own, 2);
        $collection = $this->collection($owner, [$b1, $b2]);

        // 999 isn't a member — only one valid book remains, tripping the ≥2 rule.
        $this->expectException(\DomainException::class);
        $this->service()->createBorrow($requester, $collection, [1, 999]);
    }

    public function testCannotBorrowYourOwnCollection(): void
    {
        $owner = new User();
        $collection = $this->collection($owner, [
            $this->book($owner, BookStatus::Own, 1),
            $this->book($owner, BookStatus::Own, 2),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('your own collection');
        $this->service()->createBorrow($owner, $collection, [1, 2]);
    }

    public function testCannotBorrowFewerThanTwoBooks(): void
    {
        $owner = new User();
        $collection = $this->collection($owner, [
            $this->book($owner, BookStatus::Own, 1),
            $this->book($owner, BookStatus::Own, 2),
        ]);

        $this->expectException(\DomainException::class);
        $this->service()->createBorrow(new User(), $collection, [1]);
    }

    public function testCannotBorrowWhenASelectedBookIsUnavailable(): void
    {
        $owner = new User();
        $b1 = $this->book($owner, BookStatus::Own, 1);
        $b2 = $this->book($owner, BookStatus::Lent, 2);
        $collection = $this->collection($owner, [$b1, $b2]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no longer available');
        $this->service()->createBorrow(new User(), $collection, [1, 2]);
    }

    public function testCreateBorrowPersistsTheParentRequest(): void
    {
        $owner = new User();
        $requester = new User();
        $collection = $this->collection($owner, [
            $this->book($owner, BookStatus::Own, 1),
            $this->book($owner, BookStatus::Own, 2),
        ]);

        // create() persists each child too, so capture every persist and assert
        // the parent is among them (rather than expecting a single call).
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $parent = $this->service($em)->createBorrow($requester, $collection, [1, 2]);

        self::assertContains($parent, $persisted);
    }

    public function testBorrowIsAllOrNothingWhenAChildGuardTrips(): void
    {
        // A private owner trips the per-book create() guard (reused per child), so
        // the whole borrow is rejected rather than partially created.
        $owner = (new User())->setIsPrivate(true);
        $collection = $this->collection($owner, [
            $this->book($owner, BookStatus::Own, 1),
            $this->book($owner, BookStatus::Own, 2),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('private');
        $this->service()->createBorrow(new User(), $collection, [1, 2]);
    }

    /* ───────────────────────── approve ───────────────────────── */

    public function testApproveByOwnerLendsEveryChildBook(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent, $books] = $this->pendingBorrow($owner, $requester);
        $due = new \DateTimeImmutable('2030-06-01');

        $this->service()->approve($parent, $owner, $due);

        self::assertSame(RequestStatus::Approved, $parent->getStatus());
        self::assertSame($due, $parent->getDueDate());
        self::assertNotNull($parent->getResolvedAt());
        foreach ($parent->getChildren() as $child) {
            self::assertSame(RequestStatus::Approved, $child->getStatus());
            self::assertSame($due, $child->getDueDate());
        }
        foreach ($books as $book) {
            self::assertSame(BookStatus::Lent, $book->getStatus());
            self::assertSame($requester, $book->getCurrentHolder());
            self::assertFalse($book->isHome());
        }
    }

    public function testApproveByNonOwnerIsDenied(): void
    {
        $owner = new User();
        [$parent] = $this->pendingBorrow($owner, new User());

        $this->expectException(AccessDeniedException::class);
        $this->service()->approve($parent, new User());
    }

    public function testApproveOnResolvedRequestIsRejected(): void
    {
        $owner = new User();
        [$parent] = $this->pendingBorrow($owner, new User());
        $parent->setStatus(RequestStatus::Approved);

        $this->expectException(\DomainException::class);
        $this->service()->approve($parent, $owner);
    }

    public function testApproveIsAllOrNothingWhenAMemberBookBecameUnavailable(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent, $books] = $this->pendingBorrow($owner, $requester);
        // The first member book was lent by another request after this borrow was
        // filed — the whole collection approve must fail rather than partial-lend.
        $books[0]->setStatus(BookStatus::Lent);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no longer available');
        $this->service()->approve($parent, $owner);
    }

    /* ───────────────────────── decline ───────────────────────── */

    public function testDeclineByOwnerDeclinesEveryChildAndStoresMessage(): void
    {
        $owner = new User();
        [$parent, $books] = $this->pendingBorrow($owner, new User());

        $this->service()->decline($parent, $owner, 'Not right now, sorry.');

        self::assertSame(RequestStatus::Declined, $parent->getStatus());
        self::assertNotNull($parent->getResolvedAt());
        self::assertSame('Not right now, sorry.', $parent->getDeclineMessage());
        foreach ($parent->getChildren() as $child) {
            self::assertSame(RequestStatus::Declined, $child->getStatus());
        }
        // Books never left the owner's hands.
        foreach ($books as $book) {
            self::assertTrue($book->isHome());
        }
    }

    public function testDeclineByNonOwnerIsDenied(): void
    {
        $owner = new User();
        [$parent] = $this->pendingBorrow($owner, new User());

        $this->expectException(AccessDeniedException::class);
        $this->service()->decline($parent, new User());
    }

    /* ─────────────────────── requestReturn ─────────────────────── */

    public function testRequestReturnByBorrowerMovesWholeGroupToReturnPending(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);
        $this->service()->approve($parent, $owner);

        $this->service()->requestReturn($parent, $requester);

        self::assertSame(RequestStatus::ReturnPending, $parent->getStatus());
        foreach ($parent->getChildren() as $child) {
            self::assertSame(RequestStatus::ReturnPending, $child->getStatus());
        }
    }

    public function testRequestReturnByNonRequesterIsDenied(): void
    {
        $owner = new User();
        [$parent] = $this->pendingBorrow($owner, new User());
        $this->service()->approve($parent, $owner);

        $this->expectException(AccessDeniedException::class);
        $this->service()->requestReturn($parent, $owner);
    }

    public function testRequestReturnOnInactiveLoanIsRejected(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);

        $this->expectException(\DomainException::class);
        $this->service()->requestReturn($parent, $requester);
    }

    /* ─────────────────────── confirmReturn ─────────────────────── */

    public function testConfirmReturnByOwnerClosesEveryChildLoan(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent, $books] = $this->pendingBorrow($owner, $requester);
        $this->service()->approve($parent, $owner);
        $this->service()->requestReturn($parent, $requester);

        $this->service()->confirmReturn($parent, $owner);

        self::assertSame(RequestStatus::Returned, $parent->getStatus());
        self::assertNotNull($parent->getReturnedAt());
        foreach ($parent->getChildren() as $child) {
            self::assertSame(RequestStatus::Returned, $child->getStatus());
        }
        foreach ($books as $book) {
            self::assertSame(BookStatus::Own, $book->getStatus());
            self::assertSame($owner, $book->getCurrentHolder());
            self::assertTrue($book->isHome());
        }
    }

    public function testConfirmReturnByNonOwnerIsDenied(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);
        $this->service()->approve($parent, $owner);

        $this->expectException(AccessDeniedException::class);
        $this->service()->confirmReturn($parent, $requester);
    }

    public function testConfirmReturnDirectlyFromApprovedClosesEveryChildLoan(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent, $books] = $this->pendingBorrow($owner, $requester);
        $this->service()->approve($parent, $owner);

        // The owner can close an active loan without a borrower return request first.
        $this->service()->confirmReturn($parent, $owner);

        self::assertSame(RequestStatus::Returned, $parent->getStatus());
        self::assertNotNull($parent->getReturnedAt());
        foreach ($parent->getChildren() as $child) {
            self::assertSame(RequestStatus::Returned, $child->getStatus());
        }
        foreach ($books as $book) {
            self::assertTrue($book->isHome());
        }
    }

    public function testConfirmReturnOnInactiveLoanIsRejected(): void
    {
        $owner = new User();
        [$parent] = $this->pendingBorrow($owner, new User());

        // Still pending — never approved, so there's no live loan to confirm.
        $this->expectException(\DomainException::class);
        $this->service()->confirmReturn($parent, $owner);
    }

    /* ───────────────────────── cancel ───────────────────────── */

    public function testCancelByRequesterRemovesParentRequest(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($parent);

        $this->service($em)->cancel($parent, $requester);
    }

    public function testCancelByNonRequesterIsDenied(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);

        $this->expectException(AccessDeniedException::class);
        $this->service()->cancel($parent, $owner);
    }

    public function testCancelOnApprovedRequestIsRejected(): void
    {
        $owner = new User();
        $requester = new User();
        [$parent] = $this->pendingBorrow($owner, $requester);
        $this->service()->approve($parent, $owner);

        $this->expectException(\DomainException::class);
        $this->service()->cancel($parent, $requester);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function service(?EntityManagerInterface $em = null): CollectionRequestService
    {
        $em ??= $this->createStub(EntityManagerInterface::class);

        $repo = $this->createStub(LibraryRequestRepository::class);
        $repo->method('findPendingForBookAndRequester')->willReturn(null);

        $libraryRequests = new LibraryRequestService(
            $em,
            $repo,
            $this->createStub(ActivityRecorder::class),
        );

        return new CollectionRequestService($em, $libraryRequests);
    }

    /** @param Book[] $books */
    private function collection(User $owner, array $books): BookCollection
    {
        $collection = (new BookCollection())->setOwner($owner);
        foreach ($books as $book) {
            $collection->addBook($book);
        }

        return $collection;
    }

    private function book(User $owner, BookStatus $status, int $id): Book
    {
        $book = (new Book())->setOwner($owner)->setStatus($status);
        $ref = new \ReflectionProperty(Book::class, 'id');
        $ref->setValue($book, $id);

        return $book;
    }

    /**
     * Builds a pending collection borrow (two available books) ready for the
     * transition tests.
     *
     * @return array{0: CollectionRequest, 1: Book[]}
     */
    private function pendingBorrow(User $owner, User $requester): array
    {
        $books = [
            $this->book($owner, BookStatus::Own, 1),
            $this->book($owner, BookStatus::Own, 2),
        ];
        $collection = $this->collection($owner, $books);
        $parent = $this->service()->createBorrow($requester, $collection, [1, 2]);

        return [$parent, $books];
    }
}
