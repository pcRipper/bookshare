<?php

namespace App\Entity;

use App\Enum\ActivityType;
use App\Repository\ActivityItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityItemRepository::class)]
class ActivityItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(enumType: ActivityType::class)]
    private ActivityType $actionType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Book $targetBook = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $targetUser = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentText = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getActor(): User { return $this->actor; }
    public function setActor(User $actor): static { $this->actor = $actor; return $this; }

    public function getActionType(): ActivityType { return $this->actionType; }
    public function setActionType(ActivityType $actionType): static { $this->actionType = $actionType; return $this; }

    public function getTargetBook(): ?Book { return $this->targetBook; }
    public function setTargetBook(?Book $targetBook): static { $this->targetBook = $targetBook; return $this; }

    public function getTargetUser(): ?User { return $this->targetUser; }
    public function setTargetUser(?User $targetUser): static { $this->targetUser = $targetUser; return $this; }

    public function getCommentText(): ?string { return $this->commentText; }
    public function setCommentText(?string $commentText): static { $this->commentText = $commentText; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
