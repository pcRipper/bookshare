<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Repository\ActivityItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/activity')]
class ActivityRestController extends AbstractController
{
    public function __construct(private readonly ResponseMapper $mapper) {}

    #[Route('', methods: ['GET'])]
    public function list(ActivityItemRepository $activity): JsonResponse
    {
        return $this->json(array_map(
            fn ($i) => $this->mapper->activity($i),
            $activity->findRecent(),
        ));
    }
}
