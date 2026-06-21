<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Entity\User;
use App\Service\UserStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserController extends AbstractController
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

        return $this->json($this->mapper->profile(
            $user,
            $this->stats->forUser($user),
            $viewer->getId() === $user->getId(),
        ));
    }
}
