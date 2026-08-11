<?php

namespace App\Entity;

use App\Repository\PageViewDailyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (route, day), carrying a hit counter — the whole of the traffic
 * dataset.
 *
 * $route is an SPA *route name* from App\Analytics\AnalyticsRoutes, never a URL
 * path. Paths carry ids (/profile/42, /public/library/7), so storing them would
 * give the table unbounded cardinality — a row per profile per day — and turn
 * "top pages" into a list of individuals. Route names cap this at roughly ten
 * rows a day whatever the traffic.
 *
 * $day is a real DATE rather than a timestamp, which is what lets the dashboard
 * group by it in plain DQL with no date function involved.
 *
 * The table is append-and-increment only and deliberately not on the auditor
 * whitelist (see config/packages/dh_auditor.yaml): auditing a hit counter would
 * double every write and produce a diff log longer than the data.
 */
#[ORM\Entity(repositoryClass: PageViewDailyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_page_view_daily_route_day', columns: ['route', 'day'])]
// Not redundant with the unique constraint above: `day` is its *trailing*
// column, and every window query filters on `day` alone.
#[ORM\Index(name: 'idx_page_view_daily_day', columns: ['day'])]
class PageViewDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $route;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column(options: ['default' => 0])]
    private int $views = 0;

    public function getId(): ?int { return $this->id; }

    public function getRoute(): string { return $this->route; }
    public function setRoute(string $route): static { $this->route = $route; return $this; }

    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function setDay(\DateTimeImmutable $day): static { $this->day = $day; return $this; }

    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }
}
