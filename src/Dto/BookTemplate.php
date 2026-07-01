<?php

namespace App\Dto;

/**
 * A bibliographic template used to pre-fill the "Add New Book" form. Carries
 * only the copyable metadata of a book — never its owner or lending state — so
 * it can be sourced from any book (including private libraries) without leaking
 * who holds it.
 */
final readonly class BookTemplate
{
    public function __construct(
        public string $title,
        public string $author,
        public ?string $isbn = null,
        public ?string $coverPath = null,
        public ?string $language = null,
        public ?string $description = null,
    ) {}

    /**
     * Collapse key: two templates are duplicates only when title, author,
     * language, ISBN *and* cover all match. Title/author are compared
     * case- and whitespace-insensitively; codes and URLs compare verbatim.
     */
    public function dedupeKey(): string
    {
        return implode('|', [
            mb_strtolower(trim($this->title)),
            mb_strtolower(trim($this->author)),
            $this->language ?? '',
            $this->isbn ?? '',
            $this->coverPath ?? '',
        ]);
    }
}
