<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Entity\User;
use App\Service\UserStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users')]
class UserRestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserStatsProvider $stats,
    ) {}

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

        return $this->json($this->mapper->profile(
            $user,
            $this->stats->forUser($user),
            $isSelf,
            $showLocation,
        ));
    }
}
