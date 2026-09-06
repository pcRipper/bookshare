<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A letter an operator composed in the Intercom tab.
 *
 * Everything here is capped, which matters more than usual: this is the only
 * payload in the application that fans out into mail to every opted-in member,
 * so an unbounded one would be an unbounded send. The caps are generous for a
 * human writing a round-up and far below anything that could be abused.
 */
class IntercomLetterInput
{
    // Trimmed before the check: NotBlank alone accepts "   ", and a whitespace
    // subject means every recipient sees a blank subject line.
    #[Assert\NotBlank(message: 'A subject is required.', normalizer: 'trim')]
    #[Assert\Length(max: 120, maxMessage: 'A subject cannot be longer than 120 characters.')]
    public string $subject = '';

    /** Optional opening line; the template falls back to a translated default. */
    #[Assert\Length(max: 300, maxMessage: 'An intro cannot be longer than 300 characters.')]
    public ?string $intro = null;

    /**
     * @var IntercomLetterItem[]
     */
    #[Assert\Count(min: 1, max: 20, minMessage: 'Select at least one update.', maxMessage: 'A letter can carry at most 20 versions.')]
    #[Assert\Valid]
    public array $items = [];
}
