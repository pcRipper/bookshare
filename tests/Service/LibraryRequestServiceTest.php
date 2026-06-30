<?php

namespace App\Tests\Service;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;
use App\Service\ActivityRecorder;
use App\Service\LibraryRequestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exercises the borrow-request state machine, with particular attention to the
 * security boundaries: only an owner may approve/decline/confirm, only the
 * requester may mark a return, and every transition is guarded by the current
 * status so requests can't be replayed or skipped.
 */
class LibraryRequestServiceTest extends TestCase
{
    /* ───────────────────────── create ───────────────────────── */

    public function testCreateSucceedsForAvailableBook(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(LibraryRequest::class));

        $repo = $this->createStub(LibraryRequestRepository::class);
        $repo->method('findPendingForBookAndRequester')->willReturn(null);

        $request = $this->service($em, $repo)->create($requester, $book);

        self::assertSame($book, $request->getBook());
        self::assertSame($requester, $request->getRequester());
        self::assertSame(RequestStatus::Pending, $request->getStatus());
        self::assertCount(1, $request->getEvents());
        $event = $request->getEvents()->first();
        self::assertSame(LibraryRequestEventType::Requested, $event->getType());
        self::assertSame($requester, $event->getActor());
    }

    public function testCannotRequestYourOwnBook(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);

        $this->expectException(\DomainException::class);
        $this->service()->create($owner, $book);
    }

    public function testCannotRequestFromAPrivateLibrary(): void
    {
        $owner = (new User())->setIsPrivate(true);
        $book = $this->book($owner, BookStatus::Own);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('private');
        $this->service()->create(new User(), $book);
    }

    public function testCannotRequestWhenOwnerHasDisabledRequests(): void
    {
        $owner = new User();
        $owner->setSettings((new \App\Entity\UserSettings())->setAllowRequests(false));
        $book = $this->book($owner, BookStatus::Own);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('borrow requests');
        $this->service()->create(new User(), $book);
    }

    public function testCannotRequestABookThatIsNotAvailable(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Lent);

        $this->expectException(\DomainException::class);
        $this->service()->create(new User(), $book);
    }

    public function testCannotRequestABookTheOwnerIsCurrentlyReading(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::CurrentlyReading);

        $this->expectException(\DomainException::class);
        $this->service()->create(new User(), $book);
    }

    public function testCannotRequestWhenAPendingRequestAlreadyExists(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);

        $repo = $this->createStub(LibraryRequestRepository::class);
        $repo->method('findPendingForBookAndRequester')->willReturn(new LibraryRequest());

        $this->expectException(\DomainException::class);
        $this->service($this->createStub(EntityManagerInterface::class), $repo)->create($requester, $book);
    }

    /* ───────────────────────── approve ───────────────────────── */

    public function testApproveByOwnerLendsBookAndRecordsActivity(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, $requester, RequestStatus::Pending);
        $due = new \DateTimeImmutable('2030-06-01');

        $recorded = null;
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturnCallback(
            function (User $actor, ActivityType $type, ?Book $targetBook = null, ?User $targetUser = null) use (&$recorded) {
                $recorded = compact('actor', 'type', 'targetBook', 'targetUser');

                return new ActivityItem();
            },
        );

        $this->service($this->createStub(EntityManagerInterface::class), $this->createStub(LibraryRequestRepository::class), $activity)
            ->approve($request, $owner, $due);

        self::assertSame(RequestStatus::Approved, $request->getStatus());
        self::assertNotNull($request->getResolvedAt());
        self::assertSame($due, $request->getDueDate());
        self::assertSame(BookStatus::Lent, $book->getStatus());
        self::assertSame($requester, $book->getCurrentHolder());
        self::assertFalse($book->isHome());

        $approvedEvent = $request->getEvents()->last();
        self::assertSame(LibraryRequestEventType::Approved, $approvedEvent->getType());
        self::assertSame($owner, $approvedEvent->getActor());
        self::assertSame($due, $approvedEvent->getDueDate());

        // Activity is attributed to the borrower, targeting the lender.
        self::assertSame($requester, $recorded['actor']);
        self::assertSame(ActivityType::Borrowed, $recorded['type']);
        self::assertSame($book, $recorded['targetBook']);
        self::assertSame($owner, $recorded['targetUser']);
    }

    public function testApproveByNonOwnerIsDenied(): void
    {
        $owner = new User();
        $attacker = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Pending);

        $this->expectException(AccessDeniedException::class);
        $this->service()->approve($request, $attacker);
    }

    public function testApproveOnAlreadyResolvedRequestIsRejected(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $request = $this->request($book, new User(), RequestStatus::Approved);

        $this->expectException(\DomainException::class);
        $this->service()->approve($request, $owner);
    }

    /* ───────────────────────── decline ───────────────────────── */

    public function testDeclineByOwnerSetsDeclinedStatusAndEvent(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Pending);

        $this->service()->decline($request, $owner);

        self::assertSame(RequestStatus::Declined, $request->getStatus());
        self::assertNotNull($request->getResolvedAt());
        self::assertSame(LibraryRequestEventType::Declined, $request->getEvents()->last()->getType());
        // The book never left the owner's hands.
        self::assertTrue($book->isHome());
    }

    public function testDeclineWithMessageStoresItOnTheEvent(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Pending);

        $this->service()->decline($request, $owner, 'Sorry, I just lent it to someone else.');

        $event = $request->getEvents()->last();
        self::assertSame(LibraryRequestEventType::Declined, $event->getType());
        self::assertSame('Sorry, I just lent it to someone else.', $event->getMessage());
    }

    public function testDeclineByNonOwnerIsDenied(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Pending);

        $this->expectException(AccessDeniedException::class);
        $this->service()->decline($request, new User());
    }

    public function testDeclineOnResolvedRequestIsRejected(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Declined);

        $this->expectException(\DomainException::class);
        $this->service()->decline($request, $owner);
    }

    /* ───────────────────────── cancel ───────────────────────── */

    public function testCancelByRequesterRemovesRequest(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, $requester, RequestStatus::Pending);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($request);

        $this->service($em)->cancel($request, $requester);
    }

    public function testCancelByNonRequesterIsDenied(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, $requester, RequestStatus::Pending);

        // Even the book owner cannot withdraw the borrower's request.
        $this->expectException(AccessDeniedException::class);
        $this->service()->cancel($request, $owner);
    }

    public function testCancelOnApprovedRequestIsRejected(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $request = $this->request($book, $requester, RequestStatus::Approved);

        // Once approved the borrow is committed — it can no longer be withdrawn.
        $this->expectException(\DomainException::class);
        $this->service()->cancel($request, $requester);
    }

    public function testCancelOnDeclinedRequestIsRejected(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, $requester, RequestStatus::Declined);

        $this->expectException(\DomainException::class);
        $this->service()->cancel($request, $requester);
    }

    /* ─────────────────────── requestReturn ─────────────────────── */

    public function testRequestReturnByBorrowerMovesToReturnPending(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $book->setCurrentHolder($requester);
        $request = $this->request($book, $requester, RequestStatus::Approved);

        $this->service()->requestReturn($request, $requester);

        self::assertSame(RequestStatus::ReturnPending, $request->getStatus());
        self::assertSame(LibraryRequestEventType::ReturnRequested, $request->getEvents()->last()->getType());
        // Still in the borrower's hands until the owner confirms receipt.
        self::assertSame($requester, $book->getCurrentHolder());
    }

    public function testRequestReturnByNonRequesterIsDenied(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $request = $this->request($book, $requester, RequestStatus::Approved);

        // Even the owner cannot mark the borrower's return.
        $this->expectException(AccessDeniedException::class);
        $this->service()->requestReturn($request, $owner);
    }

    public function testRequestReturnOnInactiveLoanIsRejected(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, $requester, RequestStatus::Pending);

        $this->expectException(\DomainException::class);
        $this->service()->requestReturn($request, $requester);
    }

    /* ─────────────────────── confirmReturn ─────────────────────── */

    public function testConfirmReturnFromReturnPendingClosesLoan(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $book->setCurrentHolder($requester);
        $request = $this->request($book, $requester, RequestStatus::ReturnPending);

        $recorded = null;
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturnCallback(
            function (User $actor, ActivityType $type, ?Book $targetBook = null, ?User $targetUser = null) use (&$recorded) {
                $recorded = compact('actor', 'type', 'targetBook', 'targetUser');

                return new ActivityItem();
            },
        );

        $this->service($this->createStub(EntityManagerInterface::class), $this->createStub(LibraryRequestRepository::class), $activity)
            ->confirmReturn($request, $owner);

        self::assertSame(RequestStatus::Returned, $request->getStatus());
        self::assertNotNull($request->getReturnedAt());
        self::assertSame(BookStatus::Own, $book->getStatus());
        self::assertSame($owner, $book->getCurrentHolder());
        self::assertTrue($book->isHome());
        self::assertSame(LibraryRequestEventType::Returned, $request->getEvents()->last()->getType());

        self::assertSame(ActivityType::Returned, $recorded['type']);
        self::assertSame($book, $recorded['targetBook']);
    }

    public function testConfirmReturnDirectlyFromApprovedIsAllowed(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $book->setCurrentHolder($requester);
        $request = $this->request($book, $requester, RequestStatus::Approved);

        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturn(new ActivityItem());

        $this->service($this->createStub(EntityManagerInterface::class), $this->createStub(LibraryRequestRepository::class), $activity)
            ->confirmReturn($request, $owner);

        self::assertSame(RequestStatus::Returned, $request->getStatus());
        self::assertSame($owner, $book->getCurrentHolder());
    }

    public function testConfirmReturnByNonOwnerIsDenied(): void
    {
        $owner = new User();
        $requester = new User();
        $book = $this->book($owner, BookStatus::Lent);
        $request = $this->request($book, $requester, RequestStatus::ReturnPending);

        // The borrower cannot confirm their own return.
        $this->expectException(AccessDeniedException::class);
        $this->service()->confirmReturn($request, $requester);
    }

    public function testConfirmReturnOnInactiveLoanIsRejected(): void
    {
        $owner = new User();
        $book = $this->book($owner, BookStatus::Own);
        $request = $this->request($book, new User(), RequestStatus::Returned);

        $this->expectException(\DomainException::class);
        $this->service()->confirmReturn($request, $owner);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function service(
        ?EntityManagerInterface $em = null,
        ?LibraryRequestRepository $repo = null,
        ?ActivityRecorder $activity = null,
    ): LibraryRequestService {
        return new LibraryRequestService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $repo ?? $this->createStub(LibraryRequestRepository::class),
            $activity ?? $this->createStub(ActivityRecorder::class),
        );
    }

    private function book(User $owner, BookStatus $status): Book
    {
        return (new Book())->setOwner($owner)->setStatus($status);
    }

    private function request(Book $book, User $requester, RequestStatus $status): LibraryRequest
    {
        return (new LibraryRequest())->setBook($book)->setRequester($requester)->setStatus($status);
    }
}
