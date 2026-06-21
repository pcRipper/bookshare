<?php

namespace App\Tests\Entity;

use App\Entity\Subscription;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SubscriptionTest extends TestCase
{
    public function testDefaults(): void
    {
        $subscription = new Subscription();

        self::assertNull($subscription->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $subscription->getCreatedAt());
    }

    public function testSettersAreFluentAndStore(): void
    {
        $subscriber = new User();
        $target = new User();

        $subscription = (new Subscription())
            ->setSubscriber($subscriber)
            ->setSubscribedTo($target);

        self::assertSame($subscriber, $subscription->getSubscriber());
        self::assertSame($target, $subscription->getSubscribedTo());
    }
}
