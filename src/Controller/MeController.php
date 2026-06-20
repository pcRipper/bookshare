<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\ProfileInput;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly BookRepository $books,
    ) {}

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->mapper->me($user, $this->stats($user)));
    }

    #[Route('', methods: ['PATCH'])]
    public function update(
        #[MapRequestPayload] ProfileInput $input,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $user->setBio($input->bio)->setLocation($input->location);

        $em->flush();

        return $this->json($this->mapper->me($user, $this->stats($user)));
    }

    private function stats(User $user): array
    {
        return [
            'totalBooks' => $this->books->countByOwner($user),
            'shared'     => $this->books->countShareableByOwner($user),
            'loaned'     => $this->books->countByOwnerAndStatus($user, BookStatus::Lent),
        ];
    }
}
