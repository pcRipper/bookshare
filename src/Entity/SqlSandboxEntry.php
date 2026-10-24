<?php

namespace App\Entity;

use App\Enum\SqlKind;
use App\Enum\SqlOutcome;
use App\Repository\SqlSandboxEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt in the admin SQL sandbox — accepted, rejected or failed.
 *
 * This table is the only record of what the sandbox changed: its statements run
 * through DBAL on their own connection, so auditor-bundle never sees them and no
 * `_audit` row is written. That is why every attempt is kept, including the ones
 * that never reached the database, and why the sandbox's own Postgres role holds
 * no privilege on this table at all — it cannot read, edit or erase its trail.
 *
 * Append-only like IntercomLetter. `operator` is ON DELETE SET NULL so an
 * anonymized administrator does not take the record of what they ran with them.
 */
#[ORM\Entity(repositoryClass: SqlSandboxEntryRepository::class)]
class SqlSandboxEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $operator = null;

    #[ORM\Column(type: 'text')]
    private string $statement;

    /** Null when the guard could not classify it (it was rejected first). */
    #[ORM\Column(length: 8, nullable: true, enumType: SqlKind::class)]
    private ?SqlKind $kind = null;

    #[ORM\Column(length: 16, enumType: SqlOutcome::class)]
    private SqlOutcome $outcome;

    /** Rows returned by a read, or affected by a write. */
    #[ORM\Column(nullable: true)]
    private ?int $rowCount = null;

    #[ORM\Column]
    private int $durationMs = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $executedAt;

    public function __construct(?User $operator, string $statement, SqlOutcome $outcome)
    {
        $this->operator = $operator;
        $this->statement = $statement;
        $this->outcome = $outcome;
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOperator(): ?User { return $this->operator; }
    public function getStatement(): string { return $this->statement; }

    public function getKind(): ?SqlKind { return $this->kind; }
    public function setKind(?SqlKind $kind): static { $this->kind = $kind; return $this; }

    public function getOutcome(): SqlOutcome { return $this->outcome; }

    public function getRowCount(): ?int { return $this->rowCount; }
    public function setRowCount(?int $rowCount): static { $this->rowCount = $rowCount; return $this; }

    public function getDurationMs(): int { return $this->durationMs; }
    public function setDurationMs(int $ms): static { $this->durationMs = $ms; return $this; }

    public function getError(): ?string { return $this->error; }

    public function setError(?string $error): static
    {
        $this->error = $error === null ? null : mb_substr($error, 0, 500);

        return $this;
    }

    public function getExecutedAt(): \DateTimeImmutable { return $this->executedAt; }
}
