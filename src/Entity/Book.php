<?php

namespace App\Entity;

use App\Enum\BookStatus;
use App\Repository\BookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $author;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $isbn = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $coverPath = null;

    #[ORM\Column(enumType: BookStatus::class)]
    private BookStatus $status = BookStatus::Own;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'book_category')]
    private Collection $categories;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getAuthor(): string { return $this->author; }
    public function setAuthor(string $author): static { $this->author = $author; return $this; }

    public function getIsbn(): ?string { return $this->isbn; }
    public function setIsbn(?string $isbn): static { $this->isbn = $isbn; return $this; }

    public function getCoverPath(): ?string { return $this->coverPath; }
    public function setCoverPath(?string $coverPath): static { $this->coverPath = $coverPath; return $this; }

    public function getStatus(): BookStatus { return $this->status; }
    public function setStatus(BookStatus $status): static { $this->status = $status; return $this; }

    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection { return $this->categories; }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);
        return $this;
    }

    public function clearCategories(): static
    {
        $this->categories->clear();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
