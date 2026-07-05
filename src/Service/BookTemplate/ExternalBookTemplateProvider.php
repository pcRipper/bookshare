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
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Templates sourced from the Open Library Search API (https://openlibrary.org).
 *
 * Best-effort by design: any transport error, non-200 response or malformed
 * payload is logged and yields an empty list, so a slow or down upstream never
 * breaks the "Add New Book" search. Identification (the User-Agent header, set
 * in framework.yaml from OPENLIBRARY_USER_AGENT) earns the higher rate limit.
 */
final class ExternalBookTemplateProvider implements BookTemplateProvider
{
    private const SEARCH_PATH = '/search.json';
    private const COVER_URL = 'https://covers.openlibrary.org/b/id/%d-M.jpg';
    /** Only the fields we map — keeps the response small. */
    private const FIELDS = 'title,author_name,isbn,cover_i,language,first_sentence';
    /** Empty results self-heal quickly (a near-miss during indexing), unlike hits. */
    private const EMPTY_TTL = 600;

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

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl,
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

        // An ISBN-looking query searches the isbn index; anything else the title.
        // The query is normalised so equivalent inputs (case, spacing, ISBN
        // hyphenation) share both the upstream request and the cache entry.
        $param = $this->looksLikeIsbn($query) ? 'isbn' : 'title';
        $normalized = $this->normalize($param, $query);
        if ($normalized === '') {
            return new BookTemplateResult([], false);
        }

        // The frontend advances by whole pages, so offset is a multiple of limit.
        $page = intdiv($offset, $limit) + 1;
        $docs = $this->fetchDocsCached($param, $normalized, $limit, $page);

        // A full page of raw docs implies another page upstream — independent of
        // how many survive mapping (so paging never stalls on a thin page).
        $hasMore = \count($docs) >= $limit;

        // Map on read (not cached) so transformation fixes apply without waiting
        // out the TTL; cap at the requested limit.
        $templates = [];
        foreach ($docs as $doc) {
            $template = $this->toTemplate($doc);
            if ($template !== null) {
                $templates[] = $template;
            }
            if (\count($templates) >= $limit) {
                break;
            }
        }

        return new BookTemplateResult($templates, $hasMore);
    }

    /**
     * Cached raw docs for a normalised query + page. Only successful fetches are
     * stored (the callback throws on failure, so nothing is cached and we degrade
     * to []); empty results get a short TTL, hits the configured long one. Each
     * page is its own entry, so scrolling back over a page is a cache hit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchDocsCached(string $param, string $normalized, int $limit, int $page): array
    {
        // sha1 keeps arbitrary query characters out of the (PSR-6 reserved-char) key.
        $key = sprintf('ol.%s.%d.%d.%s', $param, $limit, $page, sha1($normalized));

        try {
            return $this->cache->get($key, function (ItemInterface $item) use ($param, $normalized, $limit, $page): array {
                $docs = $this->fetchDocs($param, $normalized, $limit, $page);
                $item->expiresAfter($docs === [] ? min(self::EMPTY_TTL, $this->cacheTtl) : $this->cacheTtl);

                return $docs;
            });
        } catch (HttpExceptionInterface $e) {
            // Transport/HTTP/decoding failure — degrade to no results, don't cache, don't break.
            $this->logger->warning('Open Library template search failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * One live call to the Open Library search index (one page).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HttpExceptionInterface on transport, non-2xx or decode failure
     */
    private function fetchDocs(string $param, string $query, int $limit, int $page): array
    {
        $response = $this->client->request('GET', self::SEARCH_PATH, [
            'query' => [
                $param   => $query,
                'fields' => self::FIELDS,
                'limit'  => $limit,
                'page'   => $page,
            ],
        ]);

        return $response->toArray()['docs'] ?? [];
    }

    /** Canonical form of a query for a given index — drives cache hits and the request. */
    private function normalize(string $param, string $query): string
    {
        if ($param === 'isbn') {
            return preg_replace('/[\s-]/', '', $query) ?? '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
    }

    /** Map one Open Library search doc to a template, or null if it has no title. */
    private function toTemplate(array $doc): ?BookTemplate
    {
        $title = isset($doc['title']) ? trim((string) $doc['title']) : '';
        if ($title === '') {
            return null;
        }

        return new BookTemplate(
            title: $title,
            author: $this->first($doc['author_name'] ?? null) ?? 'Unknown',
            isbn: $this->first($doc['isbn'] ?? null),
            coverPath: isset($doc['cover_i']) ? sprintf(self::COVER_URL, (int) $doc['cover_i']) : null,
            // Prefer the doc's own MARC language; fall back to guessing from the
            // title's script when it's missing or unmapped.
            language: $this->mapLanguage($doc['language'] ?? null) ?? LanguageGuesser::guess($title),
            // The Search API has no full description; its first sentence is the
            // best blurb we can supply without a second (per-result) Works call.
            description: $this->first($doc['first_sentence'] ?? null),
        );
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
