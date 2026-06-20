<?php

namespace App\Repository;

use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * Books owned by a user, optionally filtered by status, newest first.
     *
     * @return Book[]
     */
    public function findByOwner(User $owner, ?BookStatus $status = null): array
    {
        $criteria = ['owner' => $owner];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
    }

    public function countByOwnerAndStatus(User $owner, BookStatus $status): int
    {
        return $this->count(['owner' => $owner, 'status' => $status]);
    }

    /** Books owned by the user that are shareable (status != unavailable). */
    public function countShareableByOwner(User $owner): int
    {
        return $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.owner = :owner')
            ->andWhere('b.status != :unavailable')
            ->setParameter('owner', $owner)
            ->setParameter('unavailable', BookStatus::Unavailable)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
