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

    private function provider(MockHttpClient $client): ExternalBookTemplateProvider
    {
        // Fresh in-memory cache per provider so tests are isolated.
        return new ExternalBookTemplateProvider($client, new NullLogger(), new ArrayAdapter(), 604800);
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

    public function testHasMoreWhenAFullPageOfRawDocsComesBack(): void
    {
        // A full limit of raw docs implies another page exists upstream.
        self::assertTrue($this->provider(new MockHttpClient($this->docs(12)))->search('x', 12)->hasMore);
        // A short page is the last one.
        self::assertFalse($this->provider(new MockHttpClient($this->docs(5)))->search('x', 12)->hasMore);
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

    public function testFreeTextQueryHitsTheTitleIndex(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return $this->json(['docs' => []]);
        });

        $this->provider($client)->search('dune', 12);

        self::assertStringContainsString('title=', $urls[0]);
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
        // make the second call return [] too; it must instead refetch.
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
}
