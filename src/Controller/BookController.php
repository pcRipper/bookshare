<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\BookInput;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\UserRepository;
use App\Service\BookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/books')]
class BookController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly BookService $books,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(
        Request $request,
        BookRepository $repo,
        UserRepository $users,
        LibraryRequestRepository $requests,
    ): JsonResponse {
        /** @var User $viewer */
        $viewer = $this->getUser();

        // ?owner={id} browses another member's shelf; absent ⇒ the current user's own.
        $owner = $viewer;
        if ($raw = $request->query->get('owner')) {
            $owner = $users->find($raw);
            if (!$owner instanceof User) {
                return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        $status = null;
        if ($raw = $request->query->get('status')) {
            $status = BookStatus::tryFrom($raw);
            if ($status === null) {
                return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $payload = $this->mapper->books($repo->findByOwner($owner, $status));

        // Annotate viewer-relative borrow state when browsing someone else's shelf.
        if ($owner !== $viewer) {
            $pending = array_flip($requests->findPendingBookIdsForRequester($viewer));
            $payload = array_map(
                static fn (array $b) => $b + ['requested' => isset($pending[$b['id']])],
                $payload,
            );
        }

        return $this->json($payload);
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] BookInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $book = $this->books->create($user, $input);
        $this->em->flush();

        return $this->json($this->mapper->book($book), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(Book $book, #[MapRequestPayload] BookInput $input): JsonResponse
    {
        $this->denyUnlessOwner($book);

        $this->books->update($book, $input);
        $this->em->flush();

        return $this->json($this->mapper->book($book));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Book $book): JsonResponse
    {
        $this->denyUnlessOwner($book);

        $this->books->delete($book);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function denyUnlessOwner(Book $book): void
    {
        if ($book->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not own this book.');
        }
    }
}
