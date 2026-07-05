<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Dto\BookTemplateResult;
use App\Language\LanguageGuesser;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Templates sourced from bookfinder.com.ua, a Ukrainian book-marketplace
 * aggregator — it indexes editions Open Library barely covers, so it fills the
 * gap for readers cataloguing the Ukrainian market.
 *
 * Best-effort by design (like {@see ExternalBookTemplateProvider}): any transport
 * error, non-200 response or malformed payload is logged and yields an empty list,
 * so a slow or down upstream never breaks the "Add New Book" search.
 *
 * The API is a single full-text `query` param returning a bare array sorted by
 * relevance descending; it supplies neither ISBN nor language, and the *same*
 * book recurs across shops with different cover URLs — so results are collapsed
 * on title+author alone (not {@see BookTemplate::dedupeKey()}, which keys on the
 * cover too and would never merge those near-duplicates).
 */
final class BookFinderBookTemplateProvider implements BookTemplateProvider
{
    private const SEARCH_PATH = '/api/books';
    /** Empty results self-heal quickly (a near-miss during indexing), unlike hits. */
    private const EMPTY_TTL = 600;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl,
    ) {}

    public function key(): string
    {
        return 'bookfinder';
    }

    public function search(string $query, int $limit, int $offset = 0): BookTemplateResult
    {
        if (trim($query) === '' || $limit < 1) {
            return new BookTemplateResult([], false);
        }

        // The API has one full-text index, so no ISBN/title split. Normalise so
        // equivalent inputs (case, spacing) share both the upstream request and
        // the cache entry.
        $normalized = $this->normalize($query);
        if ($normalized === '') {
            return new BookTemplateResult([], false);
        }

        // The whole relevance-sorted set arrives in one (cached) call. Map on read
        // (not cached) so transformation fixes apply without waiting out the TTL.
        $items = $this->fetchItemsCached($normalized);
        $templates = [];
        foreach ($items as $item) {
            $template = $this->toTemplate($item);
            if ($template !== null) {
                $templates[] = $template;
            }
        }

        // Collapse shop duplicates over the *entire* set once (deterministic), then
        // window it — slicing stays stable across pages because the dedup ran over
        // everything, not just this page. Every page after the first is a cache hit.
        $deduped = $this->dedupe($templates);
        $window = \array_slice($deduped, $offset, $limit);

        return new BookTemplateResult($window, $offset + $limit < \count($deduped));
    }

    /**
     * Cached raw items for a normalised query. Only successful fetches are stored
     * (the callback throws on failure, so nothing is cached and we degrade to []);
     * empty results get a short TTL, hits the configured long one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchItemsCached(string $normalized): array
    {
        // sha1 keeps arbitrary query characters out of the (PSR-6 reserved-char) key.
        $key = sprintf('bf.%s', sha1($normalized));

        try {
            return $this->cache->get($key, function (ItemInterface $item) use ($normalized): array {
                $items = $this->fetchItems($normalized);
                $item->expiresAfter($items === [] ? min(self::EMPTY_TTL, $this->cacheTtl) : $this->cacheTtl);

                return $items;
            });
        } catch (HttpExceptionInterface $e) {
            // Transport/HTTP/decoding failure — degrade to no results, don't cache, don't break.
            $this->logger->warning('BookFinder template search failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * One live call to the BookFinder search endpoint. The response is a bare
     * JSON array (no envelope); the API ignores any limit param, returning the
     * whole relevance-sorted set, so we slice locally after de-duplicating.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HttpExceptionInterface on transport, non-2xx or decode failure
     */
    private function fetchItems(string $query): array
    {
        $response = $this->client->request('GET', self::SEARCH_PATH, [
            'query' => ['query' => $query],
        ]);

        return $response->toArray();
    }

    /** Canonical form of a query — drives cache hits and the request. */
    private function normalize(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
    }

    /** Map one BookFinder listing to a template, or null if it has no title. */
    private function toTemplate(array $item): ?BookTemplate
    {
        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        if ($title === '') {
            return null;
        }

        $cover = isset($item['imageUrl']) ? trim((string) $item['imageUrl']) : '';
        $description = isset($item['description']) ? trim((string) $item['description']) : '';

        return new BookTemplate(
            title: $title,
            author: $this->firstAuthor($item['authors'] ?? null) ?? 'Unknown',
            // The API supplies no ISBN and no language for its listings — so the
            // language is guessed from the title's script (Ukrainian by default,
            // this being the Ukrainian market it indexes).
            isbn: null,
            coverPath: $cover !== '' ? $cover : null,
            language: LanguageGuesser::guess($title),
            description: $description !== '' ? $description : null,
        );
    }

    /** First non-empty `fullName` among a listing's authors, or null. */
    private function firstAuthor(mixed $authors): ?string
    {
        if (!\is_array($authors)) {
            return null;
        }
        foreach ($authors as $author) {
            $name = \is_array($author) ? trim((string) ($author['fullName'] ?? '')) : '';
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * Keep the first occurrence (highest relevance) of each distinct book, keyed
     * on title+author only — case- and whitespace-insensitively. Covers and
     * descriptions differ per shop and there's no ISBN/language to disambiguate,
     * so the shared {@see BookTemplate::dedupeKey()} would leave near-duplicates
     * uncollapsed. No cap here: the caller windows the deduped set for paging.
     *
     * @param BookTemplate[] $templates
     * @return BookTemplate[]
     */
    private function dedupe(array $templates): array
    {
        $seen = [];
        $unique = [];
        foreach ($templates as $template) {
            $key = mb_strtolower(trim($template->title)) . '|' . mb_strtolower(trim($template->author));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $template;
        }

        return $unique;
    }
}
