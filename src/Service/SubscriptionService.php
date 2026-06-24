<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Follow/unfollow another reader. Methods persist/remove but never flush — the
 * controller flushes once per request. Business-rule violations throw
 * \DomainException (controller maps to 409).
 */
class SubscriptionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptions,
    ) {}

    public function subscribe(User $subscriber, User $target): Subscription
    {
        // Object identity covers the common case; the id check also catches the
        // same user arriving as two distinct instances (security token vs resolver).
        if ($target === $subscriber
            || ($subscriber->getId() !== null && $target->getId() === $subscriber->getId())
        ) {
            throw new \DomainException('You cannot follow yourself.');
        }
        if ($target->isPrivate()) {
            throw new \DomainException('This reader keeps their library private.');
        }

        // Idempotent: following someone you already follow returns the existing edge.
        $existing = $this->subscriptions->findOneFor($subscriber, $target);
        if ($existing !== null) {
            return $existing;
        }

        $subscription = (new Subscription())
            ->setSubscriber($subscriber)
            ->setSubscribedTo($target);

        $this->em->persist($subscription);

        return $subscription;
    }

    public function unsubscribe(User $subscriber, User $target): void
    {
        $existing = $this->subscriptions->findOneFor($subscriber, $target);
        if ($existing !== null) {
            $this->em->remove($existing);
        }
    }
}
