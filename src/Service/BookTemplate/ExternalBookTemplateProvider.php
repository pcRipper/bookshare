<?php

namespace App\Service\BookTemplate;

/**
 * Templates sourced from external catalog APIs (e.g. Google Books, Open Library).
 *
 * Placeholder: the integration is intentionally deferred, so this always returns
 * an empty result. It exists now so the "external sources" toggle in the UI has a
 * live strategy to call; wiring a real API later means only filling in {@see
 * search()} — the dispatcher, controller and frontend stay untouched.
 */
final class ExternalBookTemplateProvider implements BookTemplateProvider
{
    public function key(): string
    {
        return 'external';
    }

    public function search(string $query, int $limit): array
    {
        return [];
    }
}
