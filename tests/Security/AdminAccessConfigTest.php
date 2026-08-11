<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the ROLE_ADMIN gate on /api/admin.
 *
 * Same constraint as PublicAccessConfigTest: `when@test: security: ~` disables
 * the security bundle under test, so no test in this suite can make a real
 * request and observe a 403. These assert the shipped configuration instead —
 * that the rule exists, is anchored, and is ordered so it actually fires.
 *
 * What still can't be proven here: that a non-admin really receives 403 and an
 * admin really receives 200. That belongs in the deploy smoke checks.
 */
class AdminAccessConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function security(): array
    {
        return Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/security.yaml')['security'];
    }

    public function testTheAdminPrefixRequiresTheAdminRole(): void
    {
        $rules = $this->security()['access_control'];

        self::assertContains(
            ['path' => '^/api/admin(/|$)', 'roles' => 'ROLE_ADMIN'],
            $rules,
        );
    }

    /**
     * access_control is first-match-wins, so a rule placed after the `^/api`
     * catch-all would never be reached and every member would reach the
     * dashboard with an ordinary token.
     */
    public function testTheAdminRuleIsOrderedBeforeTheAuthenticatedCatchAll(): void
    {
        $paths = array_column($this->security()['access_control'], 'path');

        $admin = array_search('^/api/admin(/|$)', $paths, true);
        $catchAll = array_search('^/api', $paths, true);

        self::assertIsInt($admin, 'The /api/admin rule is missing entirely.');
        self::assertLessThan($catchAll, $admin, 'The catch-all would swallow /api/admin.');
        self::assertSame(\count($paths) - 1, $catchAll, 'The catch-all must remain the last rule.');
    }

    /**
     * Unanchored, `^/api/admin` would also match a future /api/administrators —
     * the same trap already documented for /api/public vs /api/publications.
     */
    public function testTheAdminPatternIsAnchoredToAPathSegment(): void
    {
        $paths = array_column($this->security()['access_control'], 'path');

        self::assertNotContains('^/api/admin', $paths);
    }

    /**
     * The attribute is not redundant with the access_control rule: it is the
     * only thing that supplies a denial message the translator can resolve.
     */
    public function testTheControllerCarriesItsOwnRoleAttribute(): void
    {
        $source = file_get_contents(
            \dirname(__DIR__, 2) . '/src/Controller/AdminStatsRestController.php',
        );

        $code = '';
        foreach (token_get_all($source) as $token) {
            // The docblock explains why both layers exist — don't match on it.
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        self::assertStringContainsString('IsGranted', $code);
        self::assertStringContainsString('ROLE_ADMIN', $code);
        self::assertStringContainsString('Administrator access is required.', $code);
    }
}
