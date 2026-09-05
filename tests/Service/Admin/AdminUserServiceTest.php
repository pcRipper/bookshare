<?php

namespace App\Tests\Service\Admin;

use App\Entity\User;
use App\Exception\DomainRuleException;
use App\Repository\LibraryRequestRepository;
use App\Service\Admin\AdminUserService;
use App\Service\Admin\UserPurger;
use PHPUnit\Framework\TestCase;

/**
 * The rules, in isolation from the demolition. UserPurger's own behaviour is
 * covered DB-backed in UserPurgerTest; here it is a stub, because what these
 * cases assert is precisely *whether* it gets called.
 */
class AdminUserServiceTest extends TestCase
{
    private function service(bool $hasActiveLoan = false, ?UserPurger $purger = null): AdminUserService
    {
        $requests = $this->createStub(LibraryRequestRepository::class);
        $requests->method('hasActiveLoanInvolving')->willReturn($hasActiveLoan);

        return new AdminUserService(
            $purger ?? $this->createStub(UserPurger::class),
            $requests,
        );
    }

    /**
     * Ids are assigned by the database, so the guards that compare them need a
     * User that has one. Reflection is the least bad way in — the alternative is
     * a setter on the entity that exists only for tests.
     */
    private function user(int $id, bool $admin = false): User
    {
        $user = new User();
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        if ($admin) {
            $user->setRoles([User::ROLE_ADMIN]);
        }

        return $user;
    }

    public function testBanningStoresTheReason(): void
    {
        $target = $this->user(2);

        $this->service()->ban($this->user(1), $target, 'Spamming borrow requests');

        self::assertTrue($target->isBanned());
        self::assertSame('Spamming borrow requests', $target->getBanReason());
    }

    /** A whitespace-only note is no note; it would render as an empty callout. */
    public function testABlankReasonIsStoredAsNoReason(): void
    {
        $target = $this->user(2);

        $this->service()->ban($this->user(1), $target, '   ');

        self::assertTrue($target->isBanned());
        self::assertNull($target->getBanReason());
    }

    public function testReBanningUpdatesTheReason(): void
    {
        $target = $this->user(2)->ban('Typo');

        $this->service()->ban($this->user(1), $target, 'Corrected');

        self::assertSame('Corrected', $target->getBanReason());
    }

    public function testUnbanningLiftsTheSuspension(): void
    {
        $target = $this->user(2)->ban('Spam');

        $this->service()->unban($this->user(1), $target);

        self::assertTrue($target->isActive());
    }

    public function testAnOperatorCannotBanThemselves(): void
    {
        $actor = $this->user(1);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('You cannot do this to your own account.');

        $this->service()->ban($actor, $actor);
    }

    public function testAnOperatorCannotDeleteThemselves(): void
    {
        $actor = $this->user(1);

        $this->expectExceptionMessage('You cannot do this to your own account.');

        $this->service()->delete($actor, $actor);
    }

    public function testAnotherAdministratorCannotBeBanned(): void
    {
        $this->expectExceptionMessage('Administrators cannot be suspended or deleted from here.');

        $this->service()->ban($this->user(1), $this->user(2, admin: true));
    }

    public function testAnotherAdministratorCannotBeDeleted(): void
    {
        $this->expectExceptionMessage('Administrators cannot be suspended or deleted from here.');

        $this->service()->delete($this->user(1), $this->user(2, admin: true));
    }

    public function testADeletedAccountCannotBeBannedAgain(): void
    {
        $target = $this->user(2)->setDeletedAt(new \DateTimeImmutable());

        $this->expectExceptionMessage('This account no longer exists.');

        $this->service()->ban($this->user(1), $target);
    }

    public function testADeletedAccountCannotBeUnbanned(): void
    {
        $target = $this->user(2)->setDeletedAt(new \DateTimeImmutable());

        $this->expectExceptionMessage('This account no longer exists.');

        $this->service()->unban($this->user(1), $target);
    }

    /**
     * The one guard that protects somebody who isn't in the room. Deleting
     * mid-loan destroys the counterpart's record of a book physically in
     * someone's hands, and neither party could then close the loop.
     */
    public function testAMemberWithBooksOnLoanCannotBeDeleted(): void
    {
        $purger = $this->createMock(UserPurger::class);
        $purger->expects(self::never())->method('purge');

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('This member has books on loan.');

        $this->service(hasActiveLoan: true, purger: $purger)->delete($this->user(1), $this->user(2));
    }

    /** Suspending is exactly the escape hatch that message offers. */
    public function testAMemberWithBooksOnLoanCanStillBeSuspended(): void
    {
        $target = $this->user(2);

        $this->service(hasActiveLoan: true)->ban($this->user(1), $target);

        self::assertTrue($target->isBanned());
    }

    public function testDeletingAnOrdinaryMemberPurgesThem(): void
    {
        $target = $this->user(2);

        $purger = $this->createMock(UserPurger::class);
        $purger->expects(self::once())->method('purge')->with($target);

        $this->service(purger: $purger)->delete($this->user(1), $target);
    }
}
