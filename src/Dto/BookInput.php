<?php

namespace App\Dto;

use App\Enum\BookStatus;
use App\Language\LanguageCatalog;
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

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    #[Assert\Length(max: 500)]
    public ?string $coverPath = null;

    /**
     * Denormalised from its string value; an invalid value yields a 422.
     *
     * `lent` is intentionally not selectable here: a loan is established only
     * through the request lifecycle (approve), which sets the status *and* the
     * current holder together. Accepting `lent` directly would let a book be
     * flagged on-loan while it still sits in its owner's hands.
     */
    #[Assert\Choice(
        choices: [BookStatus::Own, BookStatus::Unavailable, BookStatus::CurrentlyReading],
        message: 'A loan can only be set through the borrow-request flow.',
    )]
    public BookStatus $status = BookStatus::Own;

    /**
     * ISO 639-1 language code, or null when unspecified. Must be one of the
     * catalogued codes — a null skips the check, an unknown code yields a 422.
     */
    #[Assert\Choice(callback: [LanguageCatalog::class, 'codes'], message: 'Unknown language.')]
    public ?string $language = null;

    /**
     * IDs of categories to attach. They must already exist — new categories are
     * created up-front via POST /api/categories, then referenced here by id.
     *
     * @var int[]
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $categoryIds = [];
}
