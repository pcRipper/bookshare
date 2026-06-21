<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Editable fields of the current user's profile. All optional: PATCH /api/me
 * applies only the keys actually present in the request body (partial update),
 * so different clients can send just the subset they edit.
 */
class ProfileInput
{
    #[Assert\Length(max: 255)]
    public ?string $fullName = null;

    #[Assert\Length(max: 300)]
    public ?string $bio = null;

    #[Assert\Length(max: 255)]
    public ?string $location = null;

    #[Assert\Length(max: 500)]
    public ?string $avatarUrl = null;

    /** Profile visibility. true ⇒ hidden from Discover and other readers. */
    public ?bool $isPrivate = null;
}
