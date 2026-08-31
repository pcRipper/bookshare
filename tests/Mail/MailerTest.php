<?php

namespace App\Tests\Mail;

use App\Entity\User;
use App\Entity\UserSettings;
use App\Mail\Mailer;
use App\Mail\MailType;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules that make the send path safe to call from anywhere: the opt-in gate,
 * the recipient's locale, and never failing the caller.
 */
class MailerTest extends TestCase
{
    /** @var list<TemplatedEmail> */
    private array $sent = [];

    private function mailer(?\Throwable $transportError = null, ?LoggerInterface $logger = null): Mailer
    {
        $this->sent = [];

        // A stub, not a mock: it only has to record what it was handed (the
        // strict test config fails an expectation-free mock).
        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (RawMessage $message) use ($transportError) {
            if ($transportError !== null) {
                throw $transportError;
            }
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $this->sent[] = $message;
        });

        // Echoes the id back with its parameters filled, which is exactly what
        // an untranslated locale does — so a rendered subject is still readable.
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => strtr($id, $parameters),
        );

        return new Mailer($transport, $translator, $logger ?? $this->createStub(LoggerInterface::class), 'https://folioshare.test');
    }

    private function user(?UserSettings $settings = null, string $email = 'reader@example.com'): User
    {
        $user = (new User())->setFullName('Ada Reader')->setEmail($email);
        if ($settings !== null) {
            $user->setSettings($settings);
        }

        return $user;
    }

    public function testAnOptedInRecipientIsSent(): void
    {
        $mailer = $this->mailer();

        $context = ['requester' => 'Bo Borrower', 'item' => 'Dracula'];

        self::assertTrue($mailer->send($this->user(), MailType::LoanRequested, $context));
        self::assertCount(1, $this->sent);
        self::assertSame('Bo Borrower would like to borrow Dracula', $this->sent[0]->getSubject());
    }

    /**
     * A placeholder with nothing behind it still sends — but it is always a bug
     * at the call site, and it is invisible from anywhere else: the mail is
     * delivered, the queue is clean, and the subject just quietly misses a name.
     * This is what shipped " would like to borrow The Dunwich Horror" to a real
     * inbox, because LoanMailer named the person `counterpart` and the subject
     * asked for `%requester%`.
     */
    public function testAnUnfilledSubjectPlaceholderIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('unfilled placeholders'),
                self::callback(static fn (array $c) => $c['missing'] === 'requester'),
            );

        $mailer = $this->mailer(logger: $logger);

        self::assertTrue($mailer->send($this->user(), MailType::LoanRequested, ['item' => 'Dracula']));
    }

    public function testAnOptedOutRecipientIsNotSent(): void
    {
        $settings = (new UserSettings())->setNotifyBorrowRequests(false);
        $mailer = $this->mailer();

        self::assertFalse($mailer->send($this->user($settings), MailType::LoanRequested));
        self::assertSame([], $this->sent);
    }

    /**
     * The gate is per type, so opting out of one family must not silence
     * another: a reader who doesn't want request notifications still hears that
     * their own loan was approved.
     */
    public function testTheGateOnlySilencesItsOwnFamily(): void
    {
        $settings = (new UserSettings())->setNotifyBorrowRequests(false);
        $mailer = $this->mailer();

        self::assertFalse($mailer->send($this->user($settings), MailType::LoanRequested));
        self::assertTrue($mailer->send($this->user($settings), MailType::LoanApproved));
        self::assertCount(1, $this->sent);
    }

    /**
     * A member who never touched their settings has no row, and must behave
     * exactly as the entity's defaults say — including notifyActivity being off,
     * which is why a follow mails nobody by default.
     */
    public function testMissingSettingsFallBackToTheEntityDefaults(): void
    {
        $mailer = $this->mailer();

        self::assertTrue($mailer->send($this->user(), MailType::LoanRequested), 'notifyBorrowRequests defaults to on.');
        self::assertFalse($mailer->send($this->user(), MailType::SocialNewFollower), 'notifyActivity defaults to off.');
    }

    /** The one ungated mail: it is where the other seven are explained. */
    public function testTheWelcomeMailIgnoresEveryOptOut(): void
    {
        $settings = (new UserSettings())
            ->setNotifyBorrowRequests(false)
            ->setNotifyRequestUpdates(false)
            ->setNotifyActivity(false)
            ->setNotifyNewsletter(false);

        self::assertTrue($this->mailer()->send($this->user($settings), MailType::AccountWelcome));
    }

    /** The address column is NOT NULL, so blank is the only unusable shape. */
    public function testAnAddresslessRecipientIsNotSent(): void
    {
        $mailer = $this->mailer();

        self::assertFalse($mailer->send($this->user(email: '  '), MailType::AccountWelcome));
        self::assertSame([], $this->sent);
    }

    /**
     * The recipient's own stored language, never the request's: whoever
     * triggered this mail says nothing about who reads it.
     */
    public function testTheRecipientsStoredLocaleIsUsed(): void
    {
        $settings = (new UserSettings())->setLocale('uk');

        $this->mailer()->send($this->user($settings), MailType::AccountWelcome);

        self::assertSame('uk', $this->sent[0]->getLocale());
        self::assertSame('uk', $this->sent[0]->getContext()['locale']);
    }

    public function testAReaderWithNoStoredLocaleGetsEnglish(): void
    {
        $this->mailer()->send($this->user(), MailType::AccountWelcome);

        self::assertSame('en', $this->sent[0]->getLocale());
    }

    /**
     * Links are absolute and built from DEFAULT_URI, because the worker renders
     * these with no request context to resolve a relative path against.
     */
    public function testEveryMailCarriesTheAbsoluteAppAndSettingsUrls(): void
    {
        $this->mailer()->send($this->user(), MailType::AccountWelcome);

        $context = $this->sent[0]->getContext();
        self::assertSame('https://folioshare.test', $context['appUrl']);
        self::assertSame('https://folioshare.test/settings', $context['settingsUrl']);
    }

    /** A caller's context must win over the defaults, not be silently dropped. */
    public function testCallerContextReachesTheTemplate(): void
    {
        $this->mailer()->send($this->user(), MailType::LoanApproved, ['item' => 'Dune', 'counterpart' => 'Bo']);

        $context = $this->sent[0]->getContext();
        self::assertSame('Dune', $context['item']);
        self::assertSame('Bo', $context['counterpart']);
    }

    /**
     * Best-effort, like LoanEventPublisher: the domain change already committed,
     * so a queue failure is reported to the caller and logged — never thrown.
     */
    public function testATransportFailureIsSwallowed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willThrowException(new TransportException('queue is down'));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $mailer = new Mailer($transport, $translator, $logger, 'https://folioshare.test');

        self::assertFalse($mailer->send($this->user(), MailType::AccountWelcome));
    }

    /** A skip is a logged fact, not silence — it is the only trace it leaves. */
    public function testASkipIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $mailer = new Mailer(
            $this->createStub(MailerInterface::class),
            $this->createStub(TranslatorInterface::class),
            $logger,
            'https://folioshare.test',
        );

        $mailer->send($this->user(email: ''), MailType::AccountWelcome);
    }

    public function testTheMailIsAddressedToTheRecipient(): void
    {
        $this->mailer()->send($this->user(), MailType::AccountWelcome);

        $to = $this->sent[0]->getTo();
        self::assertCount(1, $to);
        self::assertSame('reader@example.com', $to[0]->getAddress());
        self::assertSame('Ada Reader', $to[0]->getName());
    }
}
