<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the two things that make /api/public safe, neither of which any other
 * test can reach: `when@test: security: ~` (config/packages/security.yaml)
 * disables the security bundle under test, so the firewall is never exercised
 * by the suite. These read the shipped YAML instead.
 *
 * What still can't be proven here — that Symfony applies the rules in the order
 * written, and that an expired Bearer on a public route returns 200 rather than
 * 401 — is covered by the deploy smoke checks in DEPLOY.md.
 */
class PublicAccessConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function security(): array
    {
        return Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/security.yaml')['security'];
    }

    /**
     * The public firewall must exist with `security: false` — not merely a
     * PUBLIC_ACCESS access_control rule. With the firewall active the JWT
     * authenticator runs whenever an Authorization header is present and throws
     * on an expired token, 401-ing the very readers most likely to follow a
     * shared link.
     */
    public function testThePublicApiIsExemptFromTheAuthenticatingFirewall(): void
    {
        $firewalls = $this->security()['firewalls'];

        self::assertArrayHasKey('public_api', $firewalls);
        self::assertFalse($firewalls['public_api']['security']);
    }

    /** Firewalls match in declaration order, so the exemption must precede `main`. */
    public function testThePublicFirewallIsDeclaredBeforeTheMainOne(): void
    {
        $names = array_keys($this->security()['firewalls']);

        self::assertLessThan(
            array_search('main', $names, true),
            array_search('public_api', $names, true),
            'public_api must be declared before main or main swallows it.',
        );
    }

    /**
     * Unanchored, `^/api/public` would also match a future /api/publications and
     * silently make it anonymous.
     */
    public function testThePublicPatternsAreAnchoredToAPathSegment(): void
    {
        self::assertSame('^/api/public(/|$)', $this->security()['firewalls']['public_api']['pattern']);

        $paths = array_column($this->security()['access_control'], 'path');
        self::assertContains('^/api/public(/|$)', $paths);
        self::assertNotContains('^/api/public', $paths);
    }

    /** access_control is first-match-wins: the catch-all must stay last. */
    public function testTheAuthenticatedCatchAllRemainsTheLastRule(): void
    {
        $rules = $this->security()['access_control'];
        $last = end($rules);

        self::assertSame(['path' => '^/api', 'roles' => 'IS_AUTHENTICATED_FULLY'], $last);
    }

    /**
     * The public controller has no viewer by construction. A getUser() call
     * would return null and quietly reintroduce viewer-relative output rather
     * than failing, so forbid the token outright.
     */
    public function testThePublicControllerNeverReachesForAViewer(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/PublicRestController.php');

        $code = '';
        foreach (token_get_all($source) as $token) {
            // Comments explain *why* getUser() is banned — don't match on those.
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        self::assertStringNotContainsString('getUser', $code);
        self::assertStringNotContainsString('getToken', $code);
        self::assertStringNotContainsString('TokenStorage', $code);
    }
}
