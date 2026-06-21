<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\RateLimitSubscriber;
use PHPUnit\Framework\TestCase;
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

        $alice = new RateLimitSubscriber($authIp, $apiUser, $apiIpUser, $this->tokenStorage('alice'));
        $bob = new RateLimitSubscriber($authIp, $apiUser, $apiIpUser, $this->tokenStorage('bob'));

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

    public function testSubscribesToRequestAfterTheFirewall(): void
    {
        $events = RateLimitSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertSame('onKernelRequest', $events[KernelEvents::REQUEST][0]);
        // The firewall listener runs at priority 8; we must run after it so the
        // authenticated user is resolved before keying the per-user limiters.
        self::assertLessThan(8, $events[KernelEvents::REQUEST][1]);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function subscriber(
        TokenStorageInterface $tokenStorage,
        int $authIp = 1000,
        int $apiUser = 1000,
        int $apiIpUser = 1000,
    ): RateLimitSubscriber {
        return new RateLimitSubscriber(
            $this->fixedWindow('auth_ip', $authIp),
            $this->fixedWindow('api_user', $apiUser),
            $this->fixedWindow('api_ip_user', $apiIpUser),
            $tokenStorage,
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

    private function event(string $path, string $ip = '1.2.3.4', int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
