<?php

namespace App\Service\Admin\Sql;

use App\Enum\SqlKind;

/**
 * What one sandbox statement produced. A read carries columns and rows; a write
 * carries the number of rows it touched and whether that was kept.
 */
final class SqlResult
{
    /**
     * @param list<string>                $columns
     * @param list<list<scalar|null>>     $rows
     */
    public function __construct(
        public readonly SqlKind $kind,
        public readonly int $rowCount,
        public readonly int $durationMs,
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly bool $truncated = false,
        public readonly bool $committed = false,
    ) {}
}
