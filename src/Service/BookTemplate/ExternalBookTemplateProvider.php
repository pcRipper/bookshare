<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Language\LanguageCatalog;
use Psr\Log\LoggerInterface;
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
    private const FIELDS = 'title,author_name,isbn,cover_i,language';

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
    ) {}

    public function key(): string
    {
        return 'external';
    }

    public function search(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '' || $limit < 1) {
            return [];
        }

        try {
            // An ISBN-looking query searches the isbn index; anything else the title.
            $param = $this->looksLikeIsbn($query) ? 'isbn' : 'title';
            $response = $this->client->request('GET', self::SEARCH_PATH, [
                'query' => [
                    $param   => $query,
                    'fields' => self::FIELDS,
                    'limit'  => $limit,
                ],
            ]);

            $docs = $response->toArray()['docs'] ?? [];
        } catch (HttpExceptionInterface $e) {
            // Transport/HTTP/decoding failure — degrade to no results, don't break the search.
            $this->logger->warning('Open Library template search failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }

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

        return $templates;
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
            language: $this->mapLanguage($doc['language'] ?? null),
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
