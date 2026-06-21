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
}
