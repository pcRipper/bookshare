<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\CollectionInput;
use App\Dto\Pagination;
use App\Entity\BookCollection;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\UserRepository;
use App\Security\Voter\CollectionVoter;
use App\Service\CollectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/collections')]
class CollectionRestController extends AbstractController
{
    /** Collections per page in a library/profile grid. */
    private const PER_PAGE = 24;

    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly CollectionService $service,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * A user's collections. ?owner={id} browses another member's (public) shelf;
     * absent ⇒ the current user's own. Books in each collection carry a
     * viewer-relative `requested` flag when browsing.
     */
    #[Route('', methods: ['GET'])]
    public function list(
        Request $request,
        CollectionRepository $repo,
        UserRepository $users,
        LibraryRequestRepository $requests,
    ): JsonResponse {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $owner = $viewer;
        if ($raw = $request->query->get('owner')) {
            $owner = $users->find($raw);
            if (!$owner instanceof User) {
                return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($owner !== $viewer && $owner->isPrivate()) {
                return $this->json(['error' => 'This library is private.'], Response::HTTP_FORBIDDEN);
            }
        }

        $pagination = Pagination::fromRequest($request, self::PER_PAGE);
        $result = $repo->findByOwnerPaginated($owner, $pagination);

        $browsing = $owner !== $viewer;
        $pending = $browsing ? array_flip($requests->findPendingBookIdsForRequester($viewer)) : [];

        return $this->json($this->mapper->paginated(
            $result->items,
            $result->total,
            $pagination,
            fn (BookCollection $c) => $this->mapper->collection($c, $pending),
        ));
    }

    /** A single collection with its books (used by the borrow modal). */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(BookCollection $collection, LibraryRequestRepository $requests): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $owner = $collection->getOwner();
        if ($owner !== $viewer && $owner->isPrivate()) {
            return $this->json(['error' => 'This library is private.'], Response::HTTP_FORBIDDEN);
        }

        $pending = $owner !== $viewer ? array_flip($requests->findPendingBookIdsForRequester($viewer)) : [];

        return $this->json($this->mapper->collection($collection, $pending));
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] CollectionInput $input): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $collection = $this->service->create($user, $input);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        return $this->json($this->mapper->collection($collection), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(BookCollection $collection, #[MapRequestPayload] CollectionInput $input): JsonResponse
    {
        $this->denyAccessUnlessGranted(CollectionVoter::EDIT, $collection, 'This collection is out on loan and can\'t be edited.');

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->service->update($collection, $input, $user);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        return $this->json($this->mapper->collection($collection));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(BookCollection $collection): Response
    {
        $this->denyAccessUnlessGranted(CollectionVoter::DELETE, $collection, 'This collection is out on loan and can\'t be deleted.');

        try {
            $this->service->delete($collection);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
