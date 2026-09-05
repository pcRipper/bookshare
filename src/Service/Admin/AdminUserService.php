<?php

namespace App\Service\Admin;

use App\Entity\User;
use App\Exception\DomainRuleException;
use App\Repository\LibraryRequestRepository;

/**
 * The rules an operator's actions on another member have to obey.
 *
 * Kept out of the controller for the usual reason — these are decisions, not
 * HTTP — and out of UserPurger because that one is a demolition routine with no
 * opinion about whether the demolition should happen. Persist-never-flush: the
 * controller flushes once.
 *
 * Every violation is a DomainRuleException (a \DomainException, so the existing
 * catch blocks work) rendered as a translated 409, matching how the lending
 * machine reports its own rule violations.
 */
class AdminUserService
{
    public function __construct(
        private readonly UserPurger $purger,
        private readonly LibraryRequestRepository $requests,
    ) {}

    /**
     * Suspends a member. Idempotent in effect but not silently so: re-banning
     * refreshes the reason, which is what an operator correcting a typo expects.
     */
    public function ban(User $actor, User $target, ?string $reason = null): void
    {
        $this->assertActionable($actor, $target);

        $target->ban($reason !== null && trim($reason) !== '' ? trim($reason) : null);
    }

    public function unban(User $actor, User $target): void
    {
        // No assertActionable(): lifting a ban is the one action here that can
        // only ever make an account *more* functional, so the guards that exist
        // to prevent lockout have nothing to protect against.
        if ($target->isDeleted()) {
            throw new DomainRuleException('This account no longer exists.');
        }

        $target->unban();
    }

    /**
     * Deletes a member: anonymized row, library and social graph destroyed.
     *
     * The live-loan refusal is the one guard that is about somebody other than
     * the two people in the room. Deleting mid-loan would destroy the
     * counterpart's record of a book that is physically in someone's hands, and
     * neither party could then close the loop. Suspending achieves the operator's
     * likely goal (stop this person acting) without that collateral, so the
     * message says so.
     */
    public function delete(User $actor, User $target): void
    {
        $this->assertActionable($actor, $target);

        if ($this->requests->hasActiveLoanInvolving($target)) {
            throw new DomainRuleException(
                'This member has books on loan. Suspend the account instead, or wait for the loans to be returned.'
            );
        }

        $this->purger->purge($target);
    }

    /**
     * The two guards every destructive action shares.
     *
     * Self: an operator who suspends themself is locked out of the panel that
     * would undo it, and there is no endpoint to grant a role — only a console
     * command on a machine they may not have.
     *
     * Other admins: the same lockout one step removed, and with two operators it
     * is a mutual-destruction button. Demotion is deliberately console-only
     * (`app:grant-admin --revoke`), so this stays a hard refusal rather than a
     * "demote them first" affordance in the UI.
     */
    private function assertActionable(User $actor, User $target): void
    {
        if ($actor->getId() === $target->getId()) {
            throw new DomainRuleException('You cannot do this to your own account.');
        }

        if ($target->isAdmin()) {
            throw new DomainRuleException('Administrators cannot be suspended or deleted from here.');
        }

        if ($target->isDeleted()) {
            throw new DomainRuleException('This account no longer exists.');
        }
    }
}
