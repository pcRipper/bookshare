<?php

namespace App\Repository;

use App\Dto\Pagination;
use App\Dto\PaginatedResult;
use App\Entity\BookCollection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookCollection>
 */
class CollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookCollection::class);
    }

    /**
     * A user's collections, newest first, paginated. Books are fetched lazily
     * per collection by the mapper — the count stays correct because we page on
     * the root entity only.
     *
     * @return PaginatedResult<BookCollection>
     */
    public function findByOwnerPaginated(User $owner, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), count($paginator));
    }

    /** How many collections a user owns — powers the profile tab counter. */
    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
