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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/requests')]
class LibraryRequestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly LibraryRequestService $service,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Incoming requests for the current user's books. ?status=pending|resolved */
    #[Route('/incoming', methods: ['GET'])]
    public function incoming(Request $request, LibraryRequestRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $statuses = match ($request->query->get('status', 'pending')) {
            'pending'  => [RequestStatus::Pending],
            'resolved' => [RequestStatus::Approved, RequestStatus::Declined],
            default    => null,
        };
        if ($statuses === null) {
            return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->mapper->requests($repo->findIncoming($user, $statuses)));
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

        return $this->json($this->mapper->request($libraryRequest), Response::HTTP_CREATED);
    }

    #[Route('/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(LibraryRequest $libraryRequest): JsonResponse
    {
        return $this->resolve($libraryRequest, true);
    }

    #[Route('/{id}/decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decline(LibraryRequest $libraryRequest): JsonResponse
    {
        return $this->resolve($libraryRequest, false);
    }

    private function resolve(LibraryRequest $libraryRequest, bool $approve): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $approve
                ? $this->service->approve($libraryRequest, $user)
                : $this->service->decline($libraryRequest, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        return $this->json($this->mapper->request($libraryRequest));
    }
}
