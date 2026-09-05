<?php

namespace App\Enum;

/**
 * The two kinds of manual dump the admin panel can produce.
 *
 * They are not two formats of the same thing, and the UI says so: only `Sql` is
 * a backup you could restore from. `Json` carries the rows and nothing else —
 * no schema, no sequences, no constraints — so it answers "what is in there?"
 * and never "put it back".
 */
enum DumpKind: string
{
    case Sql = 'sql';
    case Json = 'json';

    /** The file extension, which is also what tells the two apart on disk. */
    public function extension(): string
    {
        return match ($this) {
            self::Sql => 'dump',
            self::Json => 'json',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            // pg_dump's custom format is a compressed binary archive.
            self::Sql => 'application/octet-stream',
            self::Json => 'application/json',
        };
    }

    /** Whether this kind can be fed back into a database. */
    public function isRestorable(): bool
    {
        return $this === self::Sql;
    }
}
