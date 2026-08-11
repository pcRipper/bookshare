<?php

namespace App\Entity;

use App\Repository\PageViewVisitorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (day, distinct visitor) — not per visit. This is the minimum state
 * an exact daily-unique count needs: you cannot know whether to increment a
 * counter without remembering whom you already counted today, and there is no
 * HyperLogLog to approximate it with.
 *
 * $visitorHash is salted with the application secret *and the day itself*, so
 * the same browser hashes differently tomorrow (see PageViewRecorder). The value
 * is a within-day equality token, never a cross-day identifier, and it holds no
 * recoverable IP, user agent or user id.
 *
 * $authenticated is what makes daily active users cheap: DAU is simply this
 * table filtered to true. It costs no extra rows and no extra retained data.
 *
 * Pruned by `app:prune-analytics` (120 days by default) — comfortably past the
 * dashboard's 90-day maximum window, so nothing askable is ever lost.
 */
#[ORM\Entity(repositoryClass: PageViewVisitorRepository::class)]
// `day` leads this index, so the window range scans use it and no separate
// single-column index is needed.
#[ORM\UniqueConstraint(name: 'uniq_page_view_visitor_day_hash', columns: ['day', 'visitor_hash'])]
class PageViewVisitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 32)]
    private string $visitorHash;

    #[ORM\Column(options: ['default' => false])]
    private bool $authenticated = false;

    public function getId(): ?int { return $this->id; }

    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function setDay(\DateTimeImmutable $day): static { $this->day = $day; return $this; }

    public function getVisitorHash(): string { return $this->visitorHash; }
    public function setVisitorHash(string $visitorHash): static { $this->visitorHash = $visitorHash; return $this; }

    public function isAuthenticated(): bool { return $this->authenticated; }
    public function setAuthenticated(bool $authenticated): static { $this->authenticated = $authenticated; return $this; }
}
