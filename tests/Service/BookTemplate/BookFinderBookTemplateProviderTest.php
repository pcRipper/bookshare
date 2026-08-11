<?php

namespace App\Tests\Service\BookTemplate;

use App\Service\BookTemplate\BookFinderBookTemplateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BookFinderBookTemplateProviderTest extends TestCase
{
    /** The API returns a bare JSON array of listings (no envelope). */
    private function json(array $items): MockResponse
    {
        return new MockResponse(
            json_encode($items),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * The cooldown is off by default here so each test exercises one behaviour: with
     * it on, any test whose first response fails would then be asserting the cooldown
     * rather than the caching rule it was written for. The tests that *are* about the
     * cooldown pass a TTL explicitly.
     */
    private function provider(MockHttpClient $client, int $cooldownTtl = 0): BookFinderBookTemplateProvider
    {
        // Fresh in-memory cache per provider so tests are isolated.
        return new BookFinderBookTemplateProvider($client, new NullLogger(), new ArrayAdapter(), 604800, $cooldownTtl);
    }

    public function testKeyIsBookfinder(): void
    {
        self::assertSame('bookfinder', $this->provider(new MockHttpClient())->key());
    }

    public function testMapsListingToTemplate(): void
    {
        $client = new MockHttpClient($this->json([
            [
                'title'       => 'Dune',
                'authors'     => [['fullName' => 'Frank Herbert'], ['fullName' => 'Someone Else']],
                'description' => 'A desert epic.',
                'imageUrl'    => 'https://shop.example/dune.jpg',
            ],
        ]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertCount(1, $result->items);
        self::assertFalse($result->hasMore);
        self::assertSame('Dune', $result->items[0]->title);
        self::assertSame('Frank Herbert', $result->items[0]->author);           // first author
        self::assertSame('A desert epic.', $result->items[0]->description);
        self::assertSame('https://shop.example/dune.jpg', $result->items[0]->coverPath);
        // The API supplies no ISBN; a Latin title yields no language guess either.
        self::assertNull($result->items[0]->isbn);
        self::assertNull($result->items[0]->language);
    }

    public function testLanguageIsGuessedFromTheTitleScript(): void
    {
        // The API never supplies a language, so it's inferred from the title —
        // Ukrainian by default for Cyrillic (the market this source indexes).
        $client = new MockHttpClient($this->json([
            ['title' => 'Кобзар', 'authors' => [['fullName' => 'Тарас Шевченко']]],
        ]));

        $result = $this->provider($client)->search('кобзар', 12);

        self::assertSame('uk', $result->items[0]->language);
    }

    public function testMissingAuthorBecomesUnknownAndEmptyFieldsBecomeNull(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'No Author', 'authors' => [], 'description' => '', 'imageUrl' => ''],
        ]));

        $result = $this->provider($client)->search('x', 12);

        self::assertSame('Unknown', $result->items[0]->author);
        self::assertNull($result->items[0]->coverPath);
        self::assertNull($result->items[0]->description);
    }

    public function testListingsWithoutATitleAreSkipped(): void
    {
        $client = new MockHttpClient($this->json([
            ['authors' => [['fullName' => 'No Title']]],
            ['title' => 'Real Book', 'authors' => [['fullName' => 'B']]],
        ]));

        $result = $this->provider($client)->search('book', 12);

        self::assertCount(1, $result->items);
        self::assertSame('Real Book', $result->items[0]->title);
    }

    public function testShopDuplicatesCollapseOnTitleAndAuthor(): void
    {
        // The same book from two shops: different covers/descriptions, so the
        // shared dedupeKey() (which includes the cover) would NOT merge them —
        // this provider collapses on title+author and keeps the first (highest
        // relevance) hit.
        $client = new MockHttpClient($this->json([
            ['title' => 'Dune', 'authors' => [['fullName' => 'Frank Herbert']], 'imageUrl' => 'https://a/1.jpg'],
            ['title' => 'dune', 'authors' => [['fullName' => 'Frank Herbert']], 'imageUrl' => 'https://b/2.jpg'],
        ]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertCount(1, $result->items);
        self::assertSame('https://a/1.jpg', $result->items[0]->coverPath); // the first occurrence survives
    }

    /**
     * Shops list the same book with different coverage, so the top hit is often the
     * one missing the cover or the blurb that the listing right behind it carries.
     * It keeps its place and its own values; only its gaps are filled.
     */
    public function testDuplicatesFillTheWinnersMissingCoverAndDescription(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'Dune', 'authors' => [['fullName' => 'Frank Herbert']]],
            ['title' => 'Dune', 'authors' => [['fullName' => 'Frank Herbert']], 'imageUrl' => 'https://b/2.jpg', 'description' => 'A desert epic.'],
        ]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertCount(1, $result->items);
        self::assertSame('https://b/2.jpg', $result->items[0]->coverPath);
        self::assertSame('A desert epic.', $result->items[0]->description);
    }

    public function testAWinnersOwnValuesAreNeverOverwritten(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'Dune', 'authors' => [['fullName' => 'F']], 'imageUrl' => 'https://a/1.jpg', 'description' => 'First.'],
            ['title' => 'Dune', 'authors' => [['fullName' => 'F']], 'imageUrl' => 'https://b/2.jpg', 'description' => 'Second.'],
        ]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertSame('https://a/1.jpg', $result->items[0]->coverPath);
        self::assertSame('First.', $result->items[0]->description);
    }

    /** Merging must not reorder the relevance-sorted set the windowing depends on. */
    public function testMergingPreservesRelevanceOrder(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'First', 'authors' => [['fullName' => 'A']]],
            ['title' => 'Second', 'authors' => [['fullName' => 'B']]],
            ['title' => 'First', 'authors' => [['fullName' => 'A']], 'imageUrl' => 'https://a/1.jpg'],
        ]));

        $result = $this->provider($client)->search('x', 12);

        self::assertSame(['First', 'Second'], array_map(fn ($t) => $t->title, $result->items));
    }

    public function testResultsAreCappedAtLimitWithMoreToCome(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]));

        $result = $this->provider($client)->search('x', 2);

        self::assertCount(2, $result->items);
        self::assertTrue($result->hasMore); // a third distinct book remains
    }

    public function testSecondPageWindowsTheDedupedSet(): void
    {
        $client = new MockHttpClient($this->json([
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]));
        $provider = $this->provider($client);

        $page2 = $provider->search('x', 2, 2);

        self::assertCount(1, $page2->items);
        self::assertSame('Three', $page2->items[0]->title);
        self::assertFalse($page2->hasMore); // nothing past the third book
    }

    public function testOffsetBeyondTheEndIsEmpty(): void
    {
        $client = new MockHttpClient($this->json([['title' => 'One'], ['title' => 'Two']]));

        $result = $this->provider($client)->search('x', 12, 100);

        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
    }

    public function testPagingReusesTheSingleCachedFetch(): void
    {
        // The whole set is fetched once and cached (key ignores limit/offset), so
        // scrolling to later pages never hits the API again.
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->json([['title' => 'One'], ['title' => 'Two'], ['title' => 'Three']]);
        });
        $provider = $this->provider($client);

        $provider->search('x', 2, 0);
        $provider->search('x', 2, 2);

        self::assertSame(1, $calls);
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

            return $this->json([['title' => 'Dune', 'authors' => [['fullName' => 'Frank Herbert']]]]);
        });
        $provider = $this->provider($client);

        $first = $provider->search('dune', 12);
        $second = $provider->search('dune', 12);

        self::assertSame(1, $calls, 'The second identical search should hit the cache, not the API.');
        self::assertEquals($first, $second);
    }

    /**
     * One entry backs every page of a query here, so a cached empty wouldn't just
     * pin one page as "no matches" — it would freeze the whole scroll for the TTL.
     */
    public function testEmptyResultsAreNotCached(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? $this->json([])
                : $this->json([['title' => 'Dune', 'authors' => [['fullName' => 'Frank Herbert']]]]);
        });
        $provider = $this->provider($client);

        self::assertSame([], $provider->search('dune', 12)->items);
        $second = $provider->search('dune', 12);

        self::assertSame(2, $calls, 'An empty search must not be cached.');
        self::assertCount(1, $second->items, 'The retry should see the now-populated upstream.');
    }

    public function testEquivalentQueriesShareOneCacheEntry(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return $this->json([['title' => 'Dune']]);
        });
        $provider = $this->provider($client);

        // Case + surrounding/inner whitespace differences normalise to the same key.
        $provider->search('Dune', 12);
        $provider->search('  dune ', 12);

        self::assertSame(1, $calls);
    }

    /**
     * After a failure the source is parked for a short while. It matters more here
     * than for Open Library: this client waits up to 12s (a cold query genuinely
     * takes the upstream ~7s), and each attempt holds one of five PHP-FPM workers
     * for that whole time.
     */
    public function testAFailureParksTheSourceForTheCooldown(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('down', ['http_code' => 503]);
        });
        $provider = $this->provider($client, 45);

        self::assertSame([], $provider->search('dune', 12)->items);
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
        $provider = $this->provider($client, 0);

        $provider->search('dune', 12);
        $provider->search('other', 12);

        self::assertSame(2, $calls);
    }

    /**
     * Listings are pruned to the mapped fields before caching (the raw response is
     * ~127 KB of shop offers per query), so the cached read must still produce the
     * same template as the live one.
     */
    public function testPrunedCacheEntryStillMapsEveryField(): void
    {
        $client = new MockHttpClient($this->json([
            [
                'title'       => 'Кобзар',
                'authors'     => [['fullName' => 'Тарас Шевченко'], ['fullName' => 'B']],
                'description' => 'Збірка поезій.',
                'imageUrl'    => 'https://shop.example/k.jpg',
                'price'       => 249.0,          // dropped
                'shop'        => ['name' => 'X'], // dropped
                'url'         => 'https://shop.example/k',
            ],
        ]));
        $provider = $this->provider($client);

        $live = $provider->search('кобзар', 12);
        $cached = $provider->search('кобзар', 12);

        self::assertEquals($live->items, $cached->items);
        self::assertSame('Тарас Шевченко', $cached->items[0]->author);
        self::assertSame('Збірка поезій.', $cached->items[0]->description);
        self::assertSame('https://shop.example/k.jpg', $cached->items[0]->coverPath);
        self::assertSame('uk', $cached->items[0]->language);
    }

    /**
     * A shape surprise must degrade, not 500: before the guards, a scalar among the
     * listings reached the mapper's array parameter as a TypeError.
     */
    public function testMalformedPayloadShapesDegradeToEmpty(): void
    {
        self::assertSame([], $this->provider(new MockHttpClient($this->json(['a string', 42])))->search('x', 12)->items);
        self::assertSame([], $this->provider(new MockHttpClient($this->json(['unexpected' => true])))->search('x', 12)->items);
    }

    public function testUpstreamFailureIsNotCached(): void
    {
        // First request fails, second succeeds — same query. A cached error would
        // make the second call return [] too; it must instead refetch. (Cooldown off:
        // it would legitimately suppress that refetch — see the cooldown tests.)
        $client = new MockHttpClient([
            new MockResponse('down', ['http_code' => 503]),
            $this->json([['title' => 'Dune']]),
        ]);
        $provider = $this->provider($client);

        self::assertSame([], $provider->search('dune', 12)->items);
        $second = $provider->search('dune', 12);
        self::assertCount(1, $second->items);
        self::assertSame('Dune', $second->items[0]->title);
    }
}
