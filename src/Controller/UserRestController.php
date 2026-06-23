<?php

namespace App\Controller;

use App\Api\ResponseMapper;
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
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserStatsProvider $stats,
        private readonly SubscriptionRepository $subscriptions,
    ) {}

    /**
     * Discover "Accounts" search: public members matching a name query. Empty
     * query returns [] (the SPA prompts to search rather than listing everyone).
     * Declared before show() so the literal path wins over the /{id} pattern.
     */
    #[Route('/discover', methods: ['GET'])]
    public function discover(Request $request, UserRepository $users): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->json([]);
        }

        $matches = $users->findPublicForDiscover($viewer, $q);

        // Resolve the viewer's follow state for the whole result set in one query
        // instead of an isSubscribed() probe per row.
        $followedIds = [];
        foreach ($this->subscriptions->findFollowing($viewer) as $subscription) {
            /** @var Subscription $subscription */
            $followedIds[$subscription->getSubscribedTo()->getId()] = true;
        }

        $payload = array_map(
            fn (User $u) => $this->mapper->userCard(
                $u,
                $this->stats->forUser($u),
                isset($followedIds[$u->getId()]),
            ),
            $matches,
        );

        return $this->json($payload);
    }

    /** Public profile of any user: identity and stats. Books are fetched separately via /api/books?owner={id}. */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(User $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $isSelf = $viewer->getId() === $user->getId();

        // A private profile is hidden from everyone but its owner — same rule the
        // book listing applies to a private library.
        if (!$isSelf && $user->isPrivate()) {
            return $this->json(['error' => 'This profile is private.'], Response::HTTP_FORBIDDEN);
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
