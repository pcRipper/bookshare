<?php

namespace App\Dto;

use App\Enum\BookStatus;
use App\Enum\WishPriority;
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

    #[Assert\Length(max: 500)]
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

    /** Owner's "already read" flag; defaults to unread. */
    public bool $isRead = false;

    /** True to file this as a book the owner *wants* rather than one they hold. */
    public bool $isWished = false;

    /**
     * How badly it's wanted. Ignored (and stored as null) unless $isWished —
     * normalised in BookService rather than rejected, so a client that leaves a
     * stale priority on a book it just un-wished still gets a coherent record
     * instead of a 422 about a field it isn't thinking about.
     *
     * No Assert\Choice: every case is allowed, and denormalising the payload
     * already yields a 422 for a value outside the enum.
     */
    public ?WishPriority $wishPriority = null;

    /**
     * IDs of categories to attach. They must already exist — new categories are
     * created up-front via POST /api/categories, then referenced here by id.
     *
     * @var int[]
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $categoryIds = [];
}
