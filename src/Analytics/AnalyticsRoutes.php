<?php

namespace App\Analytics;

/**
 * The vocabulary of page names traffic is counted against — the single source of
 * truth for what `POST /api/pageviews` will accept.
 *
 * Mirrors the `name:` of every route in assets/src/router/index.js, the same
 * duplicated-vocabulary arrangement as CategoryPalette and LocaleCatalog, and
 * kept honest by AnalyticsRoutesTest, which reads the router and compares.
 *
 * The allow-list is a load-bearing constraint, not tidiness. The route is a
 * grouping key, so an endpoint that accepted free text would be an
 * unbounded-cardinality write primitive: one caller could insert a million
 * distinct values, one row each, and destroy the table, its index and the "top
 * pages" list in a single afternoon — while putting attacker-chosen strings on
 * the one page the operator reads attentively. Bounded, the worst a liar can do
 * is make one of ten numbers slightly wrong.
 *
 * A route name never contains an id (the SPA sends `profile`, not `/profile/42`),
 * so nothing here identifies a member or a shared library.
 */
final class AnalyticsRoutes
{
    /**
     * Deliberately excluded, and why:
     *   google-callback — a machine redirect hop, not a page anyone reads.
     *   admin-stats     — the dashboard itself. An operator flipping between
     *                     windows would otherwise make it the top route and
     *                     inflate their own visitor counts.
     */
    public const NAMES = [
        'login',
        'library',
        'discover',
        'subscriptions',
        'profile',
        'settings',
        'changelog',
        'public-library',
        'not-found',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function has(string $name): bool
    {
        return \in_array($name, self::NAMES, true);
    }
}
