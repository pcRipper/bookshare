<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Dto\BookTemplateResult;
use App\Language\LanguageGuesser;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
// The narrower interface: the subset of client exceptions that carry the response
// (4xx/5xx/redirect), so a failure log can name the status the upstream returned.
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as ResponseAwareExceptionInterface;
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
 *
 * Every search emits one record on the `book_template` channel (cache hit/miss,
 * upstream status, duration, raw vs. deduped counts) — the degrade-to-empty path is
 * silent by design, so without that feed an empty panel and a dead upstream look
 * identical from the outside. This source needs it more than most: a cold query
 * takes the upstream ~7s to compute (it caches internally afterwards), which is
 * long enough to look like a hang.
 */
final class BookFinderBookTemplateProvider implements BookTemplateProvider
{
    private const SEARCH_PATH = '/api/books';
    /** Authors kept per listing before caching; we map the first. */
    private const MAX_AUTHORS = 3;
    /** Marks the source as failing; see {@see inCooldown()}. */
    private const COOLDOWN_KEY = 'bf.cooldown';

    /**
     * @param int $cooldownTtl Seconds to stop calling BookFinder after a failure
     *                         (0 disables the cooldown entirely — see {@see inCooldown()}).
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl,
        private readonly int $cooldownTtl = 45,
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

        if ($this->inCooldown()) {
            $this->logger->info('BookFinder template search skipped (cooldown): {query}', [
                'source' => $this->key(), 'query' => $normalized, 'offset' => $offset,
            ]);

            return new BookTemplateResult([], false);
        }

        // The whole relevance-sorted set arrives in one (cached) call. Map on read
        // (not cached) so transformation fixes apply without waiting out the TTL.
        $startedAt = hrtime(true);
        $cacheHit = true;   // cleared by the callback below if it actually runs
        $status = null;     // upstream HTTP status, when a call was made
        $items = $this->fetchItemsCached($normalized, $cacheHit, $status);
        $templates = [];
        foreach ($items as $item) {
            $template = \is_array($item) ? $this->toTemplate($item) : null;
            if ($template !== null) {
                $templates[] = $template;
            }
        }

        // Collapse shop duplicates over the *entire* set once (deterministic), then
        // window it — slicing stays stable across pages because the dedup ran over
        // everything, not just this page. Every page after the first is a cache hit.
        $deduped = $this->dedupe($templates);
        $window = \array_slice($deduped, $offset, $limit);
        $hasMore = $offset + $limit < \count($deduped);

        $this->logger->info('BookFinder template search: {query}', [
            'source'       => $this->key(),
            'query'        => $normalized,
            'offset'       => $offset,
            'limit'        => $limit,
            'cacheHit'     => $cacheHit,
            'httpStatus'   => $status,
            'rawCount'     => \count($items),
            'mappedCount'  => \count($templates),
            'dedupedCount' => \count($deduped),
            'windowCount'  => \count($window),
            'hasMore'      => $hasMore,
            'durationMs'   => $this->elapsedMs($startedAt),
        ]);

        return new BookTemplateResult($window, $hasMore);
    }

    /**
     * Cached raw items for a normalised query. Only successful, *non-empty* fetches
     * are stored: the callback throws on failure (nothing cached, degrade to []) and
     * clears $save when the search comes back empty, so "no matches" is never what a
     * later search reads back. Since one entry backs every page of this query, a
     * cached empty would also freeze the whole infinite scroll, not just one page.
     *
     * @param bool     $cacheHit set to false when the callback runs (i.e. a miss)
     * @param int|null $status   the upstream HTTP status, on a miss
     *
     * @return array<int, mixed>
     */
    private function fetchItemsCached(string $normalized, bool &$cacheHit, ?int &$status): array
    {
        // sha1 keeps arbitrary query characters out of the (PSR-6 reserved-char) key.
        // The `bf2` prefix retires entries written before listings were pruned — a
        // stale 30-day entry of the old shape still maps, but keeping both shapes
        // readable isn't worth the branch when the entry expires anyway.
        $key = sprintf('bf2.%s', sha1($normalized));

        try {
            return $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($normalized, &$cacheHit, &$status): array {
                $cacheHit = false;
                $items = $this->fetchItems($normalized, $status);
                if ($items === []) {
                    $save = false;

                    return [];
                }
                $item->expiresAfter($this->cacheTtl);

                return $items;
            });
        } catch (HttpExceptionInterface $e) {
            // Transport/HTTP/decoding failure — degrade to no results, don't cache, don't break.
            $this->logger->warning('BookFinder template search failed: {error}', [
                'source'         => $this->key(),
                'query'          => $normalized,
                'error'          => $e->getMessage(),
                'exceptionClass' => $e::class,
                'httpStatus'     => $this->statusOf($e),
                'body'           => $this->bodySnippet($e),
            ]);
            $this->startCooldown();

            return [];
        }
    }

    /**
     * One live call to the BookFinder search endpoint. The response is a bare
     * JSON array (no envelope); the API ignores any limit param, returning the
     * whole relevance-sorted set, so we slice locally after de-duplicating.
     *
     * Listings are pruned to the fields we map before they reach the cache: the raw
     * response for a common query is ~127 KB of shop offers (price, currency, stock,
     * per-shop URL, format flags, relevance score) wrapped around the handful of
     * bibliographic fields we copy, and one entry backs every page for 30 days.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HttpExceptionInterface on transport, non-2xx or decode failure
     */
    private function fetchItems(string $query, ?int &$status): array
    {
        $response = $this->client->request('GET', self::SEARCH_PATH, [
            'query' => ['query' => $query],
        ]);

        $body = $response->toArray();
        // Read the status only after toArray() has settled the response, so a
        // non-2xx surfaces as the exception the caller expects rather than a status.
        $status = $response->getStatusCode();

        $items = [];
        foreach ($body as $item) {
            if (\is_array($item)) {
                $items[] = $this->pruneItem($item);
            }
        }

        return $items;
    }

    /**
     * Strip a listing to the fields we map. `year` and `publishing` ride along
     * unused: they're one scalar each and the obvious next things a template could
     * carry, so keeping them preserves the map-on-read property for a change that
     * would otherwise have to wait out the TTL.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function pruneItem(array $item): array
    {
        $pruned = [];
        foreach (['title', 'description', 'imageUrl', 'year', 'publishing'] as $field) {
            if (isset($item[$field]) && !\is_array($item[$field])) {
                $pruned[$field] = $item[$field];
            }
        }

        if (\is_array($item['authors'] ?? null)) {
            $authors = [];
            foreach (\array_slice(array_values($item['authors']), 0, self::MAX_AUTHORS) as $author) {
                if (\is_array($author) && isset($author['fullName'])) {
                    $authors[] = ['fullName' => $author['fullName']];
                }
            }
            $pruned['authors'] = $authors;
        }

        return $pruned;
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
     * Keep one entry per distinct book, keyed on title+author only — case- and
     * whitespace-insensitively. Covers and descriptions differ per shop and there's
     * no ISBN/language to disambiguate, so the shared {@see BookTemplate::dedupeKey()}
     * would leave near-duplicates uncollapsed. No cap here: the caller windows the
     * deduped set for paging.
     *
     * The highest-relevance listing keeps its position and its own values, but any
     * field it lacks is filled from a lower-relevance twin: shops list the same book
     * with different coverage, so the top hit is frequently the one missing the cover
     * or the blurb that the listing right behind it has.
     *
     * @param BookTemplate[] $templates
     * @return BookTemplate[]
     */
    private function dedupe(array $templates): array
    {
        /** @var array<string, BookTemplate> $byKey */
        $byKey = [];
        foreach ($templates as $template) {
            $key = mb_strtolower(trim($template->title)) . '|' . mb_strtolower(trim($template->author));
            $byKey[$key] = isset($byKey[$key]) ? $this->merge($byKey[$key], $template) : $template;
        }

        return array_values($byKey);
    }

    /** $winner with its null fields filled from $other; $winner itself when nothing is missing. */
    private function merge(BookTemplate $winner, BookTemplate $other): BookTemplate
    {
        if ($winner->coverPath !== null && $winner->description !== null) {
            return $winner;
        }

        return new BookTemplate(
            title: $winner->title,
            author: $winner->author,
            isbn: $winner->isbn,
            coverPath: $winner->coverPath ?? $other->coverPath,
            language: $winner->language,
            description: $winner->description ?? $other->description,
        );
    }

    /**
     * True while the source is marked as failing. A failed call parks a short-lived
     * marker so continued typing doesn't keep dialling a dead or crawling upstream:
     * each attempt occupies a PHP-FPM worker for the whole timeout — up to 12s here,
     * and there are only five workers. Deliberately distinct from the
     * never-cache-an-empty-result rule — this is failure-only, lasts seconds rather
     * than the 30-day TTL, and is logged.
     */
    private function inCooldown(): bool
    {
        if ($this->cooldownTtl < 1) {
            return false;
        }

        // The callback runs only when the marker is absent; clearing $save keeps
        // the mere check from creating the entry it's testing for.
        return (bool) $this->cache->get(self::COOLDOWN_KEY, static function (ItemInterface $item, bool &$save): bool {
            $save = false;

            return false;
        });
    }

    private function startCooldown(): void
    {
        if ($this->cooldownTtl < 1) {
            return;
        }

        $this->cache->delete(self::COOLDOWN_KEY);
        $this->cache->get(self::COOLDOWN_KEY, function (ItemInterface $item): bool {
            $item->expiresAfter($this->cooldownTtl);

            return true;
        });
    }

    /** Whole milliseconds since an hrtime(true) reading. */
    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /** The upstream status behind a client exception, when it carries a response. */
    private function statusOf(HttpExceptionInterface $e): ?int
    {
        return $e instanceof ResponseAwareExceptionInterface ? $e->getResponse()->getStatusCode() : null;
    }

    /** A truncated slice of the failing response body — enough to tell 404-vs-503 apart. */
    private function bodySnippet(HttpExceptionInterface $e): ?string
    {
        if (!$e instanceof ResponseAwareExceptionInterface) {
            return null;
        }

        try {
            return mb_substr($e->getResponse()->getContent(false), 0, 200);
        } catch (HttpExceptionInterface) {
            return null;
        }
    }
}
