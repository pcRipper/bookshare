<?php

namespace App\Dto;

use App\Enum\BookStatus;
use Symfony\Component\Validator\Constraints as Assert;

class BookInput
{
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(max: 255)]
    public string $title = '';

    #[Assert\NotBlank(message: 'Author is required.')]
    #[Assert\Length(max: 255)]
    public string $author = '';

    #[Assert\Length(max: 32)]
    public ?string $isbn = null;

    #[Assert\Length(max: 500)]
    public ?string $coverPath = null;

    /** Denormalised from its string value; an invalid value yields a 422. */
    public BookStatus $status = BookStatus::Own;

    /**
     * IDs of categories to attach. They must already exist — new categories are
     * created up-front via POST /api/categories, then referenced here by id.
     *
     * @var int[]
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $categoryIds = [];
}
