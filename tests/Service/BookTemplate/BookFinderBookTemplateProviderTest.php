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

    private function provider(MockHttpClient $client): BookFinderBookTemplateProvider
    {
        // Fresh in-memory cache per provider so tests are isolated.
        return new BookFinderBookTemplateProvider($client, new NullLogger(), new ArrayAdapter(), 604800);
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
        // The API supplies neither an ISBN nor a language.
        self::assertNull($result->items[0]->isbn);
        self::assertNull($result->items[0]->language);
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

    public function testUpstreamFailureIsNotCached(): void
    {
        // First request fails, second succeeds — same query. A cached error would
        // make the second call return [] too; it must instead refetch.
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
