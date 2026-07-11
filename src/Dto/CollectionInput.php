<?php

namespace App\Dto;

use App\Service\CollectionService;
use Symfony\Component\Validator\Constraints as Assert;

class CollectionInput
{
    #[Assert\NotBlank(message: 'A collection name is required.')]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    public ?string $description = null;

    #[Assert\Length(max: 500)]
    #[Assert\Url(message: 'The cover must be a valid URL.')]
    public ?string $coverUrl = null;

    /**
     * IDs of the owner's books to group. Must reference at least two — a
     * collection is only meaningful (and only borrowable) as a set. Ownership is
     * re-validated server-side; foreign ids are ignored.
     *
     * @var int[]
     */
    #[Assert\Count(min: CollectionService::MIN_BOOKS, minMessage: 'Pick at least {{ limit }} books.')]
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $bookIds = [];
}
