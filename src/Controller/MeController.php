<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\ProfileInput;
use App\Entity\User;
use App\Service\UserStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserStatsProvider $stats,
    ) {}

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->mapper->me($user, $this->stats->forUser($user)));
    }

    #[Route('', methods: ['PATCH'])]
    public function update(
        Request $request,
        #[MapRequestPayload] ProfileInput $input,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        // Partial update: only touch fields the client actually sent, so a
        // lightweight editor (bio + location) and the full settings form
        // (name + avatar + bio + location) can share this endpoint.
        $present = array_keys($request->toArray());

        if (in_array('fullName', $present, true)) {
            $name = trim((string) $input->fullName);
            if ($name === '') {
                return $this->json(['error' => 'Name cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setFullName($name);
        }
        if (in_array('bio', $present, true)) {
            $user->setBio($input->bio !== null ? trim($input->bio) : null);
        }
        if (in_array('location', $present, true)) {
            $user->setLocation($input->location !== null ? trim($input->location) : null);
        }
        if (in_array('avatarUrl', $present, true)) {
            $avatar = $input->avatarUrl !== null ? trim($input->avatarUrl) : null;
            $user->setAvatarUrl($avatar === '' ? null : $avatar);
        }
        if (in_array('isPrivate', $present, true)) {
            $user->setIsPrivate((bool) $input->isPrivate);
        }

        $em->flush();

        return $this->json($this->mapper->me($user, $this->stats->forUser($user)));
    }
}
