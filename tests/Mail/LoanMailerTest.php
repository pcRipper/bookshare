<?php

namespace App\Tests\Mail;

use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\LibraryRequestEventType;
use App\Mail\LoanMailer;
use App\Mail\Mailer;
use App\Mail\MailType;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Who hears about a loan transition, and what the mail says about it.
 *
 * Runs through a real App\Mail\Mailer with a stubbed transport rather than a
 * doubled one: the routing decision and the gate that could veto it are the two
 * halves of the same behaviour, and a double between them would only assert that
 * LoanMailer calls a method.
 */
class LoanMailerTest extends TestCase
{
    /** @var list<TemplatedEmail> */
    private array $sent = [];

    private LoanMailer $mails;

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

        $this->mails = new LoanMailer(new Mailer(
            $transport,
            $translator,
            $this->createStub(LoggerInterface::class),
            'https://folioshare.test',
        ));
    }

    private function user(string $name, string $email): User
    {
        return (new User())->setFullName($name)->setEmail($email);
    }

    private function bookLoan(): LibraryRequest
    {
        $owner = $this->user('Owen Owner', 'owner@example.com');
        $requester = $this->user('Bo Borrower', 'borrower@example.com');

        $book = (new Book())->setTitle('Dune')->setAuthor('Frank Herbert')->setOwner($owner);

        return (new LibraryRequest())
            ->setBook($book)
            ->setRequester($requester)
            ->setDueDate(new \DateTimeImmutable('2026-09-20'));
    }

    private function collectionLoan(int $children = 3): CollectionRequest
    {
        $owner = $this->user('Owen Owner', 'owner@example.com');
        $requester = $this->user('Bo Borrower', 'borrower@example.com');

        $collection = (new BookCollection())->setName('The Earthsea Cycle')->setOwner($owner);

        $request = (new CollectionRequest())
            ->setCollection($collection)
            ->setRequester($requester)
            ->setDueDate(new \DateTimeImmutable('2026-09-20'));

        for ($i = 0; $i < $children; ++$i) {
            $child = (new LibraryRequest())
                ->setBook((new Book())->setTitle("Book $i")->setAuthor('Ursula K. Le Guin')->setOwner($owner))
                ->setRequester($requester);
            $request->addChild($child);
        }

        return $request;
    }

    /** @return array{string, string} [recipient address, template stem] */
    private function onlyMail(): array
    {
        self::assertCount(1, $this->sent, 'Expected exactly one mail.');
        $mail = $this->sent[0];

        return [$mail->getTo()[0]->getAddress(), (string) $mail->getHtmlTemplate()];
    }

    /**
     * The owner hears about what is asked of them, the requester about the
     * answers — the same split LoanEventPublisher routes its signals by.
     *
     * @return iterable<string, array{string, string, MailType}>
     */
    public static function bookReasons(): iterable
    {
        yield 'request received → owner' => ['request.received', 'owner@example.com', MailType::LoanRequested];
        yield 'return requested → owner' => ['return.requested', 'owner@example.com', MailType::LoanReturnRequested];
        yield 'approved → requester' => ['request.approved', 'borrower@example.com', MailType::LoanApproved];
        yield 'declined → requester' => ['request.declined', 'borrower@example.com', MailType::LoanDeclined];
        yield 'return confirmed → requester' => ['return.confirmed', 'borrower@example.com', MailType::LoanReturnConfirmed];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bookReasons')]
    public function testABookLoanReachesTheRightSide(string $reason, string $expectedAddress, MailType $expectedType): void
    {
        $this->mails->notifyLoan($this->bookLoan(), $reason);

        self::assertSame([$expectedAddress, $expectedType->template('html')], $this->onlyMail());
    }

    /** @return iterable<string, array{string, string, MailType}> */
    public static function collectionReasons(): iterable
    {
        yield 'request received → owner' => ['collection.request.received', 'owner@example.com', MailType::LoanRequested];
        yield 'return requested → owner' => ['collection.return.requested', 'owner@example.com', MailType::LoanReturnRequested];
        yield 'approved → requester' => ['collection.request.approved', 'borrower@example.com', MailType::LoanApproved];
        yield 'declined → requester' => ['collection.request.declined', 'borrower@example.com', MailType::LoanDeclined];
        yield 'return confirmed → requester' => ['collection.return.confirmed', 'borrower@example.com', MailType::LoanReturnConfirmed];
    }

    /** A collection borrow reuses the per-book templates, with isCollection set. */
    #[\PHPUnit\Framework\Attributes\DataProvider('collectionReasons')]
    public function testACollectionLoanReachesTheRightSide(string $reason, string $expectedAddress, MailType $expectedType): void
    {
        $this->mails->notifyCollectionLoan($this->collectionLoan(), $reason);

        self::assertSame([$expectedAddress, $expectedType->template('html')], $this->onlyMail());
        self::assertTrue($this->sent[0]->getContext()['isCollection']);
    }

    /**
     * One mail for the whole group, and the count is the books actually
     * borrowed — a partial borrow is the normal case, so the collection's own
     * size would overstate it.
     */
    public function testACollectionMailCountsTheBorrowedBooksOnlyOnce(): void
    {
        $this->mails->notifyCollectionLoan($this->collectionLoan(children: 2), 'collection.request.received');

        self::assertCount(1, $this->sent);
        self::assertSame(2, $this->sent[0]->getContext()['bookCount']);
        self::assertNull($this->sent[0]->getContext()['author']);
    }

    /** @return iterable<string, array{string}> */
    public static function cancellationReasons(): iterable
    {
        yield 'book' => ['request.cancelled'];
        yield 'collection' => ['collection.request.cancelled'];
    }

    /**
     * A withdrawal deliberately mails nobody: the request it would describe no
     * longer exists, and it is the transition that fires most on impulse
     * browsing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cancellationReasons')]
    public function testAWithdrawalMailsNobody(string $reason): void
    {
        if ($reason === 'request.cancelled') {
            $this->mails->notifyLoan($this->bookLoan(), $reason);
        } else {
            $this->mails->notifyCollectionLoan($this->collectionLoan(), $reason);
        }

        self::assertSame([], $this->sent);
    }

    public function testAnUnknownReasonIsARefusalRatherThanASilentNoMail(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mails->notifyLoan($this->bookLoan(), 'request.teleported');
    }

    /**
     * The decline note lives on the timeline event the decline appended — a
     * per-book loan has no decline_message column, the event log is the record.
     */
    public function testTheDeclineNoteTravelsWithTheBookMail(): void
    {
        $loan = $this->bookLoan();
        $loan->addEvent(LibraryRequestEventType::Declined, $loan->getBook()->getOwner(), null, 'Promised to someone else.');

        $this->mails->notifyLoan($loan, 'request.declined');

        self::assertSame('Promised to someone else.', $this->sent[0]->getContext()['message']);
    }

    public function testTheDeclineNoteTravelsWithTheCollectionMail(): void
    {
        $request = $this->collectionLoan()->setDeclineMessage('Not while I am rereading them.');

        $this->mails->notifyCollectionLoan($request, 'collection.request.declined');

        self::assertSame('Not while I am rereading them.', $this->sent[0]->getContext()['message']);
    }

    /**
     * The counterpart is "the other party, as the recipient sees them" — get it
     * backwards and every mail names the reader to themselves.
     */
    public function testTheCounterpartIsAlwaysTheOtherParty(): void
    {
        $this->mails->notifyLoan($this->bookLoan(), 'request.received');
        self::assertSame('Bo Borrower', $this->sent[0]->getContext()['counterpart']);
        self::assertSame('requester', $this->sent[0]->getContext()['counterpartRole']);

        $this->sent = [];
        $this->mails->notifyLoan($this->bookLoan(), 'request.approved');
        self::assertSame('Owen Owner', $this->sent[0]->getContext()['counterpart']);
        self::assertSame('owner', $this->sent[0]->getContext()['counterpartRole']);
    }

    /** Reminders always go to the borrower, and always name the owner to chase. */
    public function testAReminderGoesToTheBorrowerAndNamesTheOwner(): void
    {
        self::assertTrue($this->mails->remindBorrower($this->bookLoan(), 'overdue'));

        [$address, $template] = $this->onlyMail();
        self::assertSame('borrower@example.com', $address);
        self::assertSame(MailType::LoanReminder->template('html'), $template);
        self::assertSame('overdue', $this->sent[0]->getContext()['state']);
        self::assertSame('Owen Owner', $this->sent[0]->getContext()['counterpart']);
    }

    public function testACollectionReminderCarriesTheCollectionShape(): void
    {
        self::assertTrue($this->mails->remindBorrower($this->collectionLoan(children: 4), 'due_soon'));

        $context = $this->sent[0]->getContext();
        self::assertTrue($context['isCollection']);
        self::assertSame(4, $context['bookCount']);
        self::assertSame('due_soon', $context['state']);
    }
}
