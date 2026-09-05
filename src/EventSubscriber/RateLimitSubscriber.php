<?php

namespace App\EventSubscriber;

use App\Api\ApiError;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Applies the three configured rate limiters (see config/packages/rate_limiter.yaml)
 * to the JSON API:
 *
 *  - /api/auth/*  → keyed by client IP only (the caller isn't authenticated yet),
 *    blunting credential / OAuth-code brute-force.
 *  - POST /api/admin/dumps → keyed by the operator, five an hour. Its own
 *    bucket because one request forks pg_dump and writes a file.
 *  - every other /api/* → keyed by the authenticated user, and additionally by
 *    IP+user, so neither a single account nor a single client can flood the API.
 *
 * Runs after the firewall (negative priority) so the authenticated user is known.
 * A blocked request becomes a 429 with a Retry-After header.
 */
class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_ip')]
        private readonly RateLimiterFactoryInterface $authIpLimiter,
        #[Autowire(service: 'limiter.api_user')]
        private readonly RateLimiterFactoryInterface $apiUserLimiter,
        #[Autowire(service: 'limiter.api_ip_user')]
        private readonly RateLimiterFactoryInterface $apiIpUserLimiter,
        #[Autowire(service: 'limiter.public_ip')]
        private readonly RateLimiterFactoryInterface $publicIpLimiter,
        #[Autowire(service: 'limiter.pageview_ip_user')]
        private readonly RateLimiterFactoryInterface $pageViewLimiter,
        #[Autowire(service: 'limiter.admin_dump')]
        private readonly RateLimiterFactoryInterface $adminDumpLimiter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ApiError $errors,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority below the firewall (8) so the user is resolved before we key
        // the per-user limiters.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $ip = $request->getClientIp() ?? 'unknown';

        // Unauthenticated auth endpoints: throttle purely by IP.
        if (str_starts_with($path, '/api/auth')) {
            $this->ensureAccepted($this->authIpLimiter->create($ip)->consume());

            return;
        }

        // The signed-out share pages: keyed by IP, and returning *before* the
        // token storage is touched below. On a lazy firewall, merely reading
        // the token forces the deferred authentication to run — which would
        // both defeat the point of the `security: false` firewall and make a
        // stale Bearer header fail the request the SPA is trying to render.
        if (str_starts_with($path, '/api/public/') || $path === '/api/public') {
            $this->ensureAccepted($this->publicIpLimiter->create($ip)->consume());

            return;
        }

        // The traffic beacon fires once per SPA navigation, so it gets its own,
        // much tighter bucket rather than eating the generous api_user budget a
        // real page of work needs. Placed after the two early returns above:
        // /api/public/pageviews must be limited as public traffic, without the
        // token read below waking the lazy firewall.
        if ($path === '/api/pageviews') {
            $userId = $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'anonymous';
            $this->ensureAccepted($this->pageViewLimiter->create($ip . '|' . $userId)->consume());

            return;
        }

        // Authenticated traffic: per-user, then the tighter IP+user bucket.
        $userId = $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'anonymous';

        // Making a dump is the most expensive thing an authenticated request can
        // ask for — it forks pg_dump or walks every table, and the result lands
        // on disk. Its own bucket, and only for the write: listing and
        // downloading are ordinary reads and must stay usable while the
        // five-an-hour budget for creating them is spent.
        if ($request->isMethod('POST') && $path === '/api/admin/dumps') {
            $this->ensureAccepted($this->adminDumpLimiter->create($userId)->consume());

            return;
        }

        $this->ensureAccepted($this->apiUserLimiter->create($userId)->consume());
        $this->ensureAccepted($this->apiIpUserLimiter->create($ip.'|'.$userId)->consume());
    }

    private function ensureAccepted(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

        // Translated here rather than left to the kernel: the 429 body is the one
        // failure the SPA renders straight from `detail`, so it has to follow the
        // caller's language like every other API message.
        throw new TooManyRequestsHttpException(
            $retryAfter,
            $this->errors->translate('API rate limit exceeded. Please slow down and try again later.'),
        );
    }
}
