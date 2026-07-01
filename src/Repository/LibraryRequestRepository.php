<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class LibraryRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryRequest::class);
    }

    /**
     * Incoming requests for books owned by $owner, filtered by status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return LibraryRequest[]
     */
    public function findIncoming(User $owner, array $statuses): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.book', 'b')
            // Eager-load the lifecycle events (and their actors) so the timeline
            // renders without an N+1 per request.
            ->leftJoin('r.events', 'e')->addSelect('e')
            ->leftJoin('e.actor', 'ea')->addSelect('ea')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Outgoing requests made by $requester (the borrower's side), filtered by
     * status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return LibraryRequest[]
     */
    public function findOutgoing(User $requester, array $statuses): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.events', 'e')->addSelect('e')
            ->leftJoin('e.actor', 'ea')->addSelect('ea')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('requester', $requester)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of incoming requests (owner side) for the History view, newest
     * first, with the total matching count.
     *
     * Events are a to-many collection, which the Paginator can't page over while
     * fetch-joined. So the page query selects only to-one associations (fetch
     * joins that never multiply rows), then hydrateEvents() loads each request's
     * events in a single follow-up query — no N+1, correct paging.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<LibraryRequest>
     */
    public function findIncomingPaginated(User $owner, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('r')
            ->join('r.book', 'b')->addSelect('b')
            ->join('b.owner', 'bo')->addSelect('bo')
            ->join('r.requester', 'rq')->addSelect('rq')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithEvents($query, $pagination);
    }

    /**
     * One page of outgoing requests (borrower side) for the History view.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<LibraryRequest>
     */
    public function findOutgoingPaginated(User $requester, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('r')
            ->join('r.book', 'b')->addSelect('b')
            ->join('b.owner', 'bo')->addSelect('bo')
            ->join('r.requester', 'rq')->addSelect('rq')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('requester', $requester)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithEvents($query, $pagination);
    }

    /**
     * Runs a to-one-only page query, then eagerly loads the events (and their
     * actors) for exactly that page so the timeline renders without an N+1.
     *
     * @return PaginatedResult<LibraryRequest>
     */
    private function paginateWithEvents(\Doctrine\ORM\Query $query, Pagination $pagination): PaginatedResult
    {
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $requests = iterator_to_array($paginator);

        if ($requests !== []) {
            // Populates the events collection (ordered by its #[ORM\OrderBy]) on
            // the already-managed request entities via the Unit of Work.
            $this->createQueryBuilder('r')
                ->leftJoin('r.events', 'e')->addSelect('e')
                ->leftJoin('e.actor', 'ea')->addSelect('ea')
                ->andWhere('r IN (:requests)')
                ->setParameter('requests', $requests)
                ->getQuery()
                ->getResult();
        }

        return new PaginatedResult($requests, \count($paginator));
    }

    public function countPendingForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.book', 'b')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status = :pending')
            ->setParameter('owner', $owner)
            ->setParameter('pending', RequestStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ids of books the requester currently has a pending request for. Lets the
     * public profile mark already-requested books so the borrow button reflects
     * reality across reloads.
     *
     * @return int[]
     */
    public function findPendingBookIdsForRequester(User $requester): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.book) AS bookId')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status = :pending')
            ->setParameter('requester', $requester)
            ->setParameter('pending', RequestStatus::Pending)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['bookId'], $rows);
    }

    public function findPendingForBookAndRequester(int $bookId, User $requester): ?LibraryRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.book = :book')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status = :pending')
            ->setParameter('book', $bookId)
            ->setParameter('requester', $requester)
            ->setParameter('pending', RequestStatus::Pending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
