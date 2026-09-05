<?php

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
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
     *
     * Swept over every Admin*RestController rather than named one at a time, so
     * a controller added to the panel later is covered the day it lands instead
     * of the day somebody remembers to extend this list.
     */
    #[DataProvider('adminControllers')]
    public function testEveryAdminControllerCarriesItsOwnRoleAttribute(string $file): void
    {
        $code = self::stripComments(file_get_contents($file));

        self::assertStringContainsString('IsGranted', $code, basename($file));
        self::assertStringContainsString('ROLE_ADMIN', $code, basename($file));
        self::assertStringContainsString('Administrator access is required.', $code, basename($file));
    }

    /** @return iterable<string, array{string}> */
    public static function adminControllers(): iterable
    {
        $files = glob(\dirname(__DIR__, 2) . '/src/Controller/Admin*RestController.php') ?: [];

        // A glob that silently matched nothing would make this whole sweep pass
        // while proving nothing at all.
        self::assertNotEmpty($files, 'No admin controllers found — has the naming convention changed?');

        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * Suspended and deleted members are refused by a user checker on the `main`
     * firewall, not by a controller. Without the wiring the class is dead code
     * and every ban silently stops working — a failure with no symptom short of
     * a banned member going on using the site.
     */
    public function testTheMainFirewallRunsTheUserChecker(): void
    {
        $main = $this->security()['firewalls']['main'];

        self::assertSame(\App\Security\UserChecker::class, $main['user_checker'] ?? null);
    }

    /**
     * The checker only guards *authenticated* requests. The Google callback
     * mints its token by hand and never passes through the firewall, so without
     * its own branch a suspended member could sign in freshly and hold a valid
     * token until their next request bounced them.
     */
    public function testTheSignInEndpointRefusesAnInactiveAccount(): void
    {
        $code = self::stripComments(
            file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/AuthRestController.php'),
        );

        self::assertStringContainsString('isActive()', $code);
        self::assertStringContainsString('This account has been suspended.', $code);
    }

    /** Source with comments removed, so a docblock explaining a rule can't satisfy it. */
    private static function stripComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
