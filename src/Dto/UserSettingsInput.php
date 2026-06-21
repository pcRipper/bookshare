<?php

namespace App\Dto;

/**
 * Editable user preferences. Every field is optional: PATCH /api/me/settings
 * applies only the keys present in the request body, so the frontend can flip a
 * single toggle without resending the whole set.
 */
class UserSettingsInput
{
    public ?bool $allowRequests = null;
    public ?bool $showLocation = null;
    public ?bool $notifyBorrowRequests = null;
    public ?bool $notifyRequestUpdates = null;
    public ?bool $notifyActivity = null;
    public ?bool $notifyNewsletter = null;
}
