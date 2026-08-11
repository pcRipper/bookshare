<?php

namespace App\Tests\Service\Analytics;

use App\Repository\PageViewDailyRepository;
use App\Repository\PageViewVisitorRepository;
use App\Service\Analytics\PageViewRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The hash is the privacy contract: it must identify a visitor within one day
 * and be useless the next. These pin that, plus the bot skip.
 */
class PageViewRecorderTest extends TestCase
{
    private function request(string $ip = '203.0.113.9', string $agent = 'Mozilla/5.0 (Test)'): Request
    {
        $request = Request::create('/api/pageviews', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', $agent);

        return $request;
    }

    /**
     * record() stamps the current day itself, so the day-rotation and seeding
     * rules below are exercised through the private hash rather than by faking a
     * clock — the alternative would be injecting a clock into production code
     * that has no other use for one.
     */
    private function hashFor(?int $userId, Request $request, string $secret, string $day): string
    {
        $method = new \ReflectionMethod(PageViewRecorder::class, 'visitorHash');
        $recorder = new PageViewRecorder(
            $this->createStub(PageViewDailyRepository::class),
            $this->createStub(PageViewVisitorRepository::class),
            $secret,
        );

        return $method->invoke($recorder, $userId, $request, new \DateTimeImmutable($day));
    }

    public function testTheSameVisitorHashesIdenticallyWithinADay(): void
    {
        $a = $this->hashFor(7, $this->request(), 's', '2026-08-11');
        $b = $this->hashFor(7, $this->request(), 's', '2026-08-11');

        self::assertSame($a, $b);
    }

    /**
     * The whole point of folding the day into the hash: today's and tomorrow's
     * value for one browser can't be linked into a behavioural profile.
     */
    public function testTheSameVisitorHashesDifferentlyOnTheNextDay(): void
    {
        $today = $this->hashFor(7, $this->request(), 's', '2026-08-11');
        $tomorrow = $this->hashFor(7, $this->request(), 's', '2026-08-12');

        self::assertNotSame($today, $tomorrow);
    }

    public function testDifferentVisitorsHashDifferently(): void
    {
        self::assertNotSame(
            $this->hashFor(7, $this->request(), 's', '2026-08-11'),
            $this->hashFor(8, $this->request(), 's', '2026-08-11'),
        );

        self::assertNotSame(
            $this->hashFor(null, $this->request('198.51.100.1'), 's', '2026-08-11'),
            $this->hashFor(null, $this->request('198.51.100.2'), 's', '2026-08-11'),
        );
    }

    /** A signed-in visitor is keyed by id alone, so their IP never enters it. */
    public function testASignedInVisitorHashesTheSameFromAnotherAddress(): void
    {
        self::assertSame(
            $this->hashFor(7, $this->request('198.51.100.1'), 's', '2026-08-11'),
            $this->hashFor(7, $this->request('198.51.100.2'), 's', '2026-08-11'),
        );
    }

    public function testTheSecretChangesTheHash(): void
    {
        self::assertNotSame(
            $this->hashFor(7, $this->request(), 'secret-a', '2026-08-11'),
            $this->hashFor(7, $this->request(), 'secret-b', '2026-08-11'),
        );
    }

    public function testTheHashLeaksNeitherTheAddressNorTheUserId(): void
    {
        $anonymous = $this->hashFor(null, $this->request('203.0.113.9'), 's', '2026-08-11');
        self::assertStringNotContainsString('203.0.113.9', $anonymous);
        self::assertStringNotContainsString('Mozilla', $anonymous);

        $member = $this->hashFor(12345, $this->request(), 's', '2026-08-11');
        self::assertStringNotContainsString('12345', $member);
    }

    public function testTheHashIsAFixedWidthHexToken(): void
    {
        $hash = $this->hashFor(7, $this->request(), 's', '2026-08-11');

        self::assertSame(32, \strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
    }

    /** A real browser bumps the counter and marks the visitor present, once each. */
    public function testARealVisitorIsRecorded(): void
    {
        $daily = $this->createMock(PageViewDailyRepository::class);
        $daily->expects($this->once())
            ->method('increment')
            ->with('library', $this->isInstanceOf(\DateTimeImmutable::class));

        $visitors = $this->createMock(PageViewVisitorRepository::class);
        $visitors->expects($this->once())
            ->method('touch')
            ->with(
                $this->matchesRegularExpression('/^[0-9a-f]{32}$/'),
                $this->isInstanceOf(\DateTimeImmutable::class),
                true,
            );

        (new PageViewRecorder($daily, $visitors, 'test-secret'))
            ->record('library', $this->request(), 7);
    }

    /** A signed-out visitor is recorded, but not as a daily active user. */
    public function testAnAnonymousVisitorIsNotCountedAsActive(): void
    {
        $visitors = $this->createMock(PageViewVisitorRepository::class);
        $visitors->expects($this->once())
            ->method('touch')
            ->with($this->anything(), $this->anything(), false);

        (new PageViewRecorder(
            $this->createStub(PageViewDailyRepository::class),
            $visitors,
            'test-secret',
        ))->record('public-library', $this->request(), null);
    }

    #[DataProvider('botAgents')]
    public function testCrawlersAreNotCounted(string $agent): void
    {
        $daily = $this->createMock(PageViewDailyRepository::class);
        $daily->expects($this->never())->method('increment');

        $recorder = new PageViewRecorder(
            $daily,
            $this->createStub(PageViewVisitorRepository::class),
            'test-secret',
        );

        $recorder->record('public-library', $this->request(agent: $agent), null);
    }

    /** @return iterable<string, array{string}> */
    public static function botAgents(): iterable
    {
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1)'];
        yield 'generic crawler' => ['SomeCrawler/1.0'];
        yield 'link preview' => ['Slackbot-LinkExpanding 1.0 (+preview)'];
        yield 'headless browser' => ['HeadlessChrome/120.0'];
        yield 'curl' => ['curl/8.4.0'];
        // A blank agent is a script, not a browser — no real client omits it.
        yield 'blank' => [''];
    }
}
