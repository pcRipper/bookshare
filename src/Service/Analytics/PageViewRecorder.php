<?php

namespace App\Service\Analytics;

use App\Repository\PageViewDailyRepository;
use App\Repository\PageViewVisitorRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records one page view: bumps the (route, day) counter and, if this visitor
 * hasn't been seen today, marks them present.
 *
 * Both writes go straight to the database rather than staging for the
 * controller's flush. That is a deliberate exception to the persist-never-flush
 * rule: the ingest request has no other writes, so there is no unit of work to
 * fracture, and a hit counter is telemetry rather than domain state. It is also
 * why ingest is an explicit endpoint and not a kernel.request subscriber — a
 * write whose unique violation can close the EntityManager must never sit in the
 * middle of somebody else's request.
 */
class PageViewRecorder
{
    /**
     * Crawlers only reach the signed-out share pages, but that is precisely the
     * surface whose numbers matter most, so skip the obvious ones rather than
     * let them dominate. Not a security control — a determined bot can lie about
     * its user agent, and the cost of it doing so is one inflated counter.
     */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|monitor|headless|curl|wget|python-requests/i';

    public function __construct(
        private readonly PageViewDailyRepository $daily,
        private readonly PageViewVisitorRepository $visitors,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function record(string $route, Request $request, ?int $userId): void
    {
        if ($this->isBot($request->headers->get('User-Agent') ?? '')) {
            return;
        }

        $day = new \DateTimeImmutable('today');

        $this->daily->increment($route, $day);
        $this->visitors->touch(
            $this->visitorHash($userId, $request, $day),
            $day,
            $userId !== null,
        );
    }

    /**
     * A within-day equality token for one visitor.
     *
     * The day is part of the hashed material, so the same browser produces a
     * different hash tomorrow: the value can answer "have I already counted this
     * visitor today?" and nothing else. It cannot be joined across days into a
     * behavioural profile, and neither the IP, the user agent nor the user id can
     * be recovered from it.
     *
     * Signed-in visitors are keyed by user id — stable, needs no IP at all, and
     * counts one person on two devices once. IP + user agent is only the fallback
     * for the signed-out share pages, where there is nothing better; it
     * undercounts behind NAT and overcounts on a changing mobile IP, which is why
     * the traffic numbers are directional rather than forensic.
     *
     * Salted with %kernel.secret%, deliberately, rather than a new env var: a
     * blank APP_SECRET weakens the hash to guessable-from-IP+UA but does not
     * break it, and this avoids one more key that has to reach .env.local.php.
     */
    private function visitorHash(?int $userId, Request $request, \DateTimeImmutable $day): string
    {
        $seed = $userId !== null
            ? 'u:' . $userId
            : 'a:' . ($request->getClientIp() ?? 'unknown') . '|' . ($request->headers->get('User-Agent') ?? '');

        // 128 bits is collision-free at this scale and halves the index width.
        return substr(hash_hmac('sha256', $seed . '|' . $day->format('Y-m-d'), $this->secret), 0, 32);
    }

    private function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }
}
