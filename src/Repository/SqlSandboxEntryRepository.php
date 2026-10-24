<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\SqlSandboxEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SqlSandboxEntry>
 */
class SqlSandboxEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SqlSandboxEntry::class);
    }

    /**
     * The shared log, newest first. Paginated rather than capped (unlike the
     * intercom history): it grows with every query, and the question it answers
     * — "who changed this row, and when?" — reaches arbitrarily far back.
     */
    public function findPaginated(Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('e')
            ->leftJoin('e.operator', 'u')->addSelect('u')
            ->orderBy('e.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), count($paginator));
    }
}
