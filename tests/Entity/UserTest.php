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
}
