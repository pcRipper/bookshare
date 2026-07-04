<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplateResult;

/**
 * A source of book templates for the "Add New Book" search. Each implementation
 * is a strategy keyed by {@see key()}; the controller picks one by the request's
 * `source` param. New sources (external catalog APIs) drop in by implementing
 * this interface — no changes to the dispatcher or controller required.
 */
interface BookTemplateProvider
{
    /** Stable identifier selected by the request's `source` param (e.g. 'site'). */
    public function key(): string;

    /**
     * Search this source for templates matching $query, returning the window of
     * up to $limit results starting at $offset, plus whether more remain (for
     * the infinite-scroll UI). Sources that can't page (e.g. the local
     * catalogue) return their bounded set on the first page and `hasMore=false`.
     */
    public function search(string $query, int $limit, int $offset = 0): BookTemplateResult;
}
