<?php

namespace App\Enum;

/**
 * How badly the owner wants a book on their wish list.
 *
 * Backed by an **integer** rather than a label so `ORDER BY wish_priority DESC`
 * is the ranking itself — a string-backed enum would sort alphabetically
 * ("urgent" before "very_interested" before "can wait"), which is not the order
 * anyone means, and correcting it would cost a CASE expression in every query
 * that touches the wish list.
 *
 * The API emits the number and the SPA owns its presentation (green "can wait",
 * yellow "very interested", red "urgent") — the same division of labour as
 * `status`, whose colours also live in the frontend.
 */
enum WishPriority: int
{
    case CanWait        = 1;
    case VeryInterested = 2;
    case Urgent         = 3;

    /** The level a wish-list book gets when none was chosen. */
    public const DEFAULT = self::CanWait;

    /** @return int[] the valid backing values, for Assert\Choice and CSV parsing */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
