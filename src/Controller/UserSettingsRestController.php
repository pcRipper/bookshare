<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\UserSettingsInput;
use App\Entity\User;
use App\Repository\UserSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The current user's preferences (privacy + notification toggles). Kept on its
 * own resource — separate from /me — so the user payload stays about identity.
 */
#[Route('/me/settings')]
class UserSettingsRestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly UserSettingsRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $settings = $this->settings->findOrCreateFor($user);
        $this->em->flush();

        return $this->json($this->mapper->settings($settings));
    }

    #[Route('', methods: ['PATCH'])]
    public function update(Request $request, #[MapRequestPayload] UserSettingsInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $settings = $this->settings->findOrCreateFor($user);

        // Partial update: only apply the keys the client actually sent, so the
        // UI can flip one toggle at a time.
        $present = array_keys($request->toArray());
        $apply = [
            'allowRequests'        => $settings->setAllowRequests(...),
            'showLocation'         => $settings->setShowLocation(...),
            'notifyBorrowRequests' => $settings->setNotifyBorrowRequests(...),
            'notifyRequestUpdates' => $settings->setNotifyRequestUpdates(...),
            'notifyActivity'       => $settings->setNotifyActivity(...),
            'notifyNewsletter'     => $settings->setNotifyNewsletter(...),
        ];

        foreach ($apply as $key => $setter) {
            if (in_array($key, $present, true)) {
                $setter((bool) $input->$key);
            }
        }

        $this->em->flush();

        return $this->json($this->mapper->settings($settings));
    }
}
