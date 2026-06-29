<?php

namespace App\Tests\Service;

use App\Entity\Book;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Service\LoanEventPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The publisher turns a loan transition into a private, per-user Mercure signal.
 * These tests pin down the recipient routing (owner vs requester), the
 * signal-only payload shape, and the best-effort contract (a hub failure is
 * logged, never thrown — a committed transition must not 500).
 */
class LoanEventPublisherTest extends TestCase
{
    public function testRoutesOwnerSignalsToTheBookOwnerTopic(): void
    {
        $owner = $this->user(7);
        $requester = $this->user(9);
        $request = $this->request($owner, $requester, 42);

        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')->willReturnCallback(
            function (Update $u) use (&$captured) { $captured = $u; return 'id-1'; },
        );

        (new LoanEventPublisher($hub, $this->createStub(LoggerInterface::class)))
            ->publishLoanSignal($request, LoanEventPublisher::REQUEST_RECEIVED);

        self::assertSame(['user/7'], $captured->getTopics());
        self::assertTrue($captured->isPrivate());
        self::assertSame(
            ['reason' => 'request.received', 'requestId' => 42],
            json_decode($captured->getData(), true),
        );
    }

    public function testRoutesRequesterSignalsToTheRequesterTopic(): void
    {
        $request = $this->request($this->user(7), $this->user(9), 42);

        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')->willReturnCallback(
            function (Update $u) use (&$captured) { $captured = $u; return 'id-1'; },
        );

        (new LoanEventPublisher($hub, $this->createStub(LoggerInterface::class)))
            ->publishLoanSignal($request, LoanEventPublisher::REQUEST_APPROVED);

        self::assertSame(['user/9'], $captured->getTopics());
        self::assertSame('request.approved', json_decode($captured->getData(), true)['reason']);
    }

    public function testSwallowsAndLogsAHubFailure(): void
    {
        $request = $this->request($this->user(7), $this->user(9), 42);

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        // Must NOT throw — the loan transition already committed.
        (new LoanEventPublisher($hub, $logger))
            ->publishLoanSignal($request, LoanEventPublisher::RETURN_CONFIRMED);
    }

    public function testSkipsPublishWhenRecipientIsUnpersisted(): void
    {
        // Owner with no id (never persisted) ⇒ nothing to address; don't publish.
        $request = $this->request(new User(), $this->user(9), 42);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');

        (new LoanEventPublisher($hub, $this->createStub(LoggerInterface::class)))
            ->publishLoanSignal($request, LoanEventPublisher::REQUEST_RECEIVED);
    }

    public function testRejectsAnUnknownReason(): void
    {
        $request = $this->request($this->user(7), $this->user(9), 42);

        $this->expectException(\InvalidArgumentException::class);

        (new LoanEventPublisher($this->createStub(HubInterface::class), $this->createStub(LoggerInterface::class)))
            ->publishLoanSignal($request, 'not.a.real.reason');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function user(int $id): User
    {
        return $this->withId(new User(), $id);
    }

    private function request(User $owner, User $requester, int $id): LibraryRequest
    {
        $book = (new Book())->setOwner($owner);

        return $this->withId(
            (new LibraryRequest())->setBook($book)->setRequester($requester),
            $id,
        );
    }

    /** Sets the private identity-column `id` the way Doctrine would post-flush. */
    private function withId(object $entity, int $id): object
    {
        $prop = new \ReflectionProperty($entity, 'id');
        $prop->setValue($entity, $id);

        return $entity;
    }
}
