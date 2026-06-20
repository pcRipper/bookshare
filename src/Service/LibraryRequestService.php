<?php

namespace App\Service;

use App\Entity\Book;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
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

    public function create(User $requester, Book $book): LibraryRequest
    {
        if ($book->getOwner() === $requester) {
            throw new \DomainException('You cannot request your own book.');
        }
        if ($this->requests->findPendingForBookAndRequester((int) $book->getId(), $requester)) {
            throw new \DomainException('You already have a pending request for this book.');
        }

        $request = (new LibraryRequest())
            ->setBook($book)
            ->setRequester($requester);

        $this->em->persist($request);

        return $request;
    }

    public function approve(LibraryRequest $request, User $actor): void
    {
        $this->assertOwner($request, $actor);
        $this->assertPending($request);

        $request->setStatus(RequestStatus::Approved)->setResolvedAt(new \DateTimeImmutable());
        $request->getBook()->setStatus(BookStatus::Lent);

        $this->activity->record(
            $request->getRequester(),
            ActivityType::Borrowed,
            targetBook: $request->getBook(),
            targetUser: $actor,
        );
    }

    public function decline(LibraryRequest $request, User $actor): void
    {
        $this->assertOwner($request, $actor);
        $this->assertPending($request);

        $request->setStatus(RequestStatus::Declined)->setResolvedAt(new \DateTimeImmutable());
    }

    private function assertOwner(LibraryRequest $request, User $actor): void
    {
        if ($request->getBook()->getOwner() !== $actor) {
            throw new AccessDeniedException('You do not own the requested book.');
        }
    }

    private function assertPending(LibraryRequest $request): void
    {
        if ($request->getStatus() !== RequestStatus::Pending) {
            throw new \DomainException('This request has already been resolved.');
        }
    }
}
