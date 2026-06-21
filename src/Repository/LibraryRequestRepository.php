<?php

namespace App\Repository;

use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
