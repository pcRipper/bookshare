<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CollectionRequest>
 */
class CollectionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionRequest::class);
    }

    /**
     * Incoming collection requests (owner side), filtered by status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return CollectionRequest[]
     */
    public function findIncoming(User $owner, array $statuses): array
    {
        $requests = $this->createQueryBuilder('cr')
            ->join('cr.collection', 'c')
            ->andWhere('c.owner = :owner')
            ->andWhere('cr.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('cr.requestedAt', 'DESC')
            ->addOrderBy('cr.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->hydrateChildren($requests);
    }

    /**
     * Outgoing collection requests (borrower side), filtered by status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return CollectionRequest[]
     */
    public function findOutgoing(User $requester, array $statuses): array
    {
        $requests = $this->createQueryBuilder('cr')
            ->andWhere('cr.requester = :requester')
            ->andWhere('cr.status IN (:statuses)')
            ->setParameter('requester', $requester)
            ->orderBy('cr.requestedAt', 'DESC')
            ->addOrderBy('cr.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->hydrateChildren($requests);
    }

    /**
     * One page of incoming collection requests (owner side) for the History view.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<CollectionRequest>
     */
    public function findIncomingPaginated(User $owner, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('cr')
            ->join('cr.collection', 'c')->addSelect('c')
            ->join('c.owner', 'co')->addSelect('co')
            ->join('cr.requester', 'rq')->addSelect('rq')
            ->andWhere('c.owner = :owner')
            ->andWhere('cr.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('cr.requestedAt', 'DESC')
            ->addOrderBy('cr.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithChildren($query, $pagination);
    }

    /**
     * One page of outgoing collection requests (borrower side) for the History view.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<CollectionRequest>
     */
    public function findOutgoingPaginated(User $requester, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('cr')
            ->join('cr.collection', 'c')->addSelect('c')
            ->join('c.owner', 'co')->addSelect('co')
            ->join('cr.requester', 'rq')->addSelect('rq')
            ->andWhere('cr.requester = :requester')
            ->andWhere('cr.status IN (:statuses)')
            ->setParameter('requester', $requester)
            ->setParameter('statuses', $statuses)
            ->orderBy('cr.requestedAt', 'DESC')
            ->addOrderBy('cr.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithChildren($query, $pagination);
    }

    /**
     * True when the collection has a live borrow (approved or return-pending) —
     * used to block edits/deletes while it's out on loan.
     */
    public function hasActiveForCollection(BookCollection $collection): bool
    {
        $count = (int) $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->andWhere('cr.collection = :collection')
            ->andWhere('cr.status IN (:active)')
            ->setParameter('collection', $collection)
            ->setParameter('active', [RequestStatus::Approved, RequestStatus::ReturnPending])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Runs a to-one-only page query, then eagerly loads each request's children
     * (and the child books) for exactly that page — no N+1, correct paging.
     *
     * @return PaginatedResult<CollectionRequest>
     */
    private function paginateWithChildren(\Doctrine\ORM\Query $query, Pagination $pagination): PaginatedResult
    {
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $requests = iterator_to_array($paginator);

        $this->hydrateChildren($requests);

        return new PaginatedResult($requests, \count($paginator));
    }

    /**
     * Populates the children collection (+ each child's book) on already-managed
     * CollectionRequest entities via the Unit of Work, in a single query.
     *
     * @param CollectionRequest[] $requests
     * @return CollectionRequest[]
     */
    private function hydrateChildren(array $requests): array
    {
        if ($requests !== []) {
            $this->createQueryBuilder('cr')
                ->leftJoin('cr.children', 'ch')->addSelect('ch')
                ->leftJoin('ch.book', 'chb')->addSelect('chb')
                ->andWhere('cr IN (:requests)')
                ->setParameter('requests', $requests)
                ->getQuery()
                ->getResult();
        }

        return $requests;
    }
}
