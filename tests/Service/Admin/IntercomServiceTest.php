<?php

namespace App\Tests\Service\Admin;

use App\Dto\IntercomLetterInput;
use App\Dto\IntercomLetterItem;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Mail\Mailer;
use App\Entity\IntercomLetter;
use App\Repository\IntercomLetterRepository;
use App\Repository\UserRepository;
use App\Service\Admin\IntercomService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs through a real App\Mail\Mailer with a stubbed transport, as LoanMailerTest
 * does — and here it matters even more: the opt-in gate lives inside Mailer, and
 * "who actually receives a mass mail" is the whole behaviour under test. A
 * doubled mailer would only assert that this service calls a method.
 */
class IntercomServiceTest extends TestCase
{
    /** @var list<TemplatedEmail> */
    private array $sent = [];

    private Mailer $mailer;

    protected function setUp(): void
    {
        $this->sent = [];

        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (RawMessage $message) {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $this->sent[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->mailer = new Mailer(
            $transport,
            $translator,
            $this->createStub(LoggerInterface::class),
            'https://folioshare.test',
        );
    }

    private function subscriber(string $email, bool $optedIn = true): User
    {
        $user = (new User())->setFullName('Reader')->setEmail($email);
        $user->setSettings((new UserSettings())->setNotifyNewsletter($optedIn));

        return $user;
    }

    /** @var list<IntercomLetter> */
    private array $recorded = [];

    private function service(array $recipients): IntercomService
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findNewsletterRecipients')->willReturn($recipients);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) {
            self::assertInstanceOf(IntercomLetter::class, $entity);
            $this->recorded[] = $entity;
        });

        return new IntercomService($users, $this->mailer, $em, $this->createStub(IntercomLetterRepository::class));
    }

    private function letter(): IntercomLetterInput
    {
        $item = new IntercomLetterItem();
        $item->version = '1.28.0';
        $item->lines = ['  Ratings arrived.  ', '   ', 'Discover can sort by rating.'];

        $letter = new IntercomLetterInput();
        $letter->subject = '  What is new in FolioShare  ';
        $letter->intro = '   ';
        $letter->items = [$item];

        return $letter;
    }

    public function testOneMailPerOptedInMember(): void
    {
        $summary = $this->service([
            $this->subscriber('a@example.com'),
            $this->subscriber('b@example.com'),
            $this->subscriber('c@example.com'),
        ])->send($this->letter());

        self::assertSame(['queued' => 3, 'skipped' => 0], $summary);
        self::assertCount(3, $this->sent);
    }

    /**
     * The gate is checked twice on purpose — once by the query that produces the
     * operator's recipient count, once by Mailer per recipient. A member who
     * opted out between the two is skipped rather than mailed.
     */
    public function testAMemberWhoOptedOutAfterTheCountIsNotMailed(): void
    {
        $summary = $this->service([
            $this->subscriber('in@example.com'),
            $this->subscriber('out@example.com', optedIn: false),
        ])->send($this->letter());

        self::assertSame(['queued' => 1, 'skipped' => 1], $summary);
        self::assertCount(1, $this->sent);
        self::assertSame('in@example.com', $this->sent[0]->getTo()[0]->getAddress());
    }

    public function testTheLetterCarriesTheOperatorsSubjectVerbatim(): void
    {
        $this->service([$this->subscriber('a@example.com')])->send($this->letter());

        // Trimmed, and not replaced by the MailType's own translated subject:
        // a human wrote this line.
        self::assertSame('What is new in FolioShare', $this->sent[0]->getSubject());
    }

    public function testBlankLinesAreDroppedAndTheRestTrimmed(): void
    {
        $this->service([$this->subscriber('a@example.com')])->send($this->letter());

        $context = $this->sent[0]->getContext();
        self::assertSame(
            [['version' => '1.28.0', 'lines' => ['Ratings arrived.', 'Discover can sort by rating.']]],
            $context['items'],
        );
        // A blank intro becomes null so the template falls back to its own
        // translated opening rather than printing an empty paragraph.
        self::assertNull($context['intro']);
    }

    /**
     * The record is written even when nothing was queued: "we sent this and it
     * reached nobody" is exactly the fact an operator needs to see.
     */
    public function testTheSendIsRecordedWithTheQueuedCount(): void
    {
        $operator = $this->subscriber('operator@example.com');

        $this->service([
            $this->subscriber('in@example.com'),
            $this->subscriber('out@example.com', optedIn: false),
        ])->send($this->letter(), $operator);

        self::assertCount(1, $this->recorded);
        $letter = $this->recorded[0];
        self::assertSame('What is new in FolioShare', $letter->getSubject());
        // One queued of two attempted — the opted-out member is not a recipient.
        self::assertSame(1, $letter->getRecipientCount());
        self::assertSame($operator, $letter->getSentBy());
        self::assertSame(
            [['version' => '1.28.0', 'lines' => ['Ratings arrived.', 'Discover can sort by rating.']]],
            $letter->getItems(),
        );
    }

    /** A test send is not a send: it must leave no trace in the history. */
    public function testATestLetterIsNotRecorded(): void
    {
        $operator = $this->subscriber('operator@example.com');

        $this->service([$this->subscriber('a@example.com')])->sendTest($operator, $this->letter());

        self::assertSame([], $this->recorded);
    }

    public function testAudienceSizeCountsTheOptedIn(): void
    {
        self::assertSame(2, $this->service([
            $this->subscriber('a@example.com'),
            $this->subscriber('b@example.com'),
        ])->audienceSize());
    }

    public function testATestLetterGoesOnlyToTheOperator(): void
    {
        $operator = $this->subscriber('operator@example.com');

        // The audience is deliberately non-empty: a test send must not touch it.
        $queued = $this->service([
            $this->subscriber('a@example.com'),
            $this->subscriber('b@example.com'),
        ])->sendTest($operator, $this->letter());

        self::assertTrue($queued);
        self::assertCount(1, $this->sent);
        self::assertSame('operator@example.com', $this->sent[0]->getTo()[0]->getAddress());
    }
}
