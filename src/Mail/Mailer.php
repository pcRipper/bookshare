<?php

namespace App\Mail;

use App\Entity\User;
use App\Entity\UserSettings;
use App\I18n\LocaleCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The single outbound-mail path. Every mail in the app goes through send():
 * the opt-in gate, the recipient's language and the best-effort semantics are
 * decided here once, so no caller has to remember them.
 *
 * Three rules worth knowing:
 *
 *  1. **The locale is the recipient's, never the request's.** The actor's
 *     `Accept-Language` (which drives every API response, see LocaleSubscriber)
 *     says nothing about the person being notified, so the language comes from
 *     the recipient's own `UserSettings.locale`, falling back to English when
 *     they never chose one. Templates translate against that value explicitly —
 *     rendering happens in the worker, where the request locale doesn't exist.
 *
 *  2. **A skipped mail is logged, not silent.** An opt-out, a missing address
 *     and a queue failure all leave a record on the dedicated `mail` channel:
 *     a mail that never went out raises no error anywhere else, so this feed is
 *     the only way to tell "nobody was notified" from "nobody needed to be".
 *
 *  3. **Sending is best-effort**, exactly like LoanEventPublisher's Mercure
 *     publish: send() only enqueues (the SendEmailMessage is routed to the async
 *     transport), and a transport failure is caught and logged rather than
 *     turning a committed loan transition into a 500.
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        /** Absolute base URL for links — a worker has no request context. */
        private readonly string $appUrl,
    ) {}

    /**
     * Queues one mail to one member. Returns whether it was queued, which the
     * reminder command uses to decide whether to mark the loan as notified.
     *
     * @param array<string, mixed> $context Twig context; also supplies the
     *                                      subject's %placeholder% values.
     */
    public function send(User $to, MailType $type, array $context = []): bool
    {
        // The column is NOT NULL, so this is the blank case rather than the
        // missing one — but an address the transport would reject outright is
        // worth naming in the log instead of surfacing as a transport warning.
        $email = trim($to->getEmail());
        if ($email === '') {
            $this->skip($type, $to, 'no email address');

            return false;
        }

        $settings = $to->getSettings();
        if (!self::isAllowed($type, $settings)) {
            $this->skip($type, $to, 'opted out');

            return false;
        }

        $locale = $settings?->getLocale() ?? LocaleCatalog::DEFAULT;
        $context = $context + [
            'appUrl'    => $this->appUrl,
            'locale'    => $locale,
            'recipient' => $to->getFullName(),
            // Every mail footers a way out; the one ungated mail (welcome) links
            // there too, since it is where the other seven are turned off.
            'settingsUrl' => $this->appUrl.'/settings',
        ];

        $message = (new TemplatedEmail())
            ->to(new Address($email, $to->getFullName() ?? ''))
            ->subject($this->subject($type, $context, $locale))
            ->htmlTemplate($type->template('html'))
            ->textTemplate($type->template('txt'))
            ->locale($locale)
            ->context($context);

        try {
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            // The domain change already committed — never fail the request for a
            // mail. A queue outage means the mail is lost, hence the warning.
            $this->logger->warning('Mail "{type}" could not be queued: {error}', [
                'type'  => $type->value,
                'to'    => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('Mail "{type}" queued', [
            'type'   => $type->value,
            'to'     => $email,
            'locale' => $locale,
        ]);

        return true;
    }

    /**
     * The type's opt-in gate, read off the recipient's settings. A member with
     * no settings row behaves as if every setting is at its default, which is
     * what a fresh UserSettings instance answers.
     */
    private static function isAllowed(MailType $type, ?UserSettings $settings): bool
    {
        $gate = $type->gate();
        if ($gate === null) {
            return true; // transactional
        }

        return ($settings ?? new UserSettings())->$gate();
    }

    /**
     * Translates the subject at the recipient's locale, filling its
     * %placeholders% from the context. A placeholder with nothing in the context
     * resolves to an empty string rather than rendering the raw `%name%`.
     *
     * @param array<string, mixed> $context
     */
    private function subject(MailType $type, array $context, string $locale): string
    {
        $parameters = [];
        $missing = [];
        foreach ($type->subjectPlaceholders() as $name) {
            $value = (string) ($context[$name] ?? '');
            if (trim($value) === '') {
                $missing[] = $name;
            }

            $parameters['%'.$name.'%'] = $value;
        }

        if ($missing !== []) {
            // Still sent — a subject missing a name is worse read as an error
            // page than as a slightly odd sentence. But it is never intentional:
            // it means a call site's context key does not match the placeholder,
            // which ships a subject like " would like to borrow Dracula" and
            // nothing else in the system notices. Logged rather than thrown so
            // one bad context can't cost a delivery.
            $this->logger->warning('Mail "{type}" subject has unfilled placeholders: {missing}', [
                'type'    => $type->value,
                'missing' => implode(', ', $missing),
            ]);
        }

        return $this->translator->trans($type->subject(), $parameters, 'mails', $locale);
    }

    private function skip(MailType $type, User $to, string $reason): void
    {
        $this->logger->info('Mail "{type}" skipped: {reason}', [
            'type'   => $type->value,
            'userId' => $to->getId(),
            'reason' => $reason,
        ]);
    }
}
