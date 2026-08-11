<?php

namespace App\Repository;

use App\Entity\LibraryRequestEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LibraryRequestEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryRequestEvent::class);
    }

    /**
     * Lifecycle events per (type, day) over the window — the dashboard's loan
     * activity series.
     *
     * Read from the event log rather than from LibraryRequest itself, because the
     * request row's resolvedAt is *overwritten* by each later transition: after a
     * borrow is returned there is no record left of when it was approved. The
     * event log is append-only and typed, which is what a time series needs.
     *
     * Counts collection children as well as individual requests — borrowing a
     * five-book collection really is five loans, and this measures lending
     * volume. Note that is the deliberate opposite of the incoming/outgoing list
     * queries, which exclude children (parentRequest IS NULL) so a collection
     * borrow surfaces grouped rather than duplicated in someone's inbox.
     *
     * Sparse; the caller gap-fills with StatsWindow::fill().
     *
     * @return array<string, array<string, int>> [event type value][Y-m-d] => count
     */
    public function countByTypeAndDay(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select("e.type AS type, DATE_TRUNC('day', e.createdAt) AS day, COUNT(e.id) AS total")
            ->where('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('e.type')
            ->addGroupBy('day')
            ->getQuery()
            ->getScalarResult();

        $series = [];
        foreach ($rows as $row) {
            // An enumType column hydrates as its raw backing value here.
            $type = (string) $row['type'];
            $series[$type][PageViewDailyRepository::dayKey($row['day'])] = (int) $row['total'];
        }

        return $series;
    }
}
