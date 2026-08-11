<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Dto\BookTemplateResult;
use App\Language\LanguageCatalog;
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
 * Templates sourced from the Open Library Search API (https://openlibrary.org).
 *
 * Best-effort by design: any transport error, non-200 response or malformed
 * payload is logged and yields an empty list, so a slow or down upstream never
 * breaks the "Add New Book" search. Identification (the User-Agent header, from
 * OPENLIBRARY_USER_AGENT) earns the higher rate limit.
 *
 * The User-Agent is sent per request rather than configured on the scoped client,
 * because Open Library answers a *blank* one with 403 — and a 403 lands in the
 * best-effort catch, so a missing env value silently turned the whole source into
 * "no results" forever. The header can't be left to configuration for that reason:
 * DEFAULT_USER_AGENT keeps an unidentified install working (at the lower 1 req/s
 * limit), which is what a blank value is supposed to cost.
 *
 * Every search emits one record on the `book_template` channel (cache hit/miss,
 * upstream status, duration, raw vs. mapped counts) — the degrade-to-empty path is
 * silent by design, so without that feed an empty panel and a dead upstream look
 * identical from the outside.
 */
final class ExternalBookTemplateProvider implements BookTemplateProvider
{
    private const SEARCH_PATH = '/search.json';
    private const COVER_ID_URL = 'https://covers.openlibrary.org/b/id/%d-M.jpg';
    /** Cover-by-ISBN endpoint — used when a doc has no cover_i but does have an ISBN. */
    private const COVER_ISBN_URL = 'https://covers.openlibrary.org/b/isbn/%s-M.jpg';
    /** Only the fields we map — keeps the response small. */
    private const FIELDS = 'title,author_name,isbn,cover_i,language,first_sentence';
    /** Fallback identification when OPENLIBRARY_USER_AGENT is unset or blank. */
    private const DEFAULT_USER_AGENT = 'FolioShare/1.0 (book-sharing app; +https://github.com/pcRipper/bookshare)';
    /**
     * A doc's array fields are truncated to this many values before caching. Open
     * Library returns *every* edition ISBN for a work — often 100+ — and we map
     * exactly one, so storing the full list would pin kilobytes of dead weight per
     * doc for the whole (30-day) TTL. Three keeps a margin for mapping changes.
     */
    private const MAX_ARRAY_VALUES = 3;
    /** Marks the source as failing; see {@see inCooldown()}. */
    private const COOLDOWN_KEY = 'ol.cooldown';

    /**
     * Open Library returns MARC 21 language codes (3-letter); our catalogue uses
     * ISO 639-1 (2-letter). Map the common ones; anything unlisted resolves to
     * null (language is optional on a template). Includes both MARC bibliographic
     * and terminology variants where they differ (e.g. ger/deu).
     *
     * @var array<string, string>
     */
    private const MARC_TO_ISO = [
        'eng' => 'en', 'fre' => 'fr', 'fra' => 'fr', 'ger' => 'de', 'deu' => 'de',
        'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'dut' => 'nl', 'nld' => 'nl',
        'rus' => 'ru', 'jpn' => 'ja', 'chi' => 'zh', 'zho' => 'zh', 'ara' => 'ar',
        'swe' => 'sv', 'nor' => 'no', 'dan' => 'da', 'fin' => 'fi', 'pol' => 'pl',
        'cze' => 'cs', 'ces' => 'cs', 'gre' => 'el', 'ell' => 'el', 'heb' => 'he',
        'hin' => 'hi', 'kor' => 'ko', 'tur' => 'tr', 'ukr' => 'uk', 'vie' => 'vi',
        'tha' => 'th', 'ind' => 'id', 'lat' => 'la', 'hun' => 'hu', 'ron' => 'ro',
        'rum' => 'ro', 'cat' => 'ca', 'slo' => 'sk', 'slk' => 'sk', 'slv' => 'sl',
        'hrv' => 'hr', 'srp' => 'sr', 'bul' => 'bg', 'per' => 'fa', 'fas' => 'fa',
        'ice' => 'is', 'isl' => 'is', 'gle' => 'ga', 'est' => 'et', 'lav' => 'lv',
        'lit' => 'lt', 'ben' => 'bn', 'tam' => 'ta', 'tel' => 'te', 'urd' => 'ur',
    ];

    /**
     * @param int $cooldownTtl Seconds to stop calling Open Library after a failure
     *                         (0 disables the cooldown entirely — see {@see inCooldown()}).
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl,
        private readonly string $userAgent = '',
        private readonly int $cooldownTtl = 45,
    ) {}

    public function key(): string
    {
        return 'external';
    }

    public function search(string $query, int $limit, int $offset = 0): BookTemplateResult
    {
        if (trim($query) === '' || $limit < 1) {
            return new BookTemplateResult([], false);
        }

        // An ISBN-looking query searches the isbn index; anything else goes to the
        // general `q` index rather than `title`. `title` only matches the title
        // field, so an author name — which the UI invites, and which is how people
        // look for a series — returned either nothing or unrelated omnibus editions
        // that happened to carry the author's name in their title.
        $param = $this->looksLikeIsbn($query) ? 'isbn' : 'q';
        $normalized = $this->normalize($param, $query);
        if ($normalized === '') {
            return new BookTemplateResult([], false);
        }

        // The frontend advances by whole pages, so offset is a multiple of limit.
        $page = intdiv($offset, $limit) + 1;

        if ($this->inCooldown()) {
            $this->logger->info('Open Library template search skipped (cooldown): {query}', [
                'source' => $this->key(), 'index' => $param, 'query' => $normalized, 'page' => $page,
            ]);

            return new BookTemplateResult([], false);
        }

        $startedAt = hrtime(true);
        $cacheHit = true;   // cleared by the callback below if it actually runs
        $status = null;     // upstream HTTP status, when a call was made
        $payload = $this->fetchCached($param, $normalized, $limit, $page, $cacheHit, $status);

        $docs = \is_array($payload['docs'] ?? null) ? $payload['docs'] : [];
        $numFound = \is_int($payload['numFound'] ?? null) ? $payload['numFound'] : null;

        // Open Library reports an exact numFound even under ?fields=, so paging can
        // be driven by the real total. The old rule ("a full page of raw docs came
        // back") stalled the scroll on a page whose docs all failed mapping: it
        // promised more while handing back nothing. Keep it as the fallback for a
        // response that omits the count.
        $hasMore = $numFound !== null
            ? $page * $limit < $numFound
            : \count($docs) >= $limit;

        // Map on read (not cached) so transformation fixes apply without waiting
        // out the TTL; cap at the requested limit.
        $templates = [];
        foreach ($docs as $doc) {
            $template = \is_array($doc) ? $this->toTemplate($doc) : null;
            if ($template !== null) {
                $templates[] = $template;
            }
            if (\count($templates) >= $limit) {
                break;
            }
        }

        $this->logger->info('Open Library template search: {query}', [
            'source'     => $this->key(),
            'index'      => $param,
            'query'      => $normalized,
            'page'       => $page,
            'limit'      => $limit,
            'cacheHit'   => $cacheHit,
            'httpStatus' => $status,
            'numFound'   => $numFound,
            'rawCount'   => \count($docs),
            'mappedCount' => \count($templates),
            'hasMore'    => $hasMore,
            'durationMs' => $this->elapsedMs($startedAt),
        ]);

        return new BookTemplateResult($templates, $hasMore);
    }

    /**
     * Cached docs + total for a normalised query + page. Only successful, *non-empty*
     * fetches are stored: the callback throws on failure (nothing cached, degrade
     * to []) and clears $save when a page comes back empty, so "no matches" is
     * never what a later search reads back. An empty page is the same shape a
     * degraded upstream produces, and upstream indexes gain titles — neither is
     * worth pinning. Each page is its own entry, so scrolling back is a cache hit.
     *
     * @param bool     $cacheHit set to false when the callback runs (i.e. a miss)
     * @param int|null $status   the upstream HTTP status, on a miss
     *
     * @return array{docs: array<int, mixed>, numFound: int|null}
     */
    private function fetchCached(string $param, string $normalized, int $limit, int $page, bool &$cacheHit, ?int &$status): array
    {
        // sha1 keeps arbitrary query characters out of the (PSR-6 reserved-char) key.
        // The `ol2` prefix retires entries written before the payload carried
        // numFound — a stale 30-day entry of the old shape would just look empty.
        $key = sprintf('ol2.%s.%d.%d.%s', $param, $limit, $page, sha1($normalized));

        try {
            return $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($param, $normalized, $limit, $page, &$cacheHit, &$status): array {
                $cacheHit = false;
                $payload = $this->fetch($param, $normalized, $limit, $page, $status);
                if ($payload['docs'] === []) {
                    $save = false;

                    return $payload;
                }
                $item->expiresAfter($this->cacheTtl);

                return $payload;
            });
        } catch (HttpExceptionInterface $e) {
            // Transport/HTTP/decoding failure — degrade to no results, don't cache, don't break.
            $this->logger->warning('Open Library template search failed: {error}', [
                'source'     => $this->key(),
                'index'      => $param,
                'query'      => $normalized,
                'page'       => $page,
                'error'      => $e->getMessage(),
                'exceptionClass' => $e::class,
                'httpStatus' => $this->statusOf($e),
                'body'       => $this->bodySnippet($e),
            ]);
            $this->startCooldown();

            return ['docs' => [], 'numFound' => null];
        }
    }

    /**
     * One live call to the Open Library search index (one page). Docs are pruned to
     * the fields we map before they reach the cache — see MAX_ARRAY_VALUES.
     *
     * @return array{docs: array<int, array<string, mixed>>, numFound: int|null}
     *
     * @throws HttpExceptionInterface on transport, non-2xx or decode failure
     */
    private function fetch(string $param, string $query, int $limit, int $page, ?int &$status): array
    {
        $response = $this->client->request('GET', self::SEARCH_PATH, [
            'headers' => ['User-Agent' => trim($this->userAgent) ?: self::DEFAULT_USER_AGENT],
            'query' => [
                $param   => $query,
                'fields' => self::FIELDS,
                'limit'  => $limit,
                'page'   => $page,
            ],
        ]);

        $body = $response->toArray();
        // Read the status only after toArray() has settled the response, so a
        // non-2xx surfaces as the exception the caller expects rather than a status.
        $status = $response->getStatusCode();

        $docs = [];
        foreach (\is_array($body['docs'] ?? null) ? $body['docs'] : [] as $doc) {
            if (\is_array($doc)) {
                $docs[] = $this->pruneDoc($doc);
            }
        }

        return [
            'docs'     => $docs,
            'numFound' => isset($body['numFound']) && \is_numeric($body['numFound']) ? (int) $body['numFound'] : null,
        ];
    }

    /**
     * Strip a search doc down to the fields we map, truncating its list fields.
     * Everything the mapper could plausibly read is kept, so mapping fixes still
     * apply on read; what's dropped is the long tail we never look at.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function pruneDoc(array $doc): array
    {
        $pruned = [];
        foreach (['title', 'author_name', 'isbn', 'cover_i', 'language', 'first_sentence'] as $field) {
            if (!isset($doc[$field])) {
                continue;
            }
            $pruned[$field] = \is_array($doc[$field])
                ? \array_slice(array_values($doc[$field]), 0, self::MAX_ARRAY_VALUES)
                : $doc[$field];
        }

        return $pruned;
    }

    /** Canonical form of a query for a given index — drives cache hits and the request. */
    private function normalize(string $param, string $query): string
    {
        if ($param === 'isbn') {
            return preg_replace('/[\s-]/', '', $query) ?? '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
    }

    /**
     * True while the source is marked as failing. A failed call parks a short-lived
     * marker so continued typing doesn't keep dialling a dead or crawling upstream:
     * each attempt occupies a PHP-FPM worker for the whole timeout, and there are
     * only five. Deliberately distinct from the never-cache-an-empty-result rule —
     * this is failure-only, lasts seconds rather than the 30-day TTL, and is logged.
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

    /** A truncated slice of the failing response body — enough to tell 403-vs-503 apart. */
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

    /** Map one Open Library search doc to a template, or null if it has no title. */
    private function toTemplate(array $doc): ?BookTemplate
    {
        $title = isset($doc['title']) ? trim((string) $doc['title']) : '';
        if ($title === '') {
            return null;
        }

        $isbn = $this->first($doc['isbn'] ?? null);

        return new BookTemplate(
            title: $title,
            author: $this->first($doc['author_name'] ?? null) ?? 'Unknown',
            isbn: $isbn,
            coverPath: $this->coverUrl($doc['cover_i'] ?? null, $isbn),
            // Prefer the doc's own MARC language; fall back to guessing from the
            // title's script when it's missing or unmapped.
            language: $this->mapLanguage($doc['language'] ?? null) ?? LanguageGuesser::guess($title),
            // The Search API has no full description; its first sentence is the
            // best blurb we can supply without a second (per-result) Works call.
            description: $this->first($doc['first_sentence'] ?? null),
        );
    }

    /**
     * A doc's cover: its own cover id when it has one, else the cover-by-ISBN
     * endpoint. Plenty of docs carry an ISBN but no cover_i, and those rendered as
     * the placeholder icon even though a cover was one URL away.
     */
    private function coverUrl(mixed $coverId, ?string $isbn): ?string
    {
        if (\is_numeric($coverId)) {
            return sprintf(self::COVER_ID_URL, (int) $coverId);
        }

        // Only well-formed ISBNs — the endpoint 404s on anything else, which the
        // frontend's cover fallback would then have to absorb on every card.
        if ($isbn !== null && preg_match('/^(?:\d{13}|\d{9}[\dX])$/i', $isbn) === 1) {
            return sprintf(self::COVER_ISBN_URL, $isbn);
        }

        return null;
    }

    /** First non-empty string of an Open Library array field, or null. */
    private function first(mixed $values): ?string
    {
        if (!\is_array($values)) {
            return null;
        }
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** First catalogued language among the doc's MARC codes, or null. */
    private function mapLanguage(mixed $languages): ?string
    {
        if (!\is_array($languages)) {
            return null;
        }
        foreach ($languages as $marc) {
            $iso = self::MARC_TO_ISO[strtolower((string) $marc)] ?? null;
            if ($iso !== null && LanguageCatalog::isValid($iso)) {
                return $iso;
            }
        }

        return null;
    }

    /** Heuristic: 10 or 13 chars of digits (ISBN-10 may end in X) once separators are stripped. */
    private function looksLikeIsbn(string $query): bool
    {
        $stripped = preg_replace('/[\s-]/', '', $query) ?? '';

        return (bool) preg_match('/^(?:\d{13}|\d{9}[\dX])$/i', $stripped);
    }
}
