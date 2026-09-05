<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * The one predicate that decides whether a member is visible to the community:
 * neither suspended nor deleted.
 *
 * It is a helper called query by query rather than a Doctrine filter, and that
 * is the same decision BookRepository::onShelf() makes for the two book shelves.
 * A global filter is a default, and a default is inherited silently — the admin
 * panel, the analytics aggregates and the operator's own member list all need to
 * see exactly the rows this hides, and each of them would have to remember to
 * switch the filter off. Forcing every query to *state* which side it wants
 * makes the exceptions visible in the code that takes them.
 *
 * Deliberately NOT applied by:
 *   - the admin member list (its whole job is to show these rows),
 *   - App\Service\Analytics\StatsProvider (an operator counting the community
 *     wants the truth, including the members they suspended),
 *   - anything scoped to a single owner that already runs its own guard —
 *     the collection and public-library endpoints check the member directly, so
 *     they can answer 404 rather than silently returning an empty shelf.
 */
final class VisibleUsers
{
    /**
     * Narrows $qb to members the community may see. $alias must already name a
     * User in the query — join it first if the root entity is something else.
     */
    public static function scope(QueryBuilder $qb, string $alias = 'u'): QueryBuilder
    {
        return $qb
            ->andWhere(\sprintf('%s.bannedAt IS NULL', $alias))
            ->andWhere(\sprintf('%s.deletedAt IS NULL', $alias));
    }
}
