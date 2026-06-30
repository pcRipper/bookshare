<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Entity\Book;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Repository\BookRepository;
use App\Repository\LibraryRequestRepository;
use App\Service\LibraryRequestService;
use App\Service\LoanEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/requests')]
class LibraryRequestRestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly LibraryRequestService $service,
        private readonly EntityManagerInterface $em,
        private readonly LoanEventPublisher $publisher,
    ) {}

    /**
     * Incoming requests for the current user's books (owner side).
     * ?status=open|pending|active|resolved
     */
    #[Route('/incoming', methods: ['GET'])]
    public function incoming(Request $request, LibraryRequestRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $statuses = $this->statusFilter($request->query->get('status', 'open'));
        if ($statuses === null) {
            return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->mapper->requests($repo->findIncoming($user, $statuses)));
    }

    /**
     * Outgoing requests made by the current user (borrower side).
     * ?status=active|pending|resolved — powers the "Borrowing" view.
     */
    #[Route('/outgoing', methods: ['GET'])]
    public function outgoing(Request $request, LibraryRequestRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $statuses = $this->statusFilter($request->query->get('status', 'active'));
        if ($statuses === null) {
            return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->mapper->requests($repo->findOutgoing($user, $statuses)));
    }

    /**
     * Maps a status keyword to the matching RequestStatus set, shared by the
     * incoming and outgoing listings.
     *
     * @return RequestStatus[]|null null ⇒ unknown keyword
     */
    private function statusFilter(string $keyword): ?array
    {
        return match ($keyword) {
            'pending'  => [RequestStatus::Pending],
            'open'     => [RequestStatus::Pending, RequestStatus::ReturnPending],
            'active'   => [RequestStatus::Approved, RequestStatus::ReturnPending],
            'resolved' => [RequestStatus::Declined, RequestStatus::Returned],
            // Every state — powers the History views, which show the full timeline
            // of each loan whether it's in progress or finished.
            'all'      => [
                RequestStatus::Pending,
                RequestStatus::Approved,
                RequestStatus::Declined,
                RequestStatus::ReturnPending,
                RequestStatus::Returned,
            ],
            default    => null,
        };
    }

    /** Create a borrow request for a book (used by public profile "Request to Borrow"). */
    #[Route('', methods: ['POST'])]
    public function create(Request $request, BookRepository $books): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $bookId = $request->toArray()['bookId'] ?? null;
        if (!$bookId) {
            return $this->json(['error' => 'Missing bookId.'], Response::HTTP_BAD_REQUEST);
        }

        $book = $books->find($bookId);
        if (!$book instanceof Book) {
            return $this->json(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $libraryRequest = $this->service->create($user, $book);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        // After commit: signal the book owner that a request landed.
        $this->publisher->publishLoanSignal($libraryRequest, LoanEventPublisher::REQUEST_RECEIVED);

        return $this->json($this->mapper->request($libraryRequest), Response::HTTP_CREATED);
    }

    /** Owner approves a borrow request, optionally setting a return due date. */
    #[Route('/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, LibraryRequest $libraryRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Optional lender-set due date (ISO `YYYY-MM-DD`); blank/absent ⇒ none.
        $dueDate = null;
        $raw = $request->toArray()['dueDate'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw)) ?: null;
            if ($dueDate === null) {
                return $this->json(['error' => 'Invalid due date.'], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->runLoanAction(fn () => $this->service->approve($libraryRequest, $user, $dueDate), $libraryRequest, LoanEventPublisher::REQUEST_APPROVED);
    }

    /** Owner declines a borrow request, optionally with a short note for the borrower. */
    #[Route('/{id}/decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decline(Request $request, LibraryRequest $libraryRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Optional decline note (blank/absent ⇒ none).
        $payload = json_decode($request->getContent() ?: 'null', true);
        $message = is_array($payload) ? ($payload['message'] ?? null) : null;
        if (is_string($message)) {
            $message = trim($message);
            if ($message === '') {
                $message = null;
            } elseif (mb_strlen($message) > 255) {
                return $this->json(['error' => 'Message is too long (max 255 characters).'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $message = null;
        }

        return $this->runLoanAction(fn () => $this->service->decline($libraryRequest, $user, $message), $libraryRequest, LoanEventPublisher::REQUEST_DECLINED);
    }

    /** Borrower withdraws their own pending request, deleting it. */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(LibraryRequest $libraryRequest): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Capture before deletion: after flush the request id is null and the row gone.
        $ownerId = $libraryRequest->getBook()->getOwner()->getId();
        $requestId = $libraryRequest->getId();

        try {
            $this->service->cancel($libraryRequest, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        // After commit: signal the book owner so their incoming inbox refetches.
        if ($ownerId !== null) {
            $this->publisher->publishToUser($ownerId, LoanEventPublisher::REQUEST_CANCELLED, $requestId);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Borrower marks the book as returned, awaiting the owner's confirmation. */
    #[Route('/{id}/return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function return(LibraryRequest $libraryRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->runLoanAction(fn () => $this->service->requestReturn($libraryRequest, $user), $libraryRequest, LoanEventPublisher::RETURN_REQUESTED);
    }

    /** Owner confirms the book was received back, closing the loan. */
    #[Route('/{id}/confirm-return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmReturn(LibraryRequest $libraryRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->runLoanAction(fn () => $this->service->confirmReturn($libraryRequest, $user), $libraryRequest, LoanEventPublisher::RETURN_CONFIRMED);
    }

    /**
     * Runs a loan-state transition, mapping business-rule violations to 409 and
     * flushing once on success. Ownership violations (AccessDeniedException) bubble
     * up to the kernel as 403.
     */
    private function runLoanAction(callable $action, LibraryRequest $libraryRequest, string $signalReason): JsonResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        // After commit: signal the affected party so their SPA refetches.
        $this->publisher->publishLoanSignal($libraryRequest, $signalReason);

        return $this->json($this->mapper->request($libraryRequest));
    }
}
