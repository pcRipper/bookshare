<?php

namespace App\Dto;

use App\Enum\DumpKind;

/**
 * One dump on disk, as the admin panel sees it.
 *
 * Read from the filesystem rather than a table: the files *are* the record, and
 * a database row tracking them is a second source of truth that goes wrong the
 * first time somebody deletes one over SSH.
 */
final class DumpFile
{
    public function __construct(
        public readonly string $name,
        public readonly DumpKind $kind,
        public readonly int $bytes,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
