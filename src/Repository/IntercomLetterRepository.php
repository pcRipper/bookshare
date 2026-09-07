<?php

namespace App\Repository;

use App\Entity\IntercomLetter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntercomLetter>
 */
class IntercomLetterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntercomLetter::class);
    }

    /**
     * The most recent letters, newest first.
     *
     * Capped rather than paginated: this list answers "did we already send
     * that?", which is a question about the last handful of sends. A panel that
     * paginated its own history would be more machinery than the question needs.
     *
     * @return IntercomLetter[]
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.sentBy', 'u')->addSelect('u')
            ->orderBy('l.sentAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
