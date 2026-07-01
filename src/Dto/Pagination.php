<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * Offset-based pagination parameters for a list endpoint.
 *
 * Built from the request query (`?page=&perPage=`) with a per-endpoint default
 * page size. Input is **clamped, never rejected** — a browse UI shouldn't 422 on
 * a stray query param — so `page` is coerced to >= 1 and `perPage` into
 * [1, MAX_PER_PAGE]; garbage falls back to the default.
 */
final class Pagination
{
    /** Hard ceiling on page size so a caller can't ask for an unbounded slice. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public static function fromRequest(Request $request, int $defaultPerPage): self
    {
        return new self(
            self::coerce($request->query->get('page'), 1, 1, PHP_INT_MAX),
            self::coerce($request->query->get('perPage'), $defaultPerPage, 1, self::MAX_PER_PAGE),
        );
    }

    /** Zero-based offset of the first row on this page. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Parses a positive-integer query value, falling back to $default for
     * missing/non-numeric input and clamping the result into [$min, $max].
     */
    private static function coerce(mixed $raw, int $default, int $min, int $max): int
    {
        if ($raw === null || !ctype_digit((string) $raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}
