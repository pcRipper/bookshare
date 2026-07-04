<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Dto\BookTemplateResult;
use App\Entity\Book;
use App\Repository\BookRepository;

/**
 * Templates sourced from books already catalogued on the site. Matches the query
 * against title or ISBN across *every* library (private included) — only the
 * copyable metadata is returned, never the owner — and collapses exact duplicates
 * so the same book held by many readers appears once.
 *
 * This source is **single-page** (`hasMore=false`, no infinite scroll): dedup
 * runs after the fetch, so a SQL OFFSET would slice the result set *before* the
 * collapse and drift page to page. It returns one generous bounded page instead.
 */
final class SiteBookTemplateProvider implements BookTemplateProvider
{
    /** Rows to pull before de-duplicating, so a full page survives the collapse. */
    private const CANDIDATE_MULTIPLIER = 4;

    public function __construct(
        private readonly BookRepository $books,
    ) {}

    public function key(): string
    {
        return 'site';
    }

    public function search(string $query, int $limit, int $offset = 0): BookTemplateResult
    {
        // Single-page source: there is never a second page to scroll to.
        if (trim($query) === '' || $limit < 1 || $offset > 0) {
            return new BookTemplateResult([], false);
        }

        $candidates = $this->books->searchTemplates($query, $limit * self::CANDIDATE_MULTIPLIER);

        $items = $this->collapseDuplicates(
            array_map(
                fn (Book $b) => new BookTemplate(
                    $b->getTitle(),
                    $b->getAuthor(),
                    $b->getIsbn(),
                    $b->getCoverPath(),
                    $b->getLanguage(),
                    $b->getDescription(),
                ),
                $candidates,
            ),
            $limit,
        );

        return new BookTemplateResult($items, false);
    }

    /**
     * Keep the first occurrence of each distinct template (by {@see
     * BookTemplate::dedupeKey()}), preserving order, up to $limit.
     *
     * @param BookTemplate[] $templates
     * @return BookTemplate[]
     */
    private function collapseDuplicates(array $templates, int $limit): array
    {
        $seen = [];
        $unique = [];
        foreach ($templates as $template) {
            $key = $template->dedupeKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $template;
            if (\count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }
}
