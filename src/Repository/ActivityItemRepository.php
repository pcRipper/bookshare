<?php

namespace App\Repository;

use App\Entity\ActivityItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityItem::class);
    }

    /** @return ActivityItem[] */
    public function findRecent(int $limit = 30): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * The recent feed with its three to-one relations already loaded.
     *
     * findRecent() leaves actor, targetBook and targetUser lazy, so mapping a
     * page of items fires up to three extra queries each — 90 for a 30-item feed.
     * All three are to-one, so fetch-joining them is safe with setMaxResults
     * (no collection is being paged).
     *
     * The joins are LEFT because the two targets are nullable by design: the log
     * is append-only and detaches a reference when the book or user it pointed at
     * is deleted. An inner join would silently drop exactly that history.
     *
     * @return ActivityItem[]
     */
    public function findRecentWithRelations(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('actor', 'targetBook', 'targetUser')
            ->join('a.actor', 'actor')
            ->leftJoin('a.targetBook', 'targetBook')
            ->leftJoin('a.targetUser', 'targetUser')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
