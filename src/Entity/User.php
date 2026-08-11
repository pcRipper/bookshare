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
