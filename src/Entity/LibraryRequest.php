<?php

namespace App\Entity;

use App\Enum\RequestStatus;
use App\Repository\LibraryRequestRepository;
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

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
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
}
