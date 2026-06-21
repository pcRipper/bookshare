<?php

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the follow/unfollow rules: you can't follow yourself or a private
 * reader, following is idempotent (no duplicate edge), and unfollowing is a
 * no-op when no edge exists.
 */
class SubscriptionServiceTest extends TestCase
{
    public function testSubscribePersistsANewEdge(): void
    {
        $subscriber = new User();
        $target = new User();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Subscription::class));

        $repo = $this->createStub(SubscriptionRepository::class);
        $repo->method('findOneFor')->willReturn(null);

        $subscription = $this->service($em, $repo)->subscribe($subscriber, $target);

        self::assertSame($subscriber, $subscription->getSubscriber());
        self::assertSame($target, $subscription->getSubscribedTo());
    }

    public function testCannotFollowYourself(): void
    {
        $user = new User();

        $this->expectException(\DomainException::class);
        $this->service()->subscribe($user, $user);
    }

    public function testCannotFollowAPrivateReader(): void
    {
        $subscriber = new User();
        $target = (new User())->setIsPrivate(true);

        $this->expectException(\DomainException::class);
        $this->service()->subscribe($subscriber, $target);
    }

    public function testSubscribeIsIdempotentAndDoesNotPersistTwice(): void
    {
        $subscriber = new User();
        $target = new User();
        $existing = (new Subscription())->setSubscriber($subscriber)->setSubscribedTo($target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $repo = $this->createStub(SubscriptionRepository::class);
        $repo->method('findOneFor')->willReturn($existing);

        $result = $this->service($em, $repo)->subscribe($subscriber, $target);

        self::assertSame($existing, $result);
    }

    public function testUnsubscribeRemovesAnExistingEdge(): void
    {
        $subscriber = new User();
        $target = new User();
        $existing = (new Subscription())->setSubscriber($subscriber)->setSubscribedTo($target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($existing);

        $repo = $this->createStub(SubscriptionRepository::class);
        $repo->method('findOneFor')->willReturn($existing);

        $this->service($em, $repo)->unsubscribe($subscriber, $target);
    }

    public function testUnsubscribeIsANoOpWhenNotFollowing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $repo = $this->createStub(SubscriptionRepository::class);
        $repo->method('findOneFor')->willReturn(null);

        $this->service($em, $repo)->unsubscribe(new User(), new User());
    }

    private function service(
        ?EntityManagerInterface $em = null,
        ?SubscriptionRepository $repo = null,
    ): SubscriptionService {
        return new SubscriptionService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $repo ?? $this->createStub(SubscriptionRepository::class),
        );
    }
}
