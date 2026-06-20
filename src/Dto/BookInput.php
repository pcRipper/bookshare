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

    /** @var string[] */
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 255)])]
    public array $categories = [];
}
