<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\BookInput;
use App\Dto\BookTemplate;
use App\Dto\Pagination;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Language\LanguageCatalog;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\UserRepository;
use App\Security\Voter\BookVoter;
use App\Service\BookCsvService;
use App\Service\BookService;
use App\Service\BookTemplate\BookTemplateSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/books')]
class BookRestController extends AbstractController
{
    /** Books per page in a library collection grid. */
    private const COLLECTION_PER_PAGE = 24;
    /** Books per page in the Discover grid. */
    private const DISCOVER_PER_PAGE = 24;
    /** Max templates returned by the "Add New Book" search (bounded, bare array). */
    private const TEMPLATE_RESULTS = 12;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly BookService $books,
        private readonly BookCsvService $csv,
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

        $pagination = Pagination::fromRequest($request, self::COLLECTION_PER_PAGE);
        $result = $repo->findByOwnerPaginated($owner, $status, $pagination);

        // Annotate viewer-relative borrow state when browsing someone else's shelf.
        $browsing = $owner !== $viewer;
        $pending = $browsing ? array_flip($requests->findPendingBookIdsForRequester($viewer)) : [];

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (Book $b) => $browsing
                ? $this->mapper->book($b) + ['requested' => isset($pending[$b->getId()])]
                : $this->mapper->book($b),
        ));
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

        $language = null;
        if (($raw = $request->query->get('language')) !== null && $raw !== '') {
            if (!LanguageCatalog::isValid((string) $raw)) {
                return $this->json(['error' => 'Invalid language filter.'], Response::HTTP_BAD_REQUEST);
            }
            $language = (string) $raw;
        }

        $pagination = Pagination::fromRequest($request, self::DISCOVER_PER_PAGE);
        $result = $repo->findForDiscoverPaginated($viewer, $q !== '' ? $q : null, $category, $language, $pagination);

        $pending = array_flip($requests->findPendingBookIdsForRequester($viewer));

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (Book $b) => $this->mapper->discoverBook($b) + ['requested' => isset($pending[$b->getId()])],
        ));
    }

    /**
     * Search for book templates to pre-fill the "Add New Book" form. `?source=`
     * picks the strategy (`site` searches the catalogue, `external` Open Library,
     * `bookfinder` the bookfinder.com.ua Ukrainian marketplace); `?q=` matches
     * title or ISBN. A blank query yields an empty list. Bounded and consumed
     * whole → a bare array, no envelope.
     */
    #[Route('/templates', methods: ['GET'])]
    public function templates(Request $request, BookTemplateSearch $search): JsonResponse
    {
        $source = trim((string) $request->query->get('source', 'site')) ?: 'site';
        if (!$search->supports($source)) {
            return $this->json(['error' => 'Unknown template source.'], Response::HTTP_BAD_REQUEST);
        }

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->json([]);
        }

        $templates = $search->search($source, $q, self::TEMPLATE_RESULTS);

        return $this->json(array_map(
            fn (BookTemplate $t) => $this->mapper->bookTemplate($t),
            $templates,
        ));
    }

    /** Download the signed-in user's collection as a CSV file. */
    #[Route('/export', methods: ['GET'])]
    public function export(BookRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $csv = $this->csv->export($repo->findByOwner($user));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="folioshare-books.csv"',
        ]);
    }

    /**
     * Bulk-import books from an uploaded CSV. The `mode` (append|replace) and
     * `onError` (skip|abort) form fields pick one of the four import strategies.
     * Returns a summary; an aborted run that imported nothing is a 422.
     */
    #[Route('/import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'No file was uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        $replace = $request->request->get('mode') === 'replace';
        $abort   = $request->request->get('onError') === 'abort';

        $summary = $this->csv->import($user, (string) file_get_contents($file->getPathname()), $replace, $abort);

        if ($summary['aborted']) {
            // Nothing was staged — surface the validation errors without committing.
            return $this->json($summary, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($summary);
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
