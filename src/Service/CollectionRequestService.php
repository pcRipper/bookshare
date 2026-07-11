<?php

namespace App\Service;

use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Collection-borrow lifecycle. A collection is borrowed as a whole: the owner
 * approves/declines/confirms-return once and the aggregate state lives on the
 * parent {@see CollectionRequest}, while each selected book fans out into a
 * child {@see \App\Entity\LibraryRequest} driven through the existing per-book
 * state machine ({@see LibraryRequestService}).
 *
 * Like its per-book sibling: methods mutate/persist but never flush (the
 * controller owns the transaction); ownership violations throw
 * AccessDeniedException (→403), business-rule violations throw \DomainException
 * (→409). No Mercure signal is published here — the controller publishes exactly
 * one collection-level signal after flush, so a borrow never fans out into one
 * notification per book.
 */
class CollectionRequestService
{
    /** A collection borrow must cover at least this many books. */
    public const MIN_BOOKS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryRequestService $libraryRequests,
    ) {}

    /**
     * Borrows a selection of a collection's books as one grouped request.
     *
     * @param int[] $bookIds the subset of the collection the borrower selected
     */
    public function createBorrow(User $requester, BookCollection $collection, array $bookIds): CollectionRequest
    {
        if ($collection->getOwner() === $requester) {
            throw new \DomainException('You cannot borrow your own collection.');
        }

        // Resolve the selected ids against the collection's members, preserving
        // uniqueness and membership — anything not in the collection is ignored.
        $selected = [];
        foreach ($collection->getBooks() as $book) {
            if (in_array((int) $book->getId(), $bookIds, true)) {
                $selected[(int) $book->getId()] = $book;
            }
        }

        if (count($selected) < self::MIN_BOOKS) {
            throw new \DomainException('Select at least ' . self::MIN_BOOKS . ' available books to borrow a collection.');
        }
        foreach ($selected as $book) {
            if ($book->getStatus() !== BookStatus::Own) {
                throw new \DomainException('One of the selected books is no longer available to borrow.');
            }
        }

        $parent = (new CollectionRequest())
            ->setCollection($collection)
            ->setRequester($requester);

        // Each child runs through the standard borrow guards + audit event.
        foreach ($selected as $book) {
            $child = $this->libraryRequests->create($requester, $book, $parent);
            $parent->addChild($child);
        }

        $this->em->persist($parent);

        return $parent;
    }

    public function approve(CollectionRequest $request, User $actor, ?\DateTimeImmutable $dueDate = null): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Pending], 'This request has already been resolved.');

        foreach ($request->getChildren() as $child) {
            $this->libraryRequests->approve($child, $actor, $dueDate);
        }

        $request
            ->setStatus(RequestStatus::Approved)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setDueDate($dueDate);
    }

    public function decline(CollectionRequest $request, User $actor, ?string $message = null): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Pending], 'This request has already been resolved.');

        foreach ($request->getChildren() as $child) {
            $this->libraryRequests->decline($child, $actor, $message);
        }

        $request
            ->setStatus(RequestStatus::Declined)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setDeclineMessage($message);
    }

    /** The borrower withdraws a still-pending collection request; children cascade away. */
    public function cancel(CollectionRequest $request, User $actor): void
    {
        $this->assertRequester($request, $actor);
        $this->assertStatusIn(
            $request,
            [RequestStatus::Pending],
            'This request can no longer be withdrawn — it has already been answered.',
        );

        $this->em->remove($request);
    }

    /** The borrower signals the whole collection has been returned. */
    public function requestReturn(CollectionRequest $request, User $actor): void
    {
        $this->assertRequester($request, $actor);
        $this->assertStatusIn($request, [RequestStatus::Approved], 'This loan is not active.');

        foreach ($request->getChildren() as $child) {
            $this->libraryRequests->requestReturn($child, $actor);
        }

        $request->setStatus(RequestStatus::ReturnPending);
    }

    /** The owner confirms the whole collection is back, closing every child loan. */
    public function confirmReturn(CollectionRequest $request, User $actor): void
    {
        $this->assertOwner($request, $actor);
        $this->assertStatusIn(
            $request,
            [RequestStatus::ReturnPending, RequestStatus::Approved],
            'This loan is not active.',
        );

        foreach ($request->getChildren() as $child) {
            $this->libraryRequests->confirmReturn($child, $actor);
        }

        $request->setStatus(RequestStatus::Returned)->setReturnedAt(new \DateTimeImmutable());
    }

    private function assertOwner(CollectionRequest $request, User $actor): void
    {
        if ($request->getCollection()->getOwner() !== $actor) {
            throw new AccessDeniedException('You do not own this collection.');
        }
    }

    private function assertRequester(CollectionRequest $request, User $actor): void
    {
        if ($request->getRequester() !== $actor) {
            throw new AccessDeniedException('This is not your borrow request.');
        }
    }

    /** @param RequestStatus[] $allowed */
    private function assertStatusIn(CollectionRequest $request, array $allowed, string $message): void
    {
        if (!in_array($request->getStatus(), $allowed, true)) {
            throw new \DomainException($message);
        }
    }
}
