<?php

namespace App\Security;

/**
 * The operator gate, in one place.
 *
 * Three facts that have to agree and previously did not: the role, the path
 * prefix `access_control` guards, and the sentence a refused caller reads. The
 * sentence in particular is needed in two places at once — the `#[IsGranted]`
 * attribute on every admin controller, and {@see \App\EventSubscriber\ApiExceptionSubscriber},
 * which is what actually renders it when the firewall refuses before any
 * controller runs. Two literals would drift.
 *
 * The sentence is also its own translation id, per the {@see \App\Api\ApiError}
 * convention, so it lives in `translations/messages.<locale>.yaml`.
 */
final class AdminAccess
{
    public const ROLE = 'ROLE_ADMIN';

    /** Matches the `^/api/admin(/|$)` rule in security.yaml. */
    public const PATH_PREFIX = '/api/admin';

    public const DENIED_MESSAGE = 'Administrator access is required.';
}
