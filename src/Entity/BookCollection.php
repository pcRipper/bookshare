<?php

namespace App\Entity;

use App\Repository\CollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named, owner-curated grouping of the owner's own books (e.g. a series).
 * Books are referenced many-to-many — a book may sit in several collections —
 * and a collection is borrowable as a unit (see CollectionRequest).
 */
#[ORM\Entity(repositoryClass: CollectionRepository::class)]
#[ORM\Table(name: 'book_collection')]
class BookCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Free-text blurb; null when unset. Length is capped at the DTO (500). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    /** Optional cover image URL for the collection card; null falls back to a motif. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $coverUrl = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    /** @var Collection<int, Book> */
    #[ORM\ManyToMany(targetEntity: Book::class)]
    #[ORM\JoinTable(name: 'book_collection_book')]
    private Collection $books;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->books = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getCoverUrl(): ?string { return $this->coverUrl; }
    public function setCoverUrl(?string $coverUrl): static { $this->coverUrl = $coverUrl; return $this; }

    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }

    /** @return Collection<int, Book> */
    public function getBooks(): Collection { return $this->books; }

    public function addBook(Book $book): static
    {
        if (!$this->books->contains($book)) {
            $this->books->add($book);
        }
        return $this;
    }

    public function removeBook(Book $book): static
    {
        $this->books->removeElement($book);
        return $this;
    }

    public function clearBooks(): static
    {
        $this->books->clear();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
