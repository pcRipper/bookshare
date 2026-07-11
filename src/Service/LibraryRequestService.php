<?php

namespace App\Service;

use App\Entity\Book;
use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Borrow-request lifecycle. Methods persist/mutate but never flush —
 * the controller flushes once per request. Ownership violations throw
 * AccessDeniedException (kernel maps to 403); business-rule violations
 * throw \DomainException (controller maps to 409).
 */
class LibraryRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryRequestRepository $requests,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Creates a pending borrow request. When $parent is set the request is one
     * book of a collection borrow — it's linked to the parent CollectionRequest
     * and surfaced grouped rather than as an individual request. All the same
     * borrow guards apply per book.
     */
    public function create(User $requester, Book $book, ?CollectionRequest $parent = null): LibraryRequest
    {
        if ($book->getOwner() === $requester) {
            throw new \DomainException('You cannot request your own book.');
        }
        if ($book->getOwner()->isPrivate()) {
            throw new \DomainException('This reader\'s library is private.');
        }
        $ownerSettings = $book->getOwner()->getSettings();
        if ($ownerSettings !== null && !$ownerSettings->allowsRequests()) {
            throw new \DomainException('This reader isn\'t accepting borrow requests right now.');
        }
        if ($book->getStatus() !== BookStatus::Own) {
            throw new \DomainException('This book is not available to borrow right now.');
        }
        if ($this->requests->findPendingForBookAndRequester((int) $book->getId(), $requester)) {
            throw new \DomainException('You already have a pending request for this book.');
        }

        $request = (new LibraryRequest())
            ->setBook($book)
            ->setRequester($requester)
            ->setParentRequest($parent);
        $request->addEvent(LibraryRequestEventType::Requested, $requester);

        $this->em->persist($request);

        return $request;
    }

    public function approve(LibraryRequest $request, User $actor, ?\DateTimeImmutable $dueDate = null): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Pending], 'This request has already been resolved.');

        $request
            ->setStatus(RequestStatus::Approved)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setDueDate($dueDate);
        $request->addEvent(LibraryRequestEventType::Approved, $actor, $dueDate);

        // The book is now lent: it leaves the owner's hands for the borrower's.
        $book = $request->getBook();
        $book->setStatus(BookStatus::Lent);
        $book->setCurrentHolder($request->getRequester());

        $this->activity->record(
            $request->getRequester(),
            ActivityType::Borrowed,
            targetBook: $request->getBook(),
            targetUser: $actor,
        );
    }

    public function decline(LibraryRequest $request, User $actor, ?string $message = null): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Pending], 'This request has already been resolved.');

        $request->setStatus(RequestStatus::Declined)->setResolvedAt(new \DateTimeImmutable());
        $request->addEvent(LibraryRequestEventType::Declined, $actor, message: $message);
    }

    /**
     * The borrower withdraws their own request. Only a still-pending request can be
     * withdrawn — once the owner has approved (or otherwise resolved) it, the action
     * is rejected. The request is deleted outright (its events cascade away via the
     * FK), leaving no trace, rather than parked in a tombstone status.
     */
    public function cancel(LibraryRequest $request, User $actor): void
    {
        $this->assertRequester($request, $actor);
        $this->assertStatusIn(
            $request,
            [RequestStatus::Pending],
            'This request can no longer be withdrawn — it has already been answered.',
        );

        $this->em->remove($request);
    }

    /** The borrower signals they've returned the book; awaits the owner's confirmation. */
    public function requestReturn(LibraryRequest $request, User $actor): void
    {
        $this->assertRequester($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Approved], 'This loan is not active.');

        // The book stays Lent (and in the borrower's hands) until the owner
        // confirms physical receipt.
        $request->setStatus(RequestStatus::ReturnPending);
        $request->addEvent(LibraryRequestEventType::ReturnRequested, $actor);
    }

    /**
     * The owner confirms the book was received back, closing the loan and freeing
     * the book. Allowed from ReturnPending or directly from Approved, so the owner
     * can always close a loan even if the borrower never marked it returned.
     */
    public function confirmReturn(LibraryRequest $request, User $actor): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn(
            $request,
            [RequestStatus::ReturnPending, RequestStatus::Approved],
            'This loan is not active.',
        );

        $request->setStatus(RequestStatus::Returned)->setReturnedAt(new \DateTimeImmutable());
        $request->addEvent(LibraryRequestEventType::Returned, $actor);

        // The loan closes: the book is back in the owner's hands and available.
        $book = $request->getBook();
        $book->setStatus(BookStatus::Own);
        $book->setCurrentHolder($book->getOwner());

        $this->activity->record(
            $request->getRequester(),
            ActivityType::Returned,
            targetBook: $request->getBook(),
            targetUser: $actor,
        );
    }

    private function assertOwner(LibraryRequest $request, User $actor): void
    {
        if ($request->getBook()->getOwner() !== $actor) {
            throw new AccessDeniedException('You do not own the requested book.');
        }
    }

    private function assertRequester(LibraryRequest $request, User $actor): void
    {
        if ($request->getRequester() !== $actor) {
            throw new AccessDeniedException('This is not your borrow request.');
        }
    }

    /** @param RequestStatus[] $allowed */
    private function assertStatusIn(LibraryRequest $request, array $allowed, string $message): void
    {
        if (!in_array($request->getStatus(), $allowed, true)) {
            throw new \DomainException($message);
        }
    }
}
