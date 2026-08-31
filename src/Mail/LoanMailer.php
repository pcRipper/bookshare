<?php

namespace App\Mail;

use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Service\LoanEventPublisher;

/**
 * Turns a loan transition into a mail, using the same reason vocabulary and the
 * same recipient rules as LoanEventPublisher — the real-time signal and the mail
 * are two channels for one event, so they must never disagree about who cares.
 *
 * Callers hand over a request plus the reason they just published, and this
 * resolves the rest. Like the publisher, it must be called AFTER flush: the mail
 * links back to state a refetch has to be able to read.
 *
 * A collection borrow reuses the per-book mails with `isCollection` set. The
 * two payloads differ only in "a book by an author" vs "a collection of N
 * books", which is the same collapse ResponseMapper and the SPA's LoanCard
 * already make; a parallel set of six collection templates would have been six
 * files kept in sync by hand.
 */
final class LoanMailer
{
    /**
     * reason ⇒ the mail it sends, or null for "deliberately no mail".
     *
     * The two cancellations are null on purpose: a withdrawal would mail an
     * owner about a pending request that no longer exists, and it is the
     * transition that fires most on impulse browsing — the worst volume-to-value
     * ratio in the set. The Mercure signal already clears their badge.
     * MailTypeTest fails if a reason is missing from this map entirely, so a new
     * signal can't quietly send nothing.
     */
    private const TYPE_BY_REASON = [
        LoanEventPublisher::REQUEST_RECEIVED  => MailType::LoanRequested,
        LoanEventPublisher::REQUEST_APPROVED  => MailType::LoanApproved,
        LoanEventPublisher::REQUEST_DECLINED  => MailType::LoanDeclined,
        LoanEventPublisher::RETURN_REQUESTED  => MailType::LoanReturnRequested,
        LoanEventPublisher::RETURN_CONFIRMED  => MailType::LoanReturnConfirmed,
        LoanEventPublisher::REQUEST_CANCELLED => null,

        LoanEventPublisher::COLLECTION_REQUEST_RECEIVED  => MailType::LoanRequested,
        LoanEventPublisher::COLLECTION_REQUEST_APPROVED  => MailType::LoanApproved,
        LoanEventPublisher::COLLECTION_REQUEST_DECLINED  => MailType::LoanDeclined,
        LoanEventPublisher::COLLECTION_RETURN_REQUESTED  => MailType::LoanReturnRequested,
        LoanEventPublisher::COLLECTION_RETURN_CONFIRMED  => MailType::LoanReturnConfirmed,
        LoanEventPublisher::COLLECTION_REQUEST_CANCELLED => null,
    ];

    /**
     * Which side of the loan is notified. Identical to the publisher's routing:
     * the owner hears about things asked of them, the requester about answers.
     */
    private const OWNER_REASONS = [
        LoanEventPublisher::REQUEST_RECEIVED,
        LoanEventPublisher::RETURN_REQUESTED,
        LoanEventPublisher::COLLECTION_REQUEST_RECEIVED,
        LoanEventPublisher::COLLECTION_RETURN_REQUESTED,
    ];

    public function __construct(private readonly Mailer $mailer) {}

    /** A per-book loan transition. */
    public function notifyLoan(LibraryRequest $request, string $reason): void
    {
        $book = $request->getBook();

        $this->notify(
            reason: $reason,
            owner: $book->getOwner(),
            requester: $request->getRequester(),
            context: [
                'item'         => $book->getTitle(),
                'author'       => $book->getAuthor(),
                'isCollection' => false,
                'bookCount'    => null,
                'dueDate'      => $request->getDueDate(),
                'message'      => $this->declineNote($request),
            ],
        );
    }

    /** A collection borrow transition — one mail for the whole group, never one per book. */
    public function notifyCollectionLoan(CollectionRequest $request, string $reason): void
    {
        $collection = $request->getCollection();

        $this->notify(
            reason: $reason,
            owner: $collection->getOwner(),
            requester: $request->getRequester(),
            context: [
                'item'         => $collection->getName(),
                'author'       => null,
                'isCollection' => true,
                // The books actually borrowed (the children), not the whole
                // collection: a partial borrow is the normal case.
                'bookCount'    => $request->getChildren()->count(),
                'dueDate'      => $request->getDueDate(),
                'message'      => $request->getDeclineMessage(),
            ],
        );
    }

    /**
     * Reminder that a loan is due tomorrow or already overdue. Called by
     * app:send-loan-reminders, not by a transition, so it takes the state
     * explicitly and reports whether the mail went out — the command records
     * that against the loan so a daily cron can't send it twice.
     */
    public function remindBorrower(LibraryRequest|CollectionRequest $request, string $state): bool
    {
        $isCollection = $request instanceof CollectionRequest;
        $owner = $isCollection
            ? $request->getCollection()->getOwner()
            : $request->getBook()->getOwner();

        $context = $isCollection
            ? [
                'item'         => $request->getCollection()->getName(),
                'author'       => null,
                'isCollection' => true,
                'bookCount'    => $request->getChildren()->count(),
            ]
            : [
                'item'         => $request->getBook()->getTitle(),
                'author'       => $request->getBook()->getAuthor(),
                'isCollection' => false,
                'bookCount'    => null,
            ];

        return $this->mailer->send($request->getRequester(), MailType::LoanReminder, $context + [
            'state'           => $state,
            'dueDate'         => $request->getDueDate(),
            'counterpart'     => $owner->getFullName(),
            'counterpartRole' => 'owner',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notify(string $reason, User $owner, User $requester, array $context): void
    {
        if (!\array_key_exists($reason, self::TYPE_BY_REASON)) {
            throw new \InvalidArgumentException(sprintf('Unknown loan mail reason "%s".', $reason));
        }

        $type = self::TYPE_BY_REASON[$reason];
        if ($type === null) {
            return; // deliberately no mail for this transition
        }

        $toOwner = \in_array($reason, self::OWNER_REASONS, true);

        $this->mailer->send($toOwner ? $owner : $requester, $type, $context + [
            // The other party, as the recipient sees them: the owner hears about
            // the requester, the requester about the owner. The role drives the
            // label in the shared summary block.
            'counterpart'     => $toOwner ? $requester->getFullName() : $owner->getFullName(),
            'counterpartRole' => $toOwner ? 'requester' : 'owner',
        ]);
    }

    /**
     * The owner's optional note, which lives on the timeline event the decline
     * appended rather than on the request itself (per-book loans have no
     * decline_message column — the event log is the record).
     */
    private function declineNote(LibraryRequest $request): ?string
    {
        $last = $request->getEvents()->last();

        return $last === false ? null : $last->getMessage();
    }
}
