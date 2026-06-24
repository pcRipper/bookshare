<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A directed "follow" edge: $subscriber follows $subscribedTo. The unique
 * constraint keeps the relationship idempotent (you can't follow someone
 * twice); both sides cascade-delete so removing a user tears down their edges.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_subscription_pair', columns: ['subscriber_id', 'subscribed_to_id'])]
#[ORM\Index(name: 'idx_subscription_subscriber', columns: ['subscriber_id'])]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subscriber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subscribedTo;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubscriber(): User { return $this->subscriber; }
    public function setSubscriber(User $subscriber): static { $this->subscriber = $subscriber; return $this; }

    public function getSubscribedTo(): User { return $this->subscribedTo; }
    public function setSubscribedTo(User $subscribedTo): static { $this->subscribedTo = $subscribedTo; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
