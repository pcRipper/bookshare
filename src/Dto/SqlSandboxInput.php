<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One statement for the admin SQL sandbox.
 *
 * A write is always sent twice: first with `commit` false, which runs it and
 * rolls it back to report how many rows it touches, then with `commit` true and
 * `expectedRows` set to the count the operator was shown. If the count differs
 * by the time the commit runs, the server refuses — the data moved under the
 * preview, and the operator should look again.
 */
class SqlSandboxInput
{
    public const MAX_LENGTH = 10000;

    #[Assert\NotBlank(message: 'Enter a statement to run.')]
    #[Assert\Length(max: self::MAX_LENGTH, maxMessage: 'A statement cannot be longer than 10000 characters.')]
    public string $sql = '';

    public bool $commit = false;

    #[Assert\PositiveOrZero]
    public ?int $expectedRows = null;
}
