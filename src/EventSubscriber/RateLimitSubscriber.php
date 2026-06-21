<?php

namespace App\EventSubscriber;

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
        private readonly TokenStorageInterface $tokenStorage,
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

        // Authenticated traffic: per-user, then the tighter IP+user bucket.
        $userId = $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'anonymous';
        $this->ensureAccepted($this->apiUserLimiter->create($userId)->consume());
        $this->ensureAccepted($this->apiIpUserLimiter->create($ip.'|'.$userId)->consume());
    }

    private function ensureAccepted(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $retryAfter,
            'API rate limit exceeded. Please slow down and try again later.',
        );
    }
}
