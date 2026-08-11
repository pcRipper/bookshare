<?php

namespace App\Tests\Analytics;

use App\Analytics\AnalyticsRoutes;
use PHPUnit\Framework\TestCase;

/**
 * The allow-list is the whole security model of the ingest endpoint, and it is a
 * vocabulary duplicated in the SPA — so both halves are pinned here, the same job
 * CategoryPaletteTest does for the colour palette.
 */
class AnalyticsRoutesTest extends TestCase
{
    /**
     * Route names the SPA has but deliberately never reports, with the reason
     * recorded in AnalyticsRoutes' docblock. Listing them here rather than
     * loosening the comparison means adding a route without deciding whether it
     * is counted fails the build.
     */
    private const NOT_COUNTED = ['google-callback', 'admin-stats'];

    public function testTheListIsAFlatDuplicateFreeListOfStrings(): void
    {
        $names = AnalyticsRoutes::names();

        self::assertNotEmpty($names);
        self::assertSame(array_values($names), $names);
        self::assertSame($names, array_unique($names));
        foreach ($names as $name) {
            self::assertIsString($name);
            self::assertNotSame('', $name);
        }
    }

    public function testKnownRoutesAreAccepted(): void
    {
        foreach (AnalyticsRoutes::names() as $name) {
            self::assertTrue(AnalyticsRoutes::has($name), $name . ' should be accepted');
        }
    }

    /**
     * Anything outside the list is rejected — including case variants, which
     * would otherwise split one page's traffic across two rows.
     */
    public function testUnknownRoutesAreRejected(): void
    {
        self::assertFalse(AnalyticsRoutes::has(''));
        self::assertFalse(AnalyticsRoutes::has('nope'));
        self::assertFalse(AnalyticsRoutes::has('Library'));
        self::assertFalse(AnalyticsRoutes::has('LIBRARY'));
        self::assertFalse(AnalyticsRoutes::has('/library'));
        self::assertFalse(AnalyticsRoutes::has('library '));
        self::assertFalse(AnalyticsRoutes::has('profile/42'));
    }

    /**
     * The one that earns its keep: turns "keep this in sync with the router" from
     * a comment into a failing test. A route added to the SPA is either counted
     * or listed in NOT_COUNTED — it cannot be silently forgotten, which would
     * otherwise show up as a page that simply never appears in the traffic list.
     */
    public function testTheListMatchesTheRoutesTheSpaActuallyDeclares(): void
    {
        $router = file_get_contents(
            \dirname(__DIR__, 2) . '/assets/src/router/index.js',
        );
        self::assertIsString($router, 'The SPA router could not be read.');

        preg_match_all("/^\s*name:\s*'([^']+)'/m", $router, $matches);
        $declared = array_values(array_unique($matches[1]));
        self::assertNotEmpty($declared, 'No route names were found in the SPA router.');

        $expected = array_diff($declared, self::NOT_COUNTED);

        sort($expected);
        $actual = AnalyticsRoutes::names();
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'AnalyticsRoutes and the SPA router disagree. Add the new route to '
            . 'AnalyticsRoutes::NAMES, or to this test\'s NOT_COUNTED with a reason.',
        );
    }
}
