<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\BookInput;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
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
    public function list(Request $request, BookRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $status = null;
        if ($raw = $request->query->get('status')) {
            $status = BookStatus::tryFrom($raw);
            if ($status === null) {
                return $this->json(['error' => 'Invalid status filter.'], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->json($this->mapper->books($repo->findByOwner($user, $status)));
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
