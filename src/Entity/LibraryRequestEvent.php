<?php

namespace App\Entity;

use App\Enum\LibraryRequestEventType;
use App\Repository\LibraryRequestEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable record of a single step in a LibraryRequest's lifecycle
 * (requested → approved/declined → return requested → returned). Appended on
 * every transition so the complete history of a loan can be retraced, instead
 * of only the request's final status being visible.
 */
#[ORM\Entity(repositoryClass: LibraryRequestEventRepository::class)]
class LibraryRequestEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LibraryRequest $request;

    #[ORM\Column(enumType: LibraryRequestEventType::class)]
    private LibraryRequestEventType $type;

    /** Who performed this step (requester for borrow/return, owner for approve/decline/confirm). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    /** The return-by date captured at approval time; null for other event types. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRequest(): LibraryRequest { return $this->request; }
    public function setRequest(LibraryRequest $request): static { $this->request = $request; return $this; }

    public function getType(): LibraryRequestEventType { return $this->type; }
    public function setType(LibraryRequestEventType $type): static { $this->type = $type; return $this; }

    public function getActor(): User { return $this->actor; }
    public function setActor(User $actor): static { $this->actor = $actor; return $this; }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $dueDate): static { $this->dueDate = $dueDate; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
