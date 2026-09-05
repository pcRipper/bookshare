<?php

namespace App\Controller;

use App\Api\ApiError;
use App\Api\MemberVisibility;
use App\Api\ResponseMapper;
use App\Dto\Pagination;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\UserStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users')]
class UserRestController extends AbstractController
{
    /** Reader cards per page in the Discover "Accounts" results. */
    private const DISCOVER_PER_PAGE = 18;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserStatsProvider $stats,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ApiError $errors,
        private readonly MemberVisibility $visibility,
    ) {}

    /**
     * Discover "Accounts": public members, optionally filtered by a name query.
     * A blank query browses the membership newest-first instead of returning
     * nothing, mirroring the books feed.
     * Declared before show() so the literal path wins over the /{id} pattern.
     */
    #[Route('/discover', methods: ['GET'])]
    public function discover(Request $request, UserRepository $users): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $pagination = Pagination::fromRequest($request, self::DISCOVER_PER_PAGE);

        $q = trim((string) $request->query->get('q', ''));

        $result = $users->findPublicForDiscoverPaginated($viewer, $q !== '' ? $q : null, $pagination);

        // Resolve the viewer's follow state for the whole result set in one query
        // instead of an isSubscribed() probe per row.
        $followedIds = [];
        foreach ($this->subscriptions->findFollowing($viewer) as $subscription) {
            /** @var Subscription $subscription */
            $followedIds[$subscription->getSubscribedTo()->getId()] = true;
        }

        // Likewise the stat counters: four grouped queries for the page, not four per card.
        $stats = $this->stats->forUsers($result->items);

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (User $u) => $this->mapper->userCard(
                $u,
                $stats[$u->getId()],
                isset($followedIds[$u->getId()]),
            ),
        ));
    }

    /** Public profile of any user: identity and stats. Books are fetched separately via /api/books?owner={id}. */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(User $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $isSelf = $viewer->getId() === $user->getId();

        // A private profile is hidden from everyone but its owner — same rule the
        // book listing applies to a private library — and a suspended or deleted
        // member 404s as though they had never existed.
        if ($denied = $this->visibility->deny($user, $viewer, 'This profile is private.')) {
            return $denied;
        }

        // The owner always sees their own location; others only if the user
        // hasn't hidden it via their settings.
        $settings = $user->getSettings();
        $showLocation = $isSelf || $settings === null || $settings->showsLocation();

        $isSubscribed = !$isSelf && $this->subscriptions->isSubscribed($viewer, $user);

        return $this->json($this->mapper->profile(
            $user,
            $this->stats->forUser($user),
            $isSelf,
            $showLocation,
            $isSubscribed,
        ));
    }
}
