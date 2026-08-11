<?php

namespace App\Repository;

use App\Entity\PageViewVisitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageViewVisitor>
 */
class PageViewVisitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageViewVisitor::class);
    }

    /**
     * Records that this visitor was seen today, once.
     *
     * Check-then-insert: the (day, hash) index lookup is essentially free, and it
     * keeps the common case — a visitor already counted today, which is every
     * page view after their first — down to a single SELECT with no write.
     *
     * The catch is mandatory rather than defensive: two concurrent first requests
     * from one visitor both pass the check, and an uncaught unique violation
     * closes the EntityManager for the rest of the request.
     */
    public function touch(string $visitorHash, \DateTimeImmutable $day, bool $authenticated): void
    {
        if ($this->existsFor($visitorHash, $day)) {
            return;
        }

        try {
            $row = (new PageViewVisitor())
                ->setVisitorHash($visitorHash)
                ->setDay($day)
                ->setAuthenticated($authenticated);
            $this->getEntityManager()->persist($row);
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException) {
            // Counted by a concurrent request from the same visitor. Nothing to do.
        }
    }

    public function existsFor(string $visitorHash, \DateTimeImmutable $day): bool
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitorHash = :hash')
            ->andWhere('v.day = :day')
            ->setParameter('hash', $visitorHash)
            ->setParameter('day', $day)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Distinct visitors per day over the window. Sparse — the caller gap-fills.
     *
     * @param  bool|null $authenticated null counts everyone; true is the daily
     *   active users metric — signed-in visitors that loaded a counted page.
     * @return array<string, int> keyed by Y-m-d
     */
    public function countsByDay(\DateTimeImmutable $since, ?bool $authenticated = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.day AS day, COUNT(v.id) AS total')
            ->where('v.day >= :since')
            ->setParameter('since', $since)
            ->groupBy('v.day');

        if ($authenticated !== null) {
            $qb->andWhere('v.authenticated = :auth')->setParameter('auth', $authenticated);
        }

        $byDay = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $byDay[PageViewDailyRepository::dayKey($row['day'])] = (int) $row['total'];
        }

        return $byDay;
    }

    /** Deletes rows older than the cutoff. Used by app:prune-analytics. */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('v')
            ->delete()
            ->where('v.day < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.day < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
