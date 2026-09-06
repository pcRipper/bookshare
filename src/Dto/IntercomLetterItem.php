<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One version's worth of a composed letter: the heading, and the short lines the
 * operator edited down from that version's release notes.
 *
 * The lines are whatever they typed, not the changelog text — the whole point of
 * the composer is that a release note is prose written for a page and a letter
 * wants one line. See App\Service\Admin\IntercomService.
 */
class IntercomLetterItem
{
    #[Assert\NotBlank(message: 'A version is required.', normalizer: 'trim')]
    #[Assert\Length(max: 40)]
    public string $version = '';

    /**
     * @var string[]
     */
    #[Assert\Count(min: 1, max: 10, minMessage: 'Select at least one update.', maxMessage: 'A version can carry at most 10 lines.')]
    #[Assert\All([
        new Assert\NotBlank(message: 'An update line cannot be blank.', normalizer: 'trim'),
        new Assert\Length(max: 200, maxMessage: 'An update line cannot be longer than 200 characters.'),
    ])]
    public array $lines = [];
}
