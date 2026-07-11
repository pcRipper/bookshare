<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\CollectionBorrowInput;
use App\Dto\Pagination;
use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Repository\CollectionRepository;
use App\Repository\CollectionRequestRepository;
use App\Service\CollectionRequestService;
use App\Service\LoanEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/collection-requests')]
class CollectionRequestRestController extends AbstractController
{
    /** Collection borrows per page in the History views (in-flight slices stay bare). */
    private const HISTORY_PER_PAGE = 20;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly CollectionRequestService $service,
        private readonly EntityManagerInterface $em,
        private readonly LoanEventPublisher $publisher,
    ) {}

    /** Incoming collection requests for the current user's collections (owner side). */
    #[Route('/incoming', methods: ['GET'])]
    public function incoming(Request $request, CollectionRequestRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $keyword = $request->query->get('status', 'open');
        $statuses = $this->statusFilter($keyword);
        if ($statuses === null) {
            return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
        }

        if ($keyword === 'all') {
            $pagination = Pagination::fromRequest($request, self::HISTORY_PER_PAGE);
            $result = $repo->findIncomingPaginated($user, $statuses, $pagination);

            return $this->json($this->mapper->paginated(
                $result->items,
                $result->total,
                $pagination,
                fn (CollectionRequest $r) => $this->mapper->collectionRequest($r),
            ));
        }

        return $this->json($this->mapper->collectionRequests($repo->findIncoming($user, $statuses)));
    }

    /** Outgoing collection requests made by the current user (borrower side). */
    #[Route('/outgoing', methods: ['GET'])]
    public function outgoing(Request $request, CollectionRequestRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $keyword = $request->query->get('status', 'active');
        $statuses = $this->statusFilter($keyword);
        if ($statuses === null) {
            return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
        }

        if ($keyword === 'all') {
            $pagination = Pagination::fromRequest($request, self::HISTORY_PER_PAGE);
            $result = $repo->findOutgoingPaginated($user, $statuses, $pagination);

            return $this->json($this->mapper->paginated(
                $result->items,
                $result->total,
                $pagination,
                fn (CollectionRequest $r) => $this->mapper->collectionRequest($r),
            ));
        }

        return $this->json($this->mapper->collectionRequests($repo->findOutgoing($user, $statuses)));
    }

    /**
     * Maps a status keyword to the matching RequestStatus set (shared with the
     * per-book requests controller).
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

    /** Borrow a selection (>=2 available) of a collection's books as one grouped request. */
    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] CollectionBorrowInput $input, CollectionRepository $collections): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $collection = $collections->find($input->collectionId);
        if (!$collection instanceof BookCollection) {
            return $this->json(['error' => 'Collection not found.'], Response::HTTP_NOT_FOUND);
        }
        if ($collection->getOwner()->isPrivate() && $collection->getOwner() !== $user) {
            return $this->json(['error' => 'This library is private.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $collectionRequest = $this->service->createBorrow($user, $collection, $input->bookIds);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        // After commit: one signal to the collection owner (never one per book).
        $this->publisher->publishCollectionSignal($collectionRequest, LoanEventPublisher::COLLECTION_REQUEST_RECEIVED);

        return $this->json($this->mapper->collectionRequest($collectionRequest), Response::HTTP_CREATED);
    }

    /** Owner approves the whole collection borrow, optionally setting one due date. */
    #[Route('/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, CollectionRequest $collectionRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $dueDate = null;
        $raw = $request->toArray()['dueDate'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw)) ?: null;
            if ($dueDate === null) {
                return $this->json(['error' => 'Invalid due date.'], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->runAction(fn () => $this->service->approve($collectionRequest, $user, $dueDate), $collectionRequest, LoanEventPublisher::COLLECTION_REQUEST_APPROVED);
    }

    /** Owner declines the whole collection borrow, optionally with a note. */
    #[Route('/{id}/decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decline(Request $request, CollectionRequest $collectionRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

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

        return $this->runAction(fn () => $this->service->decline($collectionRequest, $user, $message), $collectionRequest, LoanEventPublisher::COLLECTION_REQUEST_DECLINED);
    }

    /** Borrower withdraws their own pending collection request, deleting it. */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(CollectionRequest $collectionRequest): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Capture before deletion: after flush the row (and its id) is gone.
        $ownerId = $collectionRequest->getCollection()->getOwner()->getId();
        $requestId = $collectionRequest->getId();

        try {
            $this->service->cancel($collectionRequest, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        if ($ownerId !== null) {
            $this->publisher->publishCollectionToUser($ownerId, LoanEventPublisher::COLLECTION_REQUEST_CANCELLED, $requestId);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Borrower marks the whole collection as returned, awaiting the owner's confirmation. */
    #[Route('/{id}/return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function return(CollectionRequest $collectionRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->runAction(fn () => $this->service->requestReturn($collectionRequest, $user), $collectionRequest, LoanEventPublisher::COLLECTION_RETURN_REQUESTED);
    }

    /** Owner confirms the whole collection was received back, closing every child loan. */
    #[Route('/{id}/confirm-return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmReturn(CollectionRequest $collectionRequest): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->runAction(fn () => $this->service->confirmReturn($collectionRequest, $user), $collectionRequest, LoanEventPublisher::COLLECTION_RETURN_CONFIRMED);
    }

    /**
     * Runs a collection transition, mapping business-rule violations to 409 and
     * flushing once on success, then publishing exactly one collection signal.
     * Ownership violations (AccessDeniedException) bubble up as 403.
     */
    private function runAction(callable $action, CollectionRequest $collectionRequest, string $signalReason): JsonResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        $this->publisher->publishCollectionSignal($collectionRequest, $signalReason);

        return $this->json($this->mapper->collectionRequest($collectionRequest));
    }
}
