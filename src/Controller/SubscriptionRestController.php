<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subscriptions')]
class SubscriptionRestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly SubscriptionService $service,
        private readonly SubscriptionRepository $subscriptions,
        private readonly BookRepository $books,
        private readonly LibraryRequestRepository $requests,
        private readonly EntityManagerInterface $em,
    ) {}

    /** The people the current user follows — powers the Library "Following" tab. */
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(array_map(
            fn ($s) => $this->mapper->subscription($s),
            $this->subscriptions->findFollowing($user),
        ));
    }

    /**
     * The subscription feed: each followed (public) reader and their recent books,
     * groups ordered by their newest book. Readers with no books are omitted.
     */
    #[Route('/feed', methods: ['GET'])]
    public function feed(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Books the viewer already has a pending request for, so the feed's CTA
        // reflects reality ("Requested") instead of offering to borrow again.
        $pending = array_flip($this->requests->findPendingBookIdsForRequester($user));

        $groups = [];
        foreach ($this->subscriptions->findFollowedUsers($user) as $followed) {
            $books = $this->books->findRecentByOwner($followed, 15);
            if ($books === []) {
                continue;
            }
            $groups[] = [
                'newestAt' => $books[0]->getCreatedAt(),
                'payload'  => $this->mapper->subscriptionFeed($followed, $books, $pending),
            ];
        }

        // Most recently active reader first.
        usort($groups, fn ($a, $b) => $b['newestAt'] <=> $a['newestAt']);

        return $this->json(array_map(static fn ($g) => $g['payload'], $groups));
    }

    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function subscribe(User $target): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $subscription = $this->service->subscribe($user, $target);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        return $this->json($this->mapper->subscription($subscription), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unsubscribe(User $target): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->service->unsubscribe($user, $target);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
