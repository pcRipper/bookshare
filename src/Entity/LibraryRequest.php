<?php

namespace App\Entity;

use App\Enum\LibraryRequestEventType;
use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LibraryRequestRepository::class)]
class LibraryRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Book $book;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $requester;

    #[ORM\Column(enumType: RequestStatus::class)]
    private RequestStatus $status = RequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Return-by date set by the lender on approval (no borrower approval needed). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** When the owner confirmed the book was received back. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    /**
     * The full ordered audit trail of this request's lifecycle. Each transition
     * appends one event so the history can be retraced step by step.
     *
     * @var Collection<int, LibraryRequestEvent>
     */
    #[ORM\OneToMany(targetEntity: LibraryRequestEvent::class, mappedBy: 'request', cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): static { $this->book = $book; return $this; }

    public function getRequester(): User { return $this->requester; }
    public function setRequester(User $requester): static { $this->requester = $requester; return $this; }

    public function getStatus(): RequestStatus { return $this->status; }
    public function setStatus(RequestStatus $status): static { $this->status = $status; return $this; }

    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }

    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static { $this->resolvedAt = $resolvedAt; return $this; }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $dueDate): static { $this->dueDate = $dueDate; return $this; }

    public function getReturnedAt(): ?\DateTimeImmutable { return $this->returnedAt; }
    public function setReturnedAt(?\DateTimeImmutable $returnedAt): static { $this->returnedAt = $returnedAt; return $this; }

    /** @return Collection<int, LibraryRequestEvent> */
    public function getEvents(): Collection { return $this->events; }

    /**
     * Appends a lifecycle event. Sets both sides of the relation; the event is
     * persisted via the cascade when the request is flushed.
     */
    public function addEvent(LibraryRequestEventType $type, User $actor, ?\DateTimeImmutable $dueDate = null): LibraryRequestEvent
    {
        $event = (new LibraryRequestEvent())
            ->setRequest($this)
            ->setType($type)
            ->setActor($actor)
            ->setDueDate($dueDate);
        $this->events->add($event);

        return $event;
    }
}
