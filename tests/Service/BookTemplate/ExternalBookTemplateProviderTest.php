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
            ],
        ]]));

        $result = $this->provider($client)->search('dune', 12);

        self::assertCount(1, $result);
        self::assertSame('Dune', $result[0]->title);
        self::assertSame('Frank Herbert', $result[0]->author);          // first author
        self::assertSame('9780441013593', $result[0]->isbn);            // first isbn
        self::assertSame('https://covers.openlibrary.org/b/id/12345-M.jpg', $result[0]->coverPath);
        self::assertSame('en', $result[0]->language);                    // MARC eng -> ISO en
    }

    public function testUnmappedLanguageAndMissingCoverBecomeNull(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'Untitled Tongue', 'author_name' => ['A'], 'language' => ['xyz']],
        ]]));

        $result = $this->provider($client)->search('untitled', 12);

        self::assertNull($result[0]->language);
        self::assertNull($result[0]->coverPath);
        self::assertNull($result[0]->isbn);
    }

    public function testDocsWithoutATitleAreSkipped(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['author_name' => ['No Title']],
            ['title' => 'Real Book', 'author_name' => ['B']],
        ]]));

        $result = $this->provider($client)->search('book', 12);

        self::assertCount(1, $result);
        self::assertSame('Real Book', $result[0]->title);
    }

    public function testResultsAreCappedAtLimit(): void
    {
        $client = new MockHttpClient($this->json(['docs' => [
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]]));

        self::assertCount(2, $this->provider($client)->search('x', 2));
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

        self::assertSame([], $this->provider($client)->search('dune', 12));
    }

    public function testBlankQueryMakesNoRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('No HTTP request should be made for a blank query.');
        });

        self::assertSame([], $this->provider($client)->search('   ', 12));
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

        self::assertSame([], $provider->search('dune', 12));
        $second = $provider->search('dune', 12);
        self::assertCount(1, $second);
        self::assertSame('Dune', $second[0]->title);
    }
}
