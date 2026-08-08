<?php

namespace App\Controller;

use App\Api\ApiError;
use App\Api\ResponseMapper;
use App\Dto\Pagination;
use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;
use App\Repository\UserRepository;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The signed-out share page: a read-only view of one member's library,
 * reachable by link or QR without an account.
 *
 * Deliberately a controller of its own rather than null-guards threaded through
 * BookRestController/CollectionRestController, which dereference the viewer
 * throughout to compute `requested`, `canEdit` and the "browsing someone else"
 * branch. Here there is no viewer at all: the route sits behind a
 * `security: false` firewall (config/packages/security.yaml), so
 * **nothing in this class may call getUser() or read the token storage** —
 * both would silently yield null and reintroduce viewer-relative output.
 * A test pins that.
 *
 * Everything is mapped through ResponseMapper's `public*` shapes, which drop
 * the borrower identity, ownership and viewer-relative flags.
 */
#[Route('/public')]
class PublicRestController extends AbstractController
{
    /** Matches the profile/library grids so the page paginates identically. */
    private const PER_PAGE = 24;

    /**
     * Deep offsets are pure cost with no legitimate use — a share link is
     * browsed, not crawled to page 40 000.
     */
    private const MAX_PAGE = 200;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly ApiError $errors,
    ) {}

    #[Route('/users/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function profile(string $id, UserRepository $users): JsonResponse
    {
        $owner = $this->findShared($id, $users);

        return $owner instanceof User
            ? $this->json($this->mapper->publicProfile($owner))
            : $owner;
    }

    #[Route('/users/{id}/books', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function books(string $id, Request $request, UserRepository $users, BookRepository $books): JsonResponse
    {
        $owner = $this->findShared($id, $users);
        if (!$owner instanceof User) {
            return $owner;
        }

        // Only the "available to borrow" shelf is selectable; any other value
        // falls back to the full shelf rather than 422-ing a browse UI — and
        // keeps ?status=lent from being a lending-inventory query.
        $status = $request->query->get('status') === BookStatus::Own->value ? BookStatus::Own : null;
        $query = $request->query->get('q');
        $pagination = $this->pagination($request);

        $result = $books->findByOwnerPaginated($owner, $status, $pagination, $query !== '' ? $query : null);

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (Book $b) => $this->mapper->publicBook($b),
        ));
    }

    #[Route('/users/{id}/collections', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function collections(
        string $id,
        Request $request,
        UserRepository $users,
        CollectionRepository $collections,
    ): JsonResponse {
        $owner = $this->findShared($id, $users);
        if (!$owner instanceof User) {
            return $owner;
        }

        $pagination = $this->pagination($request);
        $result = $collections->findByOwnerPaginated($owner, $pagination);

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (BookCollection $c) => $this->mapper->publicCollection($c),
        ));
    }

    /**
     * The share QR, as SVG.
     *
     * SVG rather than PNG on purpose: endroid's PngWriter needs ext-gd, which
     * isn't among the extensions this project documents as enabled, while the
     * SVG writer is pure PHP.
     *
     * Public so the Share modal can point an <img> straight at it — an <img>
     * can't carry a Bearer header, and the code encodes nothing but a URL that
     * is already public. The payload is a pure function of the id, so it is
     * cacheable; a private owner still 404s rather than emitting a code that
     * leads to a dead page.
     */
    #[Route('/users/{id}/qr.svg', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function qr(string $id, Request $request, UserRepository $users): Response
    {
        $owner = $this->findShared($id, $users);
        if (!$owner instanceof User) {
            return $owner;
        }

        $result = (new Builder(
            writer: new SvgWriter(),
            data: $this->shareUrl($request, $owner),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 8,
            // The brand green, so a printed code matches the rest of the app.
            foregroundColor: new Color(0x27, 0x47, 0x38),
        ))->build();

        $response = new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * The page the QR points at, absolute.
     *
     * Derived from the incoming request rather than DEFAULT_URI, which is
     * `http://localhost` in both .env and the hand-maintained .env.local.php —
     * a code built from it would be unscannable anywhere but the dev box.
     */
    private function shareUrl(Request $request, User $owner): string
    {
        return $request->getSchemeAndHttpHost() . '/public/library/' . $owner->getId();
    }

    /**
     * The owner whose library is shared, or the 404 to return instead.
     *
     * A private member and a member who doesn't exist give the **same** 404:
     * ids are sequential, so a distinguishable 403 would turn the id space into
     * a membership oracle. (This is why the response differs from the 403
     * 'This library is private.' the authenticated endpoints return — there the
     * caller is already a member and the distinction is useful.)
     *
     * @return User|JsonResponse
     */
    private function findShared(string $id, UserRepository $users): User|JsonResponse
    {
        $owner = $users->find($id);

        if (!$owner instanceof User || $owner->isPrivate()) {
            return $this->errors->response('This library is not shared.', Response::HTTP_NOT_FOUND);
        }

        return $owner;
    }

    /**
     * Page size is fixed rather than read from ?perPage: the shared ceiling of
     * 100 (Pagination::MAX_PER_PAGE) exists for signed-in browsing, and a public
     * endpoint shouldn't hand a scraper a 4x-bigger page than the UI asks for.
     */
    private function pagination(Request $request): Pagination
    {
        $requested = Pagination::fromRequest($request, self::PER_PAGE);

        return new Pagination(min($requested->page, self::MAX_PAGE), self::PER_PAGE);
    }
}
