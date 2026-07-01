<?php

namespace App\Dto;

/**
 * A single page of results plus the total count of matching rows, as returned
 * by a paginated repository query. The total drives `totalPages`/`hasMore` in
 * the JSON envelope (see ResponseMapper::paginated).
 *
 * @template T
 */
final class PaginatedResult
{
    /** @param T[] $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {}
}
