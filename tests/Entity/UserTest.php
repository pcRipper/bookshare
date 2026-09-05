<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

class UserTest extends TestCase
{
    public function testIsAUserInterface(): void
    {
        self::assertInstanceOf(UserInterface::class, new User());
    }

    public function testDefaults(): void
    {
        $user = new User();

        self::assertNull($user->getId());
        self::assertFalse($user->isPrivate());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testGrantedRolesAlwaysIncludeRoleUser(): void
    {
        $user = (new User())->setRoles([User::ROLE_ADMIN]);

        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
        self::assertTrue($user->isAdmin());
    }

    public function testRoleUserIsNotDuplicatedWhenStoredExplicitly(): void
    {
        // ROLE_USER is implied, so storing it too must not produce it twice.
        $user = (new User())->setRoles(['ROLE_USER', User::ROLE_ADMIN, 'ROLE_USER']);

        self::assertSame(['ROLE_USER', User::ROLE_ADMIN], $user->getRoles());
    }

    public function testRolesCanBeRevoked(): void
    {
        $user = (new User())->setRoles([User::ROLE_ADMIN])->setRoles([]);

        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testUserIdentifierIsEmail(): void
    {
        $user = (new User())->setEmail('reader@example.test');

        self::assertSame('reader@example.test', $user->getUserIdentifier());
    }

    public function testSettersAreFluentAndStore(): void
    {
        $user = (new User())
            ->setGoogleId('g-123')
            ->setEmail('jane@example.test')
            ->setFullName('Jane Doe')
            ->setAvatarUrl('/avatars/jane.png')
            ->setBio('Reader of many books.')
            ->setLocation('Lviv')
            ->setIsPrivate(true);

        self::assertSame('g-123', $user->getGoogleId());
        self::assertSame('jane@example.test', $user->getEmail());
        self::assertSame('Jane Doe', $user->getFullName());
        self::assertSame('/avatars/jane.png', $user->getAvatarUrl());
        self::assertSame('Reader of many books.', $user->getBio());
        self::assertSame('Lviv', $user->getLocation());
        self::assertTrue($user->isPrivate());
    }

    public function testEraseCredentialsIsCallable(): void
    {
        $user = new User();
        $user->eraseCredentials();

        $this->expectNotToPerformAssertions();
    }
    public function testANewAccountIsActive(): void
    {
        $user = new User();

        self::assertTrue($user->isActive());
        self::assertFalse($user->isBanned());
        self::assertFalse($user->isDeleted());
        self::assertNull($user->getBannedAt());
        self::assertNull($user->getBanReason());
    }

    public function testBanningStampsTheTimeAndKeepsTheReason(): void
    {
        $user = (new User())->ban('Spamming borrow requests');

        self::assertTrue($user->isBanned());
        self::assertFalse($user->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getBannedAt());
        self::assertSame('Spamming borrow requests', $user->getBanReason());
    }

    public function testBanningWithoutAReasonIsAllowed(): void
    {
        $user = (new User())->ban();

        self::assertTrue($user->isBanned());
        self::assertNull($user->getBanReason());
    }

    /**
     * The stamp and the reason move together. A reason surviving a lifted ban
     * would render in the admin table as an explanation for a state the member
     * is no longer in.
     */
    public function testUnbanningClearsTheReasonAsWellAsTheStamp(): void
    {
        $user = (new User())->ban('Mistake')->unban();

        self::assertFalse($user->isBanned());
        self::assertTrue($user->isActive());
        self::assertNull($user->getBannedAt());
        self::assertNull($user->getBanReason());
    }

    public function testADeletedAccountIsNotActiveEvenWithoutABan(): void
    {
        $user = (new User())->setDeletedAt(new \DateTimeImmutable());

        self::assertTrue($user->isDeleted());
        self::assertFalse($user->isBanned());
        self::assertFalse($user->isActive());
    }

    /**
     * Unbanning is not a resurrection: it clears the suspension and nothing
     * else, so an anonymized account stays gone.
     */
    public function testUnbanningDoesNotUndoADeletion(): void
    {
        $user = (new User())->ban('x')->setDeletedAt(new \DateTimeImmutable())->unban();

        self::assertFalse($user->isBanned());
        self::assertTrue($user->isDeleted());
        self::assertFalse($user->isActive());
    }

}
