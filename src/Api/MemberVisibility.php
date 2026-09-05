<?php

namespace App\Api;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate every member-scoped endpoint runs before answering about somebody
 * else: is this member still available to the community at all, and if so, has
 * the caller been let past their privacy setting?
 *
 * The two questions produce deliberately different answers:
 *
 *   - Suspended or deleted → **404**, the same one a member who never existed
 *     gets, and the same reasoning PublicRestController::findShared() gives for
 *     collapsing "private" into "missing" there. Ids are sequential, so a
 *     distinguishable status would let anyone walk the id space and read off who
 *     the operator has suspended — a moderation decision that is nobody's
 *     business but the two parties'.
 *   - Private → the existing 403 with the endpoint's own wording. Here the
 *     caller is already a member and the distinction is useful to them.
 *
 * The viewer themself is never checked: App\Security\UserChecker refuses the
 * request before a suspended or deleted member reaches a controller at all, so
 * "the caller is inactive" is not a state this class can observe.
 */
class MemberVisibility
{
    public function __construct(
        private readonly ApiError $errors,
    ) {}

    /**
     * Null when $viewer may see $member; the response to return otherwise.
     *
     * $privateMessage is the endpoint's own 403 wording ('This profile is
     * private.' / 'This library is private.') — it is an English sentence used
     * as its own translation id, per the ApiError convention.
     */
    public function deny(User $member, ?User $viewer, string $privateMessage): ?JsonResponse
    {
        if (!$member->isActive()) {
            return $this->errors->response('This member is no longer available.', Response::HTTP_NOT_FOUND);
        }

        $isSelf = $viewer !== null && $viewer->getId() === $member->getId();

        if (!$isSelf && $member->isPrivate()) {
            return $this->errors->response($privateMessage, Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
