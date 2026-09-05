<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The checker is what makes a ban take effect on a token that was already
 * issued. `when@test: security: ~` means no test here can drive a real request
 * through the firewall, so this exercises the checker directly — the wiring that
 * puts it on the firewall is pinned by AdminAccessConfigTest.
 */
class UserCheckerTest extends TestCase
{
    public function testAnOrdinaryMemberPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker())->checkPreAuth(new User());
    }

    public function testASuspendedMemberIsRefused(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('This account has been suspended.');

        (new UserChecker())->checkPreAuth((new User())->ban('Spam'));
    }

    public function testADeletedMemberIsRefused(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('This account no longer exists.');

        (new UserChecker())->checkPreAuth((new User())->setDeletedAt(new \DateTimeImmutable()));
    }

    /**
     * Deleted wins. An anonymized row is the more absolute state, and reporting
     * "suspended" about it would be both wrong and more informative than the
     * remains of the account deserve.
     */
    public function testDeletionIsReportedInPreferenceToASuspension(): void
    {
        $user = (new User())->ban('Spam')->setDeletedAt(new \DateTimeImmutable());

        $this->expectExceptionMessage('This account no longer exists.');

        (new UserChecker())->checkPreAuth($user);
    }

    /**
     * The firewall hands the checker whatever its provider returns. Ours only
     * ever yields App\Entity\User, but the signature is UserInterface and a
     * TypeError here would be a 500 on every request.
     */
    public function testAForeignUserImplementationIsIgnoredRatherThanCrashing(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker())->checkPreAuth(new InMemoryUser('someone', null));
    }

    #[DataProvider('everyState')]
    public function testPostAuthNeverRefuses(User $user): void
    {
        // A stateless JWT firewall performs no fresh credential check per
        // request, so post-auth would simply not run — putting the rule there
        // would silently do nothing.
        $this->expectNotToPerformAssertions();

        (new UserChecker())->checkPostAuth($user);
    }

    /** @return iterable<string, array{User}> */
    public static function everyState(): iterable
    {
        yield 'ordinary'  => [new User()];
        yield 'suspended' => [(new User())->ban('Spam')];
        yield 'deleted'   => [(new User())->setDeletedAt(new \DateTimeImmutable())];
    }
}
