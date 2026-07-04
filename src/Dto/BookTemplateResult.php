<?php

namespace App\Dto;

/**
 * One page of book-template search results plus whether more remain.
 *
 * Template search is infinite-scrolled, not numbered-paged: there's no reliable
 * total (external results are deduped, and upstream counts are approximate), so
 * the endpoint carries only `hasMore` — enough to decide whether to fetch the
 * next page — rather than the standard `{ total, totalPages }` envelope.
 */
final readonly class BookTemplateResult
{
    /**
     * @param BookTemplate[] $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {}
}
