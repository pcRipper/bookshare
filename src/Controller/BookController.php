<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\BookInput;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\UserRepository;
use App\Security\Voter\BookVoter;
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
            // A private profile's collection is visible only to its owner.
            if ($owner !== $viewer && $owner->isPrivate()) {
                return $this->json(['error' => 'This library is private.'], Response::HTTP_FORBIDDEN);
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

    /**
     * Discover: shareable books from other public members. Supports a free-text
     * query (?q= over title/author) and a category filter (?category={id}).
     * Each book carries its owner and a viewer-relative `requested` flag.
     */
    #[Route('/discover', methods: ['GET'])]
    public function discover(
        Request $request,
        BookRepository $repo,
        CategoryRepository $categories,
        LibraryRequestRepository $requests,
    ): JsonResponse {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $q = trim((string) $request->query->get('q', ''));

        $category = null;
        if (($raw = $request->query->get('category')) !== null && $raw !== '') {
            if (!ctype_digit((string) $raw)) {
                return $this->json(['error' => 'Invalid category filter.'], Response::HTTP_BAD_REQUEST);
            }
            $category = $categories->find((int) $raw);
            if ($category === null) {
                return $this->json(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        $books = $repo->findForDiscover($viewer, $q !== '' ? $q : null, $category);

        $pending = array_flip($requests->findPendingBookIdsForRequester($viewer));
        $payload = array_map(
            fn (Book $b) => $this->mapper->discoverBook($b) + ['requested' => isset($pending[$b->getId()])],
            $books,
        );

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
        $this->denyAccessUnlessGranted(BookVoter::EDIT, $book, self::lockedMessage($book));

        $this->books->update($book, $input);
        $this->em->flush();

        return $this->json($this->mapper->book($book));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Book $book): JsonResponse
    {
        $this->denyAccessUnlessGranted(BookVoter::DELETE, $book, self::lockedMessage($book));

        $this->books->delete($book);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Picks the access-denied reason: a book that's owned but out on loan is
     * locked until returned; anything else is a plain ownership violation.
     */
    private function lockedMessage(Book $book): string
    {
        return $book->getOwner() === $this->getUser() && !$book->isHome()
            ? 'This book is out on loan and can\'t be edited until it\'s returned.'
            : 'You do not own this book.';
    }
}
