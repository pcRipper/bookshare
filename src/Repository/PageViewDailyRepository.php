<?php

namespace App\Repository;

use App\Entity\PageViewDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageViewDaily>
 */
class PageViewDailyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageViewDaily::class);
    }

    /**
     * Adds one hit to (route, day), creating the row on the first hit of the day.
     *
     * Deliberately *not* a read-modify-write through the ORM: two concurrent
     * requests would both read the same count and one increment would be lost,
     * and the first hit of a day would race on the unique constraint — whose
     * failed flush() closes the EntityManager for the rest of the request.
     *
     * Instead the UPDATE is a single atomic statement (`views + 1` is computed by
     * Postgres, never read into PHP), and the insert path catches the expected
     * unique violation from a concurrent first hit and falls back to the UPDATE,
     * which by then succeeds. Costs one extra round-trip on the first hit of each
     * (route, day) — around ten times a day in total.
     *
     * An `INSERT ... ON CONFLICT DO UPDATE` would do this in one statement, but it
     * would be the only native SQL in src/; this keeps the query layer pure DQL
     * and portable at a cost that does not matter at this write volume. It is a
     * drop-in swap behind this signature if it ever does.
     *
     * Note the UPDATE is invisible to the ORM: any PageViewDaily already in the
     * identity map keeps its old $views, and hydration will not overwrite it.
     * Nothing reads a counter back in the same request that writes one — the
     * ingest endpoint answers 204 — but clear() first if that ever changes.
     */
    public function increment(string $route, \DateTimeImmutable $day): void
    {
        if ($this->addOne($route, $day) === 1) {
            return;
        }

        try {
            $row = (new PageViewDaily())->setRoute($route)->setDay($day)->setViews(1);
            $this->getEntityManager()->persist($row);
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row between our UPDATE and INSERT.
            // Expected, not exceptional — its counter is there now, so add to it.
            $this->addOne($route, $day);
        }
    }

    /**
     * Total views per route over the window, most-viewed first.
     *
     * @return list<array{route: string, views: int}>
     */
    public function topRoutes(\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.route AS route, SUM(p.views) AS total')
            ->where('p.day >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.route')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row) => ['route' => (string) $row['route'], 'views' => (int) $row['total']],
            $rows,
        );
    }

    /**
     * Views per day over the window. Sparse — days with no traffic are absent,
     * and the caller gap-fills against StatsWindow::dayKeys().
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function viewsByDay(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.day AS day, SUM(p.views) AS total')
            ->where('p.day >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.day')
            ->getQuery()
            ->getScalarResult();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[self::dayKey($row['day'])] = (int) $row['total'];
        }

        return $byDay;
    }

    /** Total views over the window, for the KPI tile. */
    public function totalViews(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.views), 0)')
            ->where('p.day >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A DQL UPDATE is one atomic statement, so concurrent hits can't clobber each
     * other the way a read-modify-write would.
     *
     * @return int rows affected: 1 when the counter existed, 0 on the day's first hit
     */
    private function addOne(string $route, \DateTimeImmutable $day): int
    {
        return (int) $this->createQueryBuilder('p')
            ->update()
            ->set('p.views', 'p.views + 1')
            ->where('p.route = :route')
            ->andWhere('p.day = :day')
            ->setParameter('route', $route)
            ->setParameter('day', $day)
            ->getQuery()
            ->execute();
    }

    /** getScalarResult() hands back a DateTimeInterface or a raw string by platform. */
    public static function dayKey(mixed $day): string
    {
        return $day instanceof \DateTimeInterface
            ? $day->format('Y-m-d')
            : substr((string) $day, 0, 10);
    }
}
