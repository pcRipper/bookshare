<?php

namespace App\Entity;

use App\Enum\RequestStatus;
use App\Repository\CollectionRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The parent of a collection borrow: one request the owner approves/declines and
 * the borrower returns as a whole. It fans out into one child LibraryRequest per
 * selected book (each carrying its own per-book lifecycle + audit events); this
 * parent holds the aggregate status and lender-set due date.
 */
#[ORM\Entity(repositoryClass: CollectionRequestRepository::class)]
#[ORM\Table(name: 'collection_request')]
class CollectionRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BookCollection $collection;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $requester;

    #[ORM\Column(enumType: RequestStatus::class)]
    private RequestStatus $status = RequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Return-by date set by the lender on approval, applied to every child loan. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    /**
     * When the "due tomorrow" and "overdue" reminders were mailed to the
     * borrower. Two nullable timestamps rather than a reminder-log entity: the
     * only question asked of them is "has this one gone out yet", and as columns
     * the answer is part of the query that finds the loans to remind about - so
     * a daily cron cannot double-send even if it runs twice.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueReminderSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $overdueReminderSentAt = null;

    /** The owner's optional reason when declining the whole collection request. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $declineMessage = null;

    /**
     * The per-book child loans this collection borrow fanned out into.
     *
     * @var Collection<int, LibraryRequest>
     */
    #[ORM\OneToMany(targetEntity: LibraryRequest::class, mappedBy: 'parentRequest', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $children;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getCollection(): BookCollection { return $this->collection; }
    public function setCollection(BookCollection $collection): static { $this->collection = $collection; return $this; }

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

    public function getDueReminderSentAt(): ?\DateTimeImmutable { return $this->dueReminderSentAt; }
    public function setDueReminderSentAt(?\DateTimeImmutable $at): static { $this->dueReminderSentAt = $at; return $this; }

    public function getOverdueReminderSentAt(): ?\DateTimeImmutable { return $this->overdueReminderSentAt; }
    public function setOverdueReminderSentAt(?\DateTimeImmutable $at): static { $this->overdueReminderSentAt = $at; return $this; }

    public function getDeclineMessage(): ?string { return $this->declineMessage; }
    public function setDeclineMessage(?string $declineMessage): static { $this->declineMessage = $declineMessage; return $this; }

    /** @return Collection<int, LibraryRequest> */
    public function getChildren(): Collection { return $this->children; }

    public function addChild(LibraryRequest $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParentRequest($this);
        }
        return $this;
    }
}
