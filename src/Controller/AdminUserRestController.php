<?php

namespace App\Controller;

use App\Api\ApiError;
use App\Api\ResponseMapper;
use App\Dto\Pagination;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AdminAccess;
use App\Service\Admin\AdminUserService;
use App\Service\UserStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Member administration: who is here, and the two things an operator can do
 * about one of them.
 *
 * Gated twice, exactly as AdminStatsRestController documents — the
 * access_control rule in security.yaml is the coarse net that covers any
 * /api/admin controller, and the attribute is what supplies a denial message we
 * control and can translate.
 */
#[Route('/admin/users')]
#[IsGranted(AdminAccess::ROLE, message: AdminAccess::DENIED_MESSAGE)]
class AdminUserRestController extends AbstractController
{
    /** Members per page. Denser than a reader-card grid: this is a table. */
    private const PER_PAGE = 25;

    /**
     * The status filter's vocabulary. Bounded like every other keyword filter in
     * the API; an unrecognised value falls back to 'all' rather than 422-ing,
     * matching Pagination's clamp-don't-reject rule.
     */
    private const STATUSES = ['all', 'active', 'banned', 'deleted'];

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserStatsProvider $stats,
        private readonly AdminUserService $service,
        private readonly EntityManagerInterface $em,
        private readonly ApiError $errors,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request, UserRepository $users): JsonResponse
    {
        $pagination = Pagination::fromRequest($request, self::PER_PAGE);

        $status = (string) $request->query->get('status', 'all');
        if (!\in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        $result = $users->findForAdminPaginated(
            $request->query->get('q'),
            $status,
            $pagination,
        );

        // Grouped counts for the whole page — four queries, not four per row.
        $stats = $this->stats->forUsers($result->items);

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (User $user) => $this->mapper->adminUser($user, $stats[$user->getId()]),
        ));
    }

    #[Route('/{id}/ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ban(User $user, Request $request): JsonResponse
    {
        // Read by hand rather than through a DTO: one optional free-text field,
        // and an operator's note has no validation rules beyond the column width
        // the entity already enforces.
        $reason = $request->toArray()['reason'] ?? null;
        if ($reason !== null && !\is_string($reason)) {
            return $this->errors->response('Invalid ban reason.', Response::HTTP_BAD_REQUEST);
        }

        return $this->apply(fn (User $actor) => $this->service->ban($actor, $user, $reason), $user);
    }

    #[Route('/{id}/unban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unban(User $user): JsonResponse
    {
        return $this->apply(fn (User $actor) => $this->service->unban($actor, $user), $user);
    }

    /**
     * Soft-deletes the account: the row is anonymized and the member's library,
     * loans and follows are destroyed. See App\Service\Admin\UserPurger for why
     * the row survives at all.
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(User $user): JsonResponse
    {
        return $this->apply(fn (User $actor) => $this->service->delete($actor, $user), $user);
    }

    /**
     * The shared body of the three write actions: run the rule, flush once, and
     * hand back the member in their new state so the table can replace the row
     * without a refetch.
     *
     * Stats are recomputed after the flush deliberately — a deletion has just
     * emptied the shelf this reads, and returning the pre-delete counts would
     * put a row on screen claiming a library that no longer exists.
     */
    private function apply(callable $action, User $target): JsonResponse
    {
        /** @var User $actor */
        $actor = $this->getUser();

        try {
            $action($actor);
        } catch (\DomainException $e) {
            return $this->errors->fromDomain($e, Response::HTTP_CONFLICT);
        }

        $this->em->flush();

        return $this->json($this->mapper->adminUser($target, $this->stats->forUser($target)));
    }
}
