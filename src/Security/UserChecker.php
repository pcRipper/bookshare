<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses a suspended or deleted member before the firewall hands the request to
 * a controller.
 *
 * This is what makes a ban take effect on an already-issued JWT without any
 * revocation machinery: the `main` firewall reloads the user from the database
 * on every request (the same property that lets `app:grant-admin` apply without
 * a re-login), so the check below runs against current state rather than against
 * whatever was true when the token was minted. A suspended member's next call
 * fails, the SPA's 401 interceptor drops their stale credentials, and they land
 * on /login — where AuthRestController refuses to mint them a new one.
 *
 * checkPreAuth, not checkPostAuth: post-auth runs only on a fresh credential
 * check, which a stateless JWT firewall does not perform on every request.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Deleted first: an anonymized row is the more absolute state, and
        // saying "suspended" about it would be both wrong and more informative
        // than the account's remains deserve.
        if ($user->isDeleted()) {
            throw new CustomUserMessageAccountStatusException('This account no longer exists.');
        }

        if ($user->isBanned()) {
            throw new CustomUserMessageAccountStatusException('This account has been suspended.');
        }
    }

    public function checkPostAuth(UserInterface $user): void {}
}
