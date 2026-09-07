<?php

namespace App\Service\Admin;

use App\Dto\IntercomLetterInput;
use App\Entity\IntercomLetter;
use App\Entity\User;
use App\Mail\MailType;
use App\Mail\Mailer;
use App\Repository\IntercomLetterRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sending the letter an operator composed in the Intercom tab.
 *
 * Deliberately thin: every rule that matters about outbound mail already lives
 * in App\Mail\Mailer — the opt-in gate, the *recipient's* locale (never the
 * operator's), the queue hop and the logging of both sends and deliberate
 * skips. This class resolves the audience and hands each member to that one
 * path, so a mass mail behaves exactly like every transactional one.
 *
 * The gate is therefore checked twice, and that is on purpose: the repository
 * asks for opted-in members so the operator sees a truthful recipient count
 * before sending, and Mailer re-checks per recipient so a member who opts out
 * between the count and the send is still not mailed.
 */
final class IntercomService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Mailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly IntercomLetterRepository $letters,
    ) {}

    /** How many members would receive a letter sent right now. */
    public function audienceSize(): int
    {
        return \count($this->users->findNewsletterRecipients());
    }

    /**
     * Queues the letter to every opted-in member and records that it went out.
     *
     * Persists but never flushes — the controller owns the transaction, as
     * everywhere else. The record is written even when nothing was queued: "we
     * sent this and it reached nobody" is exactly the fact an operator needs to
     * see, and it is the one a silent no-op would hide.
     *
     * @return array{queued: int, skipped: int}
     */
    public function send(IntercomLetterInput $letter, ?User $operator = null): array
    {
        $queued = 0;
        $skipped = 0;

        foreach ($this->users->findNewsletterRecipients() as $recipient) {
            if ($this->mailer->send($recipient, MailType::IntercomUpdates, self::context($letter))) {
                ++$queued;
            } else {
                ++$skipped;
            }
        }

        $context = self::context($letter);
        $this->em->persist(
            (new IntercomLetter())
                ->setSubject($context['subject'])
                ->setIntro($context['intro'])
                ->setItems($context['items'])
                ->setRecipientCount($queued)
                ->setSentBy($operator),
        );

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Queues the letter to the operator alone, for a look before the real send.
     *
     * Sent through the same path as everything else, which means it obeys the
     * operator's own opt-in: seeing your own test requires subscribing like
     * anybody else. That is the honest behaviour — a test that bypassed the gate
     * would prove the letter renders but not that the gate works, and the gate
     * is the part that decides whether anyone hears from us at all.
     */
    public function sendTest(User $operator, IntercomLetterInput $letter): bool
    {
        return $this->mailer->send($operator, MailType::IntercomUpdates, self::context($letter));
    }

    /**
     * The last few letters, for the panel's own memory.
     *
     * @return IntercomLetter[]
     */
    public function history(): array
    {
        return $this->letters->findRecent();
    }

    /** @return array<string, mixed> */
    private static function context(IntercomLetterInput $letter): array
    {
        return [
            'subject' => trim($letter->subject),
            'intro'   => trim((string) $letter->intro) ?: null,
            'items'   => array_map(
                static fn ($item) => [
                    'version' => $item->version,
                    'lines'   => array_values(array_filter(array_map('trim', $item->lines), static fn ($l) => $l !== '')),
                ],
                $letter->items,
            ),
        ];
    }
}
