<?php

namespace App\Tests\Service\BookTemplate;

use App\Service\BookTemplate\ExternalBookTemplateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExternalBookTemplateProviderTest extends TestCase
{
    private function json(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /** A payload of $n minimal docs — useful for exercising the full-page hasMore rule. */
    private function docs(int $n): MockResponse
    {
        $docs = [];
        for ($i = 0; $i < $n; $i++) {
            $docs[] = ['title' => 'Book ' . $i];
        }

        return $this->json(['docs' => $docs]);
    }

    /**
     * The cooldown is off by default here so each test exercises one behaviour: with
     * it on, any test whose first response fails would then be asserting the cooldown
     * rather than the caching rule it was written for. The tests that *are* about the
     * cooldown pass a TTL explicitly.
     */
    private function provider(MockHttpClient $client, string $userAgent = '', int $cooldownTtl = 0): ExternalBookTemplateProvider
    {
        // Fresh in-memory cache per provider so tests are isolated.
        return new ExternalBookTemplateProvider($client, new NullLogger(), new ArrayAdapter(), 604800, $userAgent, $cooldownTtl);
    }

    /** Captures the User-Agent the provider actually put on the wire. */
    private function capturedUserAgent(string $configured): string
    {
        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            foreach ($options['headers'] ?? [] as $header) {
                if (stripos($header, 'user-agent:') === 0) {
                    $sent[] = trim(substr($header, \strlen('user-agent:')));
                }
            }

            return $this->json(['docs' => []]);
        });

        $this->provider($client, $configured)->search('dune', 12);

        return $sent[0] ?? '';
    }

    public function testConfiguredUserAgentIsSent(): void
    {
        self::assertSame('FolioShare (me@example.com)', $this->capturedUserAgent('FolioShare (me@example.com)'));
    }

    /**
     * Open Library answers a blank User-Agent with 403, and the best-effort catch
     * turns that into an empty result — so an unset env var silently disabled the
     * whole source. A blank value must cost the higher rate limit, nothing more.
     */
    public function testBlankUserAgentFallsBackToADefaultRatherThanSendingNothing(): void
    {
        self::assertNotSame('', $this->capturedUserAgent(''));
        self::assertNotSame('', $this->capturedUserAgent('   '));
    }

    public function testKeyIsExternal(): void
    {
        self::assertSame('external', $this->provider(new MockHttpClient())->key());
    }

    public function testMapsSearchDocsToTemplates(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            [
                'title'       => 'Dune',
                'author_name' => ['Frank Herbert', 'Someone Else'],
                'isbn'        => ['9780441013593', '0441013597'],
                'cover_i'     => 12345,
                'language'    => ['eng'],
                'first_sentence' => ['A beginning is a delicate time.', 'Second sentence.'],
            ],
        ]]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertCount(1, $result->items);
        self::assertSame('Dune', $result->items[0]->title);
        self::assertSame('Frank Herbert', $result->items[0]->author);          // first author
        self::assertSame('9780441013593', $result->items[0]->isbn);            // first isbn
        self::assertSame('https://covers.openlibrary.org/b/id/12345-M.jpg', $result->items[0]->coverPath);
        self::assertSame('en', $result->items[0]->language);                    // MARC eng -> ISO en
        self::assertSame('A beginning is a delicate time.', $result->items[0]->description); // first_sentence[0]
    }

    public function testUnmappedLanguageAndMissingCoverBecomeNull(): void
    {
        // A Latin title yields no language guess, so an unmapped MARC code stays null.
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Untitled Tongue', 'author_name' => ['A'], 'language' => ['xyz']],
        ]]));

        $result = $this->provider($client)->search('untitled', 12);

        self::assertNull($result->items[0]->language);
        self::assertNull($result->items[0]->coverPath);
        self::assertNull($result->items[0]->isbn);
    }

    public function testLanguageIsGuessedFromTheTitleWhenMarcIsMissing(): void
    {
        // No MARC language on the doc — fall back to guessing from the title's
        // script (Cyrillic here, Ukrainian by default).
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Кобзар', 'author_name' => ['Тарас Шевченко']],
        ]]));

        $result = $this->provider($client)->search('кобзар', 12);

        self::assertSame('uk', $result->items[0]->language);
    }

    public function testMarcLanguageWinsOverTheTitleGuess(): void
    {
        // A doc that carries a mappable MARC code keeps it — the guess is a fallback.
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Кобзар', 'author_name' => ['A'], 'language' => ['eng']],
        ]]));

        $result = $this->provider($client)->search('кобзар', 12);

        self::assertSame('en', $result->items[0]->language);
    }

    public function testDocsWithoutATitleAreSkipped(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['author_name' => ['No Title']],
            ['title' => 'Real Book', 'author_name' => ['B']],
        ]]));

        $result = $this->provider($client)->search('book', 12);

        self::assertCount(1, $result->items);
        self::assertSame('Real Book', $result->items[0]->title);
    }

    public function testResultsAreCappedAtLimit(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]]));

        self::assertCount(2, $this->provider($client)->search('x', 2)->items);
    }

    /** The fallback rule, for a response that carries no numFound. */
    public function testHasMoreFallsBackToAFullPageOfRawDocs(): void
    {
        // A full limit of raw docs implies another page exists upstream.
        self::assertTrue($this->provider(new MockHttpClient($this->docs(12)))->search('x', 12)->hasMore);
        // A short page is the last one.
        self::assertFalse($this->provider(new MockHttpClient($this->docs(5)))->search('x', 12)->hasMore);
    }

    /**
     * Open Library reports an exact numFound even under ?fields=, so paging follows
     * the real total rather than guessing from the page size.
     */
    public function testHasMoreComesFromNumFoundWhenPresent(): void
    {
        $page1 = $this->json(['numFound' => 30, 'docs' => [['title' => 'One']]]);
        self::assertTrue($this->provider(new MockHttpClient($page1))->search('x', 12)->hasMore);

        // A full page that is nonetheless the last one: numFound says so, the
        // page-size heuristic would have promised another.
        $last = $this->json(['numFound' => 12, 'docs' => array_fill(0, 12, ['title' => 'Book'])]);
        self::assertFalse($this->provider(new MockHttpClient($last))->search('x', 12)->hasMore);
    }

    /**
     * The old rule promised another page whenever a full limit of raw docs came
     * back — even when every one of them failed to map, which handed the scroll an
     * empty page and a standing promise of more.
     */
    public function testAFullPageOfUnmappableDocsDoesNotPromiseMore(): void
    {
        $client = new MockHttpClient($this->json([
            'numFound' => 12,
            'docs'     => array_fill(0, 12, ['author_name' => ['No Title']]),
        ]));

        $result = $this->provider($client)->search('x', 12);

        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
    }

    public function testOffsetMapsToThePageParam(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return $this->json(['docs' => []]);
        });

        // offset 24 with a page size of 12 is the third page.
        $this->provider($client)->search('dune', 12, 24);

        self::assertStringContainsString('page=3', $urls[0]);
    }

    public function testIsbnLikeQueryHitsTheIsbnIndex(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return $this->json(['docs' => []]);
        });

        $this->provider($client)->search('978-0-441-01359-3', 12);

        self::assertStringContainsString('isbn=', $urls[0]);
        self::assertStringNotContainsString('title=', $urls[0]);
    }

    /**
     * Free text goes to the general `q` index, not `title`. The title index only
     * matches the title field, so searching an author — which the UI invites —
     * returned nothing, or unrelated omnibus editions with the name in their title.
     */
    public function testFreeTextQueryHitsTheGeneralIndex(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return $this->json(['docs' => []]);
        });

        $this->provider($client)->search('sapkowski', 12);

        self::assertStringContainsString('q=sapkowski', $urls[0]);
        self::assertStringNotContainsString('title=', $urls[0]);
        self::assertStringNotContainsString('isbn=', $urls[0]);
    }

    public function testUpstreamFailureDegradesToEmptyList(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 503]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
    }

    public function testBlankQueryMakesNoRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('No HTTP request should be made for a blank query.');
        });

        self::assertSame([], $this->provider($client)->search('   ', 12)->items);
    }

    public function testRepeatSearchIsServedFromCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->json(['docs' => [['title' => 'Dune', 'author_name' => ['Frank Herbert']]]]);
        });
        $provider = $this->provider($client);

        $first = $provider->search('dune', 12);
        $second = $provider->search('dune', 12);

        self::assertSame(1, $calls, 'The second identical search should hit the cache, not the API.');
        self::assertEquals($first, $second);
    }

    /**
     * An empty page is the same shape a degraded upstream produces, and the index
     * gains titles over time — caching it would serve "no matches" for a query that
     * has since started matching.
     */
    public function testEmptyResultsAreNotCached(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            // Empty first, then a hit — the second search must reach the API.
            return $calls === 1
                ? $this->json(['docs' => []])
                : $this->json(['docs' => [['title' => 'Dune', 'author_name' => ['Frank Herbert']]]]);
        });
        $provider = $this->provider($client);

        self::assertSame([], $provider->search('dune', 12)->items);
        $second = $provider->search('dune', 12);

        self::assertSame(2, $calls, 'An empty page must not be cached.');
        self::assertCount(1, $second->items, 'The retry should see the now-populated upstream.');
    }

    public function testDifferentPagesAreFetchedAndCachedSeparately(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->docs(12);
        });
        $provider = $this->provider($client);

        $provider->search('dune', 12, 0);   // page 1
        $provider->search('dune', 12, 12);  // page 2 — a distinct upstream call
        $provider->search('dune', 12, 12);  // page 2 again — served from cache

        self::assertSame(2, $calls, 'Each page is its own cache entry; re-reading a page is a hit.');
    }

    public function testEquivalentQueriesShareOneCacheEntry(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->json(['docs' => [['title' => 'Dune']]]);
        });
        $provider = $this->provider($client);

        // Case + surrounding/inner whitespace differences normalise to the same key.
        $provider->search('Dune', 12);
        $provider->search('  dune ', 12);

        self::assertSame(1, $calls);
    }

    public function testIsbnHyphenationDoesNotSplitTheCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->json(['docs' => [['title' => 'Dune']]]);
        });
        $provider = $this->provider($client);

        $provider->search('978-0-441-01359-3', 12);
        $provider->search('9780441013593', 12);

        self::assertSame(1, $calls);
    }

    public function testUpstreamFailureIsNotCached(): void
    {
        // First request fails, second succeeds — same query. A cached error would
        // make the second call return [] too; it must instead refetch. (Cooldown off:
        // it would legitimately suppress that refetch — see the cooldown tests.)
        $client = new MockHttpClient([
            new MockResponse('down', ['http_code' => 503]),
            $this->json(['docs' => [['title' => 'Dune']]]),
        ]);
        $provider = $this->provider($client);

        self::assertSame([], $provider->search('dune', 12)->items);
        $second = $provider->search('dune', 12);
        self::assertCount(1, $second->items);
        self::assertSame('Dune', $second->items[0]->title);
    }

    /**
     * After a failure the source is parked for a short while: every attempt holds a
     * PHP-FPM worker for the whole timeout, so continued typing against a dead
     * upstream must not keep dialling it.
     */
    public function testAFailureParksTheSourceForTheCooldown(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('down', ['http_code' => 503]);
        });
        $provider = $this->provider($client, '', 45);

        self::assertSame([], $provider->search('dune', 12)->items);
        // A different query too — the failure modes here (upstream down, timeouts
        // under load, a 403 on identification) are properties of the source, not of
        // one query.
        self::assertSame([], $provider->search('other', 12)->items);

        self::assertSame(1, $calls, 'Only the first search should reach the upstream.');
    }

    public function testCooldownIsSkippedEntirelyWhenDisabled(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('down', ['http_code' => 503]);
        });
        $provider = $this->provider($client, '', 0);

        $provider->search('dune', 12);
        $provider->search('other', 12);

        self::assertSame(2, $calls);
    }

    /** A doc with no cover_i still gets a cover, via the cover-by-ISBN endpoint. */
    public function testCoverFallsBackToTheIsbnEndpoint(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Dune', 'author_name' => ['Frank Herbert'], 'isbn' => ['9780441013593']],
        ]]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertSame('https://covers.openlibrary.org/b/isbn/9780441013593-M.jpg', $result->items[0]->coverPath);
    }

    /** A malformed ISBN would 404 that endpoint, so it earns no cover URL at all. */
    public function testMalformedIsbnEarnsNoCoverUrl(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Dune', 'author_name' => ['A'], 'isbn' => ['not-an-isbn']],
        ]]));

        self::assertNull($this->provider($client)->search('dune', 12)->items[0]->coverPath);
    }

    public function testCoverIdWinsOverTheIsbnFallback(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Dune', 'author_name' => ['A'], 'isbn' => ['9780441013593'], 'cover_i' => 42],
        ]]));

        self::assertSame(
            'https://covers.openlibrary.org/b/id/42-M.jpg',
            $this->provider($client)->search('dune', 12)->items[0]->coverPath,
        );
    }

    /**
     * Docs are pruned before caching (a work can carry 100+ edition ISBNs and we map
     * one), so the cached read must still produce the same template as the live one.
     */
    public function testPrunedCacheEntryStillMapsEveryField(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            [
                'title'          => 'Dune',
                'author_name'    => ['Frank Herbert', 'B', 'C', 'D', 'E'],
                'isbn'           => array_fill(0, 40, '9780441013593'),
                'cover_i'        => 12345,
                'language'       => ['eng', 'fre'],
                'first_sentence' => ['A beginning is a delicate time.'],
                'ebook_access'   => 'borrowable', // a field we never map — dropped
            ],
        ]]));
        $provider = $this->provider($client);

        $live = $provider->search('dune', 12);
        $cached = $provider->search('dune', 12);

        self::assertEquals($live->items, $cached->items);
        self::assertSame('Frank Herbert', $cached->items[0]->author);
        self::assertSame('9780441013593', $cached->items[0]->isbn);
        self::assertSame('en', $cached->items[0]->language);
        self::assertSame('A beginning is a delicate time.', $cached->items[0]->description);
    }

    /**
     * A shape surprise must degrade, not 500: before the guards, a non-array `docs`
     * or a scalar among the docs reached the mapper's array parameter as a TypeError.
     */
    public function testMalformedPayloadShapesDegradeToEmpty(): void
    {
        self::assertSame([], $this->provider(new MockHttpClient($this->json(['docs' => 'nope'])))->search('x', 12)->items);
        self::assertSame([], $this->provider(new MockHttpClient($this->json(['docs' => ['a string', 42]])))->search('x', 12)->items);
        self::assertSame([], $this->provider(new MockHttpClient($this->json(['unexpected' => true])))->search('x', 12)->items);
    }
}
