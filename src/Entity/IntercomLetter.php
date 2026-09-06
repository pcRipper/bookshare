<?php

namespace App\Entity;

use App\Repository\IntercomLetterRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A letter an operator sent from the Intercom tab, kept so the panel has a
 * memory.
 *
 * Without it the obvious mistake is sending the same round-up twice: nothing
 * else in the system can answer "did the 1.28 letter already go out?" — the
 * `mail` monolog channel records one line per recipient, which is the wrong
 * shape for that question and is rotated away in production.
 *
 * Append-only, like ActivityItem: a sent letter is a fact about the past, so
 * there is no edit and no delete. `sentBy` keeps ON DELETE SET NULL for the same
 * reason ActivityItem's target does — an anonymized operator must not take the
 * record of what they sent with them (see App\Service\Admin\UserPurger).
 */
#[ORM\Entity(repositoryClass: IntercomLetterRepository::class)]
class IntercomLetter
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $subject;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $intro = null;

    /**
     * The composed letter exactly as it went out: `[{version, lines[]}]`.
     *
     * Stored rather than re-derived, because it is the operator's edited text
     * and not the changelog — replaying it from `changelog.js` later would show
     * something nobody ever sent. Plain JSON, not JSONB, for the reason
     * User.roles is: it is never queried into, and JSONB would make every
     * migrations:diff emit a phantom ALTER.
     *
     * @var array<int, array{version: string, lines: string[]}>
     */
    #[ORM\Column(type: 'json')]
    private array $items = [];

    /** How many members it was queued to, at the moment it was sent. */
    #[ORM\Column]
    private int $recipientCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $sentBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getIntro(): ?string { return $this->intro; }
    public function setIntro(?string $intro): static { $this->intro = $intro; return $this; }

    /** @return array<int, array{version: string, lines: string[]}> */
    public function getItems(): array { return $this->items; }

    /** @param array<int, array{version: string, lines: string[]}> $items */
    public function setItems(array $items): static { $this->items = $items; return $this; }

    public function getRecipientCount(): int { return $this->recipientCount; }
    public function setRecipientCount(int $count): static { $this->recipientCount = $count; return $this; }

    public function getSentBy(): ?User { return $this->sentBy; }
    public function setSentBy(?User $sentBy): static { $this->sentBy = $sentBy; return $this; }

    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }
}
