<?php

namespace App\Service;

use App\Entity\LibraryRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a lightweight "something in your loan changed" signal to the Mercure
 * hub after a request transition. The payload carries only a reason + the request
 * id — never authoritative entity data. The SPA reacts by refetching through the
 * existing authenticated REST endpoints, so authorization stays in the REST layer
 * and a missed/out-of-order signal self-heals on the next refetch.
 *
 * Publishing is best-effort: a hub outage is logged but never bubbles up, so a
 * successful loan transition is never turned into a 500. Callers MUST invoke this
 * only AFTER the EntityManager has flushed, so any refetch reads committed state.
 */
final class LoanEventPublisher
{
    // Recipient = book owner.
    public const REQUEST_RECEIVED = 'request.received';
    public const RETURN_REQUESTED = 'return.requested';
    // Recipient = requester.
    public const REQUEST_APPROVED = 'request.approved';
    public const REQUEST_DECLINED = 'request.declined';
    public const RETURN_CONFIRMED = 'return.confirmed';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function publishLoanSignal(LibraryRequest $request, string $reason): void
    {
        $recipient = match ($reason) {
            self::REQUEST_RECEIVED, self::RETURN_REQUESTED => $request->getBook()->getOwner(),
            self::REQUEST_APPROVED, self::REQUEST_DECLINED, self::RETURN_CONFIRMED => $request->getRequester(),
            default => throw new \InvalidArgumentException(sprintf('Unknown loan signal reason "%s".', $reason)),
        };

        $recipientId = $recipient->getId();
        if ($recipientId === null) {
            return; // unpersisted user — nothing to notify
        }

        $update = new Update(
            topics: sprintf('user/%d', $recipientId),
            data: json_encode([
                'reason' => $reason,
                'requestId' => $request->getId(),
            ], \JSON_THROW_ON_ERROR),
            // Private: only delivered to a subscriber whose JWT grants this exact
            // topic — i.e. the recipient themselves. Closes the cross-user leak.
            private: true,
        );

        try {
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            // Best-effort: the loan transition already committed. Don't fail the request.
            $this->logger->warning('Mercure publish failed for loan signal "{reason}": {error}', [
                'reason' => $reason,
                'requestId' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
