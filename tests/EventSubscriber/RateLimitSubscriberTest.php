<?php

namespace App\Tests\EventSubscriber;

use App\Api\ApiError;
use App\EventSubscriber\RateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class RateLimitSubscriberTest extends TestCase
{
    public function testAuthEndpointIsThrottledByIp(): void
    {
        $sub = $this->subscriber($this->tokenStorage(null), authIp: 2);
        $event = $this->event('/api/auth/google/callback');

        $sub->onKernelRequest($event);
        $sub->onKernelRequest($event);

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($event);
    }

    public function testAuthEndpointWorksWithoutAnAuthenticatedUser(): void
    {
        // The per-user limiters would key on a null token; the auth branch must
        // never touch them, so a tiny user limit must not affect /api/auth.
        $sub = $this->subscriber($this->tokenStorage(null), authIp: 1000, apiUser: 1, apiIpUser: 1);

        $sub->onKernelRequest($this->event('/api/auth/google'));
        $sub->onKernelRequest($this->event('/api/auth/google'));
        $sub->onKernelRequest($this->event('/api/auth/google'));

        $this->expectNotToPerformAssertions();
    }

    public function testAuthenticatedEndpointIsThrottledPerUser(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), authIp: 1000, apiUser: 2, apiIpUser: 1000);
        $event = $this->event('/api/books');

        $sub->onKernelRequest($event);
        $sub->onKernelRequest($event);

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($event);
    }

    public function testDifferentUsersAreLimitedIndependently(): void
    {
        // Share the same limiter factories (hence the same counters) but vary the
        // authenticated user, proving the per-user keying isolates accounts.
        $authIp = $this->fixedWindow('auth_ip', 1000);
        $apiUser = $this->fixedWindow('api_user', 1);
        $apiIpUser = $this->fixedWindow('api_ip_user', 1000);

        $errors = new ApiError(new IdentityTranslator());
        $publicIp = $this->fixedWindow('public_ip', 1000);
        $pageView = $this->fixedWindow('pageview_ip_user', 1000);
        $adminDump = $this->fixedWindow('admin_dump', 1000);
        $alice = new RateLimitSubscriber($authIp, $apiUser, $apiIpUser, $publicIp, $pageView, $adminDump, $this->tokenStorage('alice'), $errors);
        $bob = new RateLimitSubscriber($authIp, $apiUser, $apiIpUser, $publicIp, $pageView, $adminDump, $this->tokenStorage('bob'), $errors);

        $alice->onKernelRequest($this->event('/api/books'));
        // Bob is a different key — unaffected by Alice exhausting hers.
        $bob->onKernelRequest($this->event('/api/books'));

        $this->expectException(TooManyRequestsHttpException::class);
        $alice->onKernelRequest($this->event('/api/books'));
    }

    public function testIpPlusUserKeyIsolatesByIp(): void
    {
        // User limit is generous; the IP+user bucket is the tight one. The same
        // user from a second IP gets a fresh bucket.
        $sub = $this->subscriber($this->tokenStorage('alice'), authIp: 1000, apiUser: 1000, apiIpUser: 1);

        $sub->onKernelRequest($this->event('/api/books', '10.0.0.1'));
        $sub->onKernelRequest($this->event('/api/books', '10.0.0.2')); // different IP, fresh bucket

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($this->event('/api/books', '10.0.0.1')); // first IP is exhausted
    }

    public function testNonApiPathsAreIgnored(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), authIp: 1, apiUser: 1, apiIpUser: 1);

        // Far more than any limit — but these aren't API requests.
        for ($i = 0; $i < 5; ++$i) {
            $sub->onKernelRequest($this->event('/login'));
        }

        $this->expectNotToPerformAssertions();
    }

    public function testSubRequestsAreIgnored(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), authIp: 1, apiUser: 1, apiIpUser: 1);

        for ($i = 0; $i < 5; ++$i) {
            $sub->onKernelRequest($this->event('/api/books', '1.2.3.4', HttpKernelInterface::SUB_REQUEST));
        }

        $this->expectNotToPerformAssertions();
    }

    public function testPublicPagesAreThrottledByIpAlone(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), publicIp: 1);

        $sub->onKernelRequest($this->event('/api/public/users/7', '10.0.0.1'));
        $sub->onKernelRequest($this->event('/api/public/users/7', '10.0.0.2')); // other IP, fresh bucket

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($this->event('/api/public/users/7', '10.0.0.1'));
    }

    /**
     * The subscriber must return before touching token storage on a public
     * path. On a lazy firewall, *reading* the token forces the deferred
     * authentication — which would undo the `security: false` exemption and
     * make a stale Bearer 401 the page instead of rendering it.
     */
    public function testPublicPagesNeverResolveTheViewer(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $sub = $this->subscriber($tokenStorage);

        $sub->onKernelRequest($this->event('/api/public/users/7'));
        $sub->onKernelRequest($this->event('/api/public/users/7/books'));
        $sub->onKernelRequest($this->event('/api/public/users/7/collections'));
    }

    /** The prefix must not spill onto a future /api/publications. */
    public function testAdjacentPathsAreNotTreatedAsPublic(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), apiUser: 1, publicIp: 1000);

        $sub->onKernelRequest($this->event('/api/publications'));

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($this->event('/api/publications'));
    }

    /**
     * The beacon fires once per SPA navigation, so it must not spend the generous
     * api_user budget a page of real work needs.
     */
    public function testPageviewsGetTheirOwnTighterBucket(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), apiUser: 1000, pageView: 2);
        $event = $this->event('/api/pageviews');

        $sub->onKernelRequest($event);
        $sub->onKernelRequest($event);

        $this->expectException(TooManyRequestsHttpException::class);
        $sub->onKernelRequest($event);
    }

    /** Exhausting the beacon budget must not lock the member out of the app. */
    public function testExhaustingThePageviewBucketLeavesTheApiUsable(): void
    {
        $sub = $this->subscriber($this->tokenStorage('alice'), apiUser: 1000, pageView: 1);

        $sub->onKernelRequest($this->event('/api/pageviews'));

        try {
            $sub->onKernelRequest($this->event('/api/pageviews'));
            self::fail('The pageview bucket should have been exhausted.');
        } catch (TooManyRequestsHttpException) {
            // Expected — that is the point of the tighter bucket.
        }

        $sub->onKernelRequest($this->event('/api/books'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The public twin must be limited as public traffic, and must still return
     * before the token read — the /api/public branch has to win over this one.
     */
    public function testThePublicPageviewTwinNeverResolvesTheViewer(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $this->subscriber($tokenStorage)->onKernelRequest($this->event('/api/public/pageviews'));
    }

    public function testSubscribesToRequestAfterTheFirewall(): void
    {
        $events = RateLimitSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertSame('onKernelRequest', $events[KernelEvents::REQUEST][0]);
        // The firewall listener runs at priority 8; we must run after it so the
        // authenticated user is resolved before keying the per-user limiters.
        self::assertLessThan(8, $events[KernelEvents::REQUEST][1]);
    }

    /**
     * Creating a dump forks pg_dump or walks every table and leaves a file on
     * disk. Five an hour, keyed to the operator.
     */
    public function testCreatingADumpIsThrottledHard(): void
    {
        $subscriber = $this->subscriber($this->tokenStorage('operator'), adminDump: 2);

        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));
        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));

        $this->expectException(TooManyRequestsHttpException::class);
        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));
    }

    /**
     * Only the write. Listing and downloading are ordinary reads, and an
     * operator who has spent the hour's dump budget must still be able to fetch
     * the files that budget produced.
     */
    public function testListingAndDownloadingDumpsAreNotThrottledAsWrites(): void
    {
        $subscriber = $this->subscriber($this->tokenStorage('operator'), adminDump: 1);

        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));

        // Would throw if either of these consumed the exhausted dump bucket.
        $subscriber->onKernelRequest($this->event('/api/admin/dumps'));
        $subscriber->onKernelRequest($this->event('/api/admin/dumps/20260101-000000-abcd-sql.dump'));

        $this->expectException(TooManyRequestsHttpException::class);
        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));
    }

    /** The dump bucket is separate: spending it must not spend the general one. */
    public function testTheDumpBucketIsItsOwn(): void
    {
        $subscriber = $this->subscriber($this->tokenStorage('operator'), apiUser: 2, adminDump: 5);

        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));
        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));
        $subscriber->onKernelRequest($this->event('/api/admin/dumps', method: 'POST'));

        // api_user still has its full budget.
        $subscriber->onKernelRequest($this->event('/api/books'));
        $subscriber->onKernelRequest($this->event('/api/books'));

        $this->expectException(TooManyRequestsHttpException::class);
        $subscriber->onKernelRequest($this->event('/api/books'));
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function subscriber(
        TokenStorageInterface $tokenStorage,
        int $authIp = 1000,
        int $apiUser = 1000,
        int $apiIpUser = 1000,
        int $publicIp = 1000,
        int $pageView = 1000,
        int $adminDump = 1000,
    ): RateLimitSubscriber {
        return new RateLimitSubscriber(
            $this->fixedWindow('auth_ip', $authIp),
            $this->fixedWindow('api_user', $apiUser),
            $this->fixedWindow('api_ip_user', $apiIpUser),
            $this->fixedWindow('public_ip', $publicIp),
            $this->fixedWindow('pageview_ip_user', $pageView),
            $this->fixedWindow('admin_dump', $adminDump),
            $tokenStorage,
            // IdentityTranslator renders the id itself, so the 429 message the
            // assertions read is the English one.
            new ApiError(new IdentityTranslator()),
        );
    }

    private function fixedWindow(string $id, int $limit): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function tokenStorage(?string $userId): TokenStorageInterface
    {
        $storage = $this->createStub(TokenStorageInterface::class);

        if ($userId === null) {
            $storage->method('getToken')->willReturn(null);

            return $storage;
        }

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn($userId);
        $storage->method('getToken')->willReturn($token);

        return $storage;
    }

    private function event(
        string $path,
        string $ip = '1.2.3.4',
        int $type = HttpKernelInterface::MAIN_REQUEST,
        // The dump limiter is the first branch that keys on the verb as well as
        // the path, since only creating a dump is expensive.
        string $method = 'GET',
    ): RequestEvent {
        $request = Request::create($path, $method, [], [], [], ['REMOTE_ADDR' => $ip]);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
