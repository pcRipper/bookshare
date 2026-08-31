<?php

namespace App\Mail;

/**
 * Every mail the application can send, and everything that differs between
 * them: the Twig template stem, the subject (an English sentence, which is also
 * its translation id — the `App\Api\ApiError` convention), and which
 * `UserSettings` opt-in gates it.
 *
 * Nothing else in the codebase branches on "which mail is this". A new mail is a
 * new case here plus two template files; `MailTypeTest` fails if the templates
 * are missing or the gate names an accessor `UserSettings` doesn't have.
 *
 * The set is deliberately small — 8 types for 16 candidate notifications:
 *
 *  - A collection borrow reuses the five per-book loan types with `isCollection`
 *    set, because the two differ only in "a book" vs "a collection of N books".
 *    The codebase already collapses that distinction twice (ResponseMapper's
 *    request()/collectionRequest() pair, and one LoanCard in the SPA).
 *  - A withdrawn request sends nothing: it would mail an owner about a pending
 *    request that no longer exists, and it is the transition that fires most on
 *    impulse browsing. See LoanMailer::TYPE_BY_REASON.
 *  - Due-soon and overdue are one type with a `state`, not two mails.
 *
 * Subjects carrying a value use `%name%` placeholders rather than sprintf's %s,
 * since a format string can't be a catalog key (again as ApiError does).
 */
enum MailType: string
{
    /** Someone asked to borrow from you. → book/collection owner. */
    case LoanRequested = 'loan.requested';
    /** Your borrow request was approved, with the due date the owner set. → requester. */
    case LoanApproved = 'loan.approved';
    /** Your borrow request was declined, with the owner's optional note. → requester. */
    case LoanDeclined = 'loan.declined';
    /** Your borrower says they're returning it; confirm to close the loan. → owner. */
    case LoanReturnRequested = 'loan.return_requested';
    /** The owner confirmed your return; the loan is closed. → requester. */
    case LoanReturnConfirmed = 'loan.return_confirmed';
    /** Due tomorrow, or already overdue (context `state`). → borrower. */
    case LoanReminder = 'loan.reminder';

    /** First sign-in. → the new member. Transactional, ungated. */
    case AccountWelcome = 'account.welcome';
    /** Someone started following you. → the followed member. */
    case SocialNewFollower = 'social.new_follower';

    /**
     * The `UserSettings` predicate that must be true for this mail to go out, or
     * null when the mail is transactional and always sent.
     *
     * A member with no settings row behaves as if every setting is at its
     * default (the documented rule on the entity), which is what makes reading
     * the accessor off a fresh UserSettings instance correct.
     */
    public function gate(): ?string
    {
        return match ($this) {
            // "Somebody wants something from you" — the owner-side inbox.
            self::LoanRequested,
            self::LoanReturnRequested => 'notifiesBorrowRequests',
            // "The loan you're in moved" — the borrower-side updates.
            self::LoanApproved,
            self::LoanDeclined,
            self::LoanReturnConfirmed,
            self::LoanReminder => 'notifiesRequestUpdates',
            // Community noise, off by default.
            self::SocialNewFollower => 'notifiesActivity',
            // No opt-out: it is the first thing a new account ever receives.
            self::AccountWelcome => null,
        };
    }

    /**
     * The subject line, in English, which is also its id in the `mails` catalog.
     * Placeholders are filled from the context the caller passes.
     */
    public function subject(): string
    {
        return match ($this) {
            self::LoanRequested        => '%requester% would like to borrow %item%',
            self::LoanApproved         => 'Your request for %item% was approved',
            self::LoanDeclined         => 'Your request for %item% was declined',
            self::LoanReturnRequested  => '%requester% is returning %item%',
            self::LoanReturnConfirmed  => 'Your return of %item% is confirmed',
            self::LoanReminder         => 'A reminder about %item%',
            self::AccountWelcome       => 'Welcome to FolioShare',
            self::SocialNewFollower    => '%follower% is now following you',
        };
    }

    /**
     * Placeholder names this subject expects, without the delimiters. Used by
     * the mailer to build the trans parameters, and by the tests to pin that
     * every placeholder is actually supplied.
     *
     * @return list<string>
     */
    public function subjectPlaceholders(): array
    {
        preg_match_all('/%(\w+)%/', $this->subject(), $matches);

        return $matches[1];
    }

    /** `emails/loan.approved.html.twig` and its `.txt.twig` sibling. */
    public function template(string $extension): string
    {
        return sprintf('emails/%s.%s.twig', $this->value, $extension);
    }
}
