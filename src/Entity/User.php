<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $googleId;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $fullName;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarUrl = null;

    /**
     * The remote URL $avatarUrl was downloaded from (a Google profile photo), kept
     * so the original is never lost to localization. Null whenever $avatarUrl is
     * not a localized copy — a link pasted in Settings, a failed fetch, or no avatar.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarSourceUrl = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    /**
     * When true the profile is hidden: its books are excluded from Discover,
     * other members can't browse its collection, and borrow requests against
     * its books are rejected. The owner always sees their own profile.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isPrivate = false;

    /**
     * Tweakable preferences (privacy/notification toggles), kept in their own
     * table. Null until the user first touches their settings; callers treat
     * null as "all defaults".
     */
    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UserSettings::class, cascade: ['persist'])]
    private ?UserSettings $settings = null;

    /**
     * Roles granted on top of the baseline — today only ROLE_ADMIN, held by the
     * site operator. ROLE_USER is implied by getRoles() and never stored, so an
     * ordinary member's column stays `[]`: "no extra grants" and "ordinary" are
     * the same state, and no backfill can get the two out of step.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $roles = [];

    /**
     * Set when an administrator suspends the account. Two things follow from it,
     * and they are deliberately separate mechanisms:
     *
     *   - Sign-in stops, enforced by App\Security\UserChecker. Because the
     *     firewall reloads the user from the database on every request, an
     *     already-issued JWT dies on the suspended member's next call — the same
     *     property that lets `app:grant-admin` take effect without a re-login,
     *     and the reason this needs no token-revocation path.
     *   - Their content leaves the community surfaces, enforced query by query
     *     through App\Repository\VisibleUsers.
     *
     * Reversible: unbanning clears the stamp and the reason together.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bannedAt = null;

    /** The operator's note on why, shown only inside the admin panel. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $banReason = null;

    /**
     * Set when the account is deleted. The row survives, anonymized by
     * App\Service\Admin\UserPurger — a hard DELETE would take the other party's
     * loan history with it, since library_request.requester_id and book.owner_id
     * carry no ON DELETE rule.
     *
     * Not reversible: by the time this is set the identifying columns have
     * already been overwritten and the member's books are gone.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getGoogleId(): string { return $this->googleId; }
    public function setGoogleId(string $googleId): static { $this->googleId = $googleId; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getFullName(): string { return $this->fullName; }
    public function setFullName(string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function setAvatarUrl(?string $avatarUrl): static { $this->avatarUrl = $avatarUrl; return $this; }

    public function getAvatarSourceUrl(): ?string { return $this->avatarSourceUrl; }
    public function setAvatarSourceUrl(?string $avatarSourceUrl): static { $this->avatarSourceUrl = $avatarSourceUrl; return $this; }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): static { $this->bio = $bio; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): static { $this->location = $location; return $this; }

    public function isPrivate(): bool { return $this->isPrivate; }
    public function setIsPrivate(bool $isPrivate): static { $this->isPrivate = $isPrivate; return $this; }

    public function getSettings(): ?UserSettings { return $this->settings; }
    public function setSettings(?UserSettings $settings): static
    {
        $this->settings = $settings;
        if ($settings !== null) {
            $settings->setUser($this);
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getBannedAt(): ?\DateTimeImmutable { return $this->bannedAt; }
    public function getBanReason(): ?string { return $this->banReason; }

    /**
     * The stamp and the reason move together: a reason left behind by a lifted
     * ban would read in the admin table as an explanation for a state the member
     * is no longer in.
     */
    public function ban(?string $reason = null): static
    {
        $this->bannedAt = new \DateTimeImmutable();
        $this->banReason = $reason;

        return $this;
    }

    public function unban(): static
    {
        $this->bannedAt = null;
        $this->banReason = null;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static { $this->deletedAt = $deletedAt; return $this; }

    public function isBanned(): bool { return $this->bannedAt !== null; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    /** Neither suspended nor deleted — the state every ordinary member is in. */
    public function isActive(): bool { return !$this->isBanned() && !$this->isDeleted(); }

    /** @param string[] $roles */
    public function setRoles(array $roles): static { $this->roles = array_values(array_unique($roles)); return $this; }

    public function isAdmin(): bool { return \in_array(self::ROLE_ADMIN, $this->getRoles(), true); }

    // UserInterface
    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        // ROLE_USER is the floor every authenticated member stands on; merging it
        // here rather than storing it keeps the column meaning "extra grants".
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void {}
}
