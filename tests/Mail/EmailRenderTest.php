<?php

namespace App\Tests\Mail;

use App\I18n\LocaleCatalog;
use App\Mail\MailType;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;

/**
 * Renders every mail in every locale. Boots the kernel (no DB) because a Twig
 * template can only be proven to render by rendering it: a typo'd variable, a
 * missing include or an `|format_date` on a string is a runtime error, and the
 * failure surfaces in the worker — where nobody is watching — rather than in the
 * request that queued the mail.
 *
 * Also asserts the localisation actually happened: the recipient's language is
 * carried by TemplatedEmail::locale() and applied by the LocaleSwitcher inside
 * BodyRenderer, which is exactly the kind of wiring that breaks silently and
 * ships English to every reader.
 */
class EmailRenderTest extends KernelTestCase
{
    private BodyRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->renderer = self::getContainer()->get('twig.mime_body_renderer');
    }

    /**
     * A representative context per type. Deliberately complete: the templates
     * must not depend on a caller omitting a field.
     *
     * @return iterable<string, array{MailType, array<string, mixed>, string}>
     */
    public static function mails(): iterable
    {
        $loan = [
            'item'            => 'The Left Hand of Darkness',
            'author'          => 'Ursula K. Le Guin',
            'isCollection'    => false,
            'bookCount'       => null,
            'dueDate'         => new \DateTimeImmutable('2026-09-14'),
            'message'         => null,
            'counterpart'     => 'Ada Reader',
            'counterpartRole' => 'requester',
        ];
        $collection = ['isCollection' => true, 'author' => null, 'bookCount' => 4, 'item' => 'The Earthsea Cycle'] + $loan;

        foreach (LocaleCatalog::codes() as $locale) {
            foreach ([
                'requested'        => [MailType::LoanRequested, $loan],
                'approved'         => [MailType::LoanApproved, ['counterpartRole' => 'owner'] + $loan],
                'declined'         => [MailType::LoanDeclined, ['message' => 'Sorry, it is promised to someone else.', 'counterpartRole' => 'owner'] + $loan],
                'declined-no-note' => [MailType::LoanDeclined, ['counterpartRole' => 'owner'] + $loan],
                'return-requested' => [MailType::LoanReturnRequested, $loan],
                'return-confirmed' => [MailType::LoanReturnConfirmed, ['counterpartRole' => 'owner'] + $loan],
                'reminder-due'     => [MailType::LoanReminder, ['state' => 'due_soon', 'counterpartRole' => 'owner'] + $loan],
                'reminder-overdue' => [MailType::LoanReminder, ['state' => 'overdue', 'counterpartRole' => 'owner'] + $loan],
                'collection'       => [MailType::LoanRequested, $collection],
                'welcome'          => [MailType::AccountWelcome, []],
                'follower'         => [MailType::SocialNewFollower, ['follower' => 'Ada Reader', 'followerId' => 7]],
            ] as $name => [$type, $context]) {
                yield "$name ($locale)" => [$type, $context, $locale];
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mails')]
    public function testEveryMailRendersInEveryLocale(MailType $type, array $context, string $locale): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@folioshare.test', 'FolioShare'))
            ->to(new Address('reader@example.com', 'Ada Reader'))
            ->subject('subject')
            ->htmlTemplate($type->template('html'))
            ->textTemplate($type->template('txt'))
            ->locale($locale)
            ->context($context + [
                'appUrl'      => 'https://folioshare.test',
                'settingsUrl' => 'https://folioshare.test/settings',
                'locale'      => $locale,
                'recipient'   => 'Ada Reader',
            ]);

        $this->renderer->render($email);

        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        self::assertNotSame('', trim($html), 'Rendered an empty HTML body.');
        self::assertNotSame('', trim($text), 'Rendered an empty text body — the part that keeps HTML-only mail out of spam filters.');
        // A link out is the point of every one of these mails.
        self::assertStringContainsString('https://folioshare.test', $html);
        self::assertStringContainsString('https://folioshare.test/settings', $text);
        // Twig's escaping is on: an unresolved variable would have surfaced as an
        // exception above, but a stray raw placeholder would not.
        self::assertStringNotContainsString('%count%', $html);
        self::assertStringNotContainsString('%author%', $html);
    }

    /**
     * The recipient's language must actually reach the rendered body. Ukrainian
     * is the clearest tell: a Cyrillic-free body means the locale was dropped.
     */
    public function testTheRecipientLocaleIsApplied(): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@folioshare.test'))
            ->to(new Address('reader@example.com'))
            ->subject('subject')
            ->htmlTemplate(MailType::AccountWelcome->template('html'))
            ->textTemplate(MailType::AccountWelcome->template('txt'))
            ->locale('uk')
            ->context([
                'appUrl'      => 'https://folioshare.test',
                'settingsUrl' => 'https://folioshare.test/settings',
                'locale'      => 'uk',
                'recipient'   => 'Ada Reader',
            ]);

        $this->renderer->render($email);

        self::assertMatchesRegularExpression('/\p{Cyrillic}/u', (string) $email->getHtmlBody());
    }
}
