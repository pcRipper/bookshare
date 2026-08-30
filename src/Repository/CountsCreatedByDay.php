<?php

namespace App\Repository;

/**
 * Shared "how many of these were created each day" query for the dashboard's
 * growth series. Used by User, Book and BookCollection, whose entities differ
 * only in name — the alternative was the same eight lines three times.
 *
 * DATE_TRUNC is registered in config/packages/doctrine.yaml from
 * beberlei/doctrineextensions; Doctrine ships no date function of its own. It is
 * PostgreSQL-specific, in keeping with the raw SQL in migrations/.
 */
trait CountsCreatedByDay
{
    /**
     * Rows created per calendar day since the cutoff.
     *
     * Sparse: days with nothing created are absent, because SQL can only return
     * days that have rows. Callers project this onto the full axis with
     * StatsWindow::fill(), which is where the zeroes come from.
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function countCreatedByDay(\DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select("DATE_TRUNC('day', e.createdAt) AS day, COUNT(e.id) AS total")
            ->where('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('day');

        $this->scopeCreatedByDay($qb);

        $rows = $qb->getQuery()->getScalarResult();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[PageViewDailyRepository::dayKey($row['day'])] = (int) $row['total'];
        }

        return $byDay;
    }

    /**
     * Hook for a repository whose table holds rows that aren't really "created
     * things" — BookRepository excludes wish-list books here. The alias is `e`.
     * No-op by default, so User and BookCollection are unaffected.
     */
    protected function scopeCreatedByDay(\Doctrine\ORM\QueryBuilder $qb): void
    {
    }
}
