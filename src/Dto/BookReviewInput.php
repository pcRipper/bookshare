<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The owner's verdict on one of their books: a star rating, words, or both.
 *
 * Its own DTO rather than fields on BookInput, because it is not what the Manage
 * Book modal edits — a book's cover, ISBN and shelf are inventory, a review is
 * not. Keeping them apart also means a PATCH from that form can never overwrite
 * a review, which is the trap `toBookInput` exists to warn about.
 *
 * Every field is nullable and clearing both is how a review is deleted: there is
 * no separate DELETE, since "no rating and no words" is exactly the state a book
 * starts in.
 */
class BookReviewInput
{
    /**
     * 1-5, or null for "not rated".
     *
     * Rejected rather than clamped, unlike WishPriority: a value outside the
     * scale is a client that means something else by the number, and silently
     * storing 5 for a 10 would be worse than saying so.
     */
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'A rating must be between 1 and 5 stars.')]
    public ?int $rating = null;

    /** The written review; null or blank means none. */
    #[Assert\Length(max: 1000, maxMessage: 'A review cannot be longer than 1000 characters.')]
    public ?string $review = null;
}
