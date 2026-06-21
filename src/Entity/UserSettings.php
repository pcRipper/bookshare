<?php

namespace App\Entity;

use App\Repository\UserSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user preferences kept deliberately separate from the core User entity:
 * these are tweakable knobs (privacy toggles, notification opt-ins), not
 * identity, so they live in their own table and are fetched/updated through a
 * dedicated /me/settings resource rather than bloating the user payload.
 *
 * Booleans default to the same values on the PHP side and in the DB schema, so
 * a user without a row yet behaves as if every setting is at its default.
 */
#[ORM\Entity(repositoryClass: UserSettingsRepository::class)]
#[ORM\Table(name: 'user_settings')]
class UserSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'settings')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    /** When false, other members can't file borrow requests against this user's books. */
    #[ORM\Column(options: ['default' => true])]
    private bool $allowRequests = true;

    /** When false, the user's location is hidden from other readers' view of their profile. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showLocation = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyBorrowRequests = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyRequestUpdates = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifyActivity = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifyNewsletter = false;

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function allowsRequests(): bool { return $this->allowRequests; }
    public function setAllowRequests(bool $value): static { $this->allowRequests = $value; return $this; }

    public function showsLocation(): bool { return $this->showLocation; }
    public function setShowLocation(bool $value): static { $this->showLocation = $value; return $this; }

    public function notifiesBorrowRequests(): bool { return $this->notifyBorrowRequests; }
    public function setNotifyBorrowRequests(bool $value): static { $this->notifyBorrowRequests = $value; return $this; }

    public function notifiesRequestUpdates(): bool { return $this->notifyRequestUpdates; }
    public function setNotifyRequestUpdates(bool $value): static { $this->notifyRequestUpdates = $value; return $this; }

    public function notifiesActivity(): bool { return $this->notifyActivity; }
    public function setNotifyActivity(bool $value): static { $this->notifyActivity = $value; return $this; }

    public function notifiesNewsletter(): bool { return $this->notifyNewsletter; }
    public function setNotifyNewsletter(bool $value): static { $this->notifyNewsletter = $value; return $this; }
}
