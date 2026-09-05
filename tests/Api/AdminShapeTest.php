<?php

namespace App\Tests\Api;

use App\Api\ResponseMapper;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Pins the exact key set the admin member list emits.
 *
 * A whitelist (`assertSame` on `array_keys`), for the reason PublicShapeTest
 * gives: a field added to a shared user shape years from now must fail here
 * rather than quietly appearing. The pressure runs the opposite way from the
 * public shapes, though — this is the one place in the API allowed to publish an
 * email address and an isAdmin flag, so the test's job is to stop that
 * permission spreading, not to stop fields arriving.
 */
class AdminShapeTest extends TestCase
{
    private const ADMIN_USER_KEYS = [
        'id', 'email', 'fullName', 'avatarUrl', 'location', 'isPrivate',
        'isAdmin', 'createdAt', 'bannedAt', 'banReason', 'deletedAt', 'stats',
    ];

    private const STATS = [
        'totalBooks' => 3, 'shared' => 2, 'loaned' => 1, 'collections' => 1, 'wished' => 4,
    ];

    private function mapper(): ResponseMapper
    {
        return new ResponseMapper($this->createStub(AuthorizationCheckerInterface::class));
    }

    private function member(): User
    {
        return (new User())
            ->setGoogleId('g-1')
            ->setEmail('member@test.local')
            ->setFullName('A Member')
            ->setLocation('Kyiv');
    }

    public function testTheShapeIsExactlyTheWhitelist(): void
    {
        $shape = $this->mapper()->adminUser($this->member(), self::STATS);

        self::assertSame(self::ADMIN_USER_KEYS, array_keys($shape));
    }

    public function testAnOrdinaryMemberReportsNoModerationState(): void
    {
        $shape = $this->mapper()->adminUser($this->member(), self::STATS);

        self::assertNull($shape['bannedAt']);
        self::assertNull($shape['banReason']);
        self::assertNull($shape['deletedAt']);
        self::assertFalse($shape['isAdmin']);
    }

    public function testASuspensionIsPublishedWithItsReason(): void
    {
        $shape = $this->mapper()->adminUser($this->member()->ban('Spamming borrow requests'), self::STATS);

        self::assertNotNull($shape['bannedAt']);
        self::assertSame('Spamming borrow requests', $shape['banReason']);
    }

    /**
     * ATOM, like every other instant the API emits — the day-bucket Y-m-d form
     * belongs to the analytics series alone, where a calendar day is the unit.
     */
    public function testTimestampsAreAtom(): void
    {
        $shape = $this->mapper()->adminUser($this->member()->ban('x'), self::STATS);

        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $shape['createdAt']));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $shape['bannedAt']));
    }

    /**
     * The counts arrive from UserStatsProvider already grouped for the whole
     * page; the mapper passes them through rather than re-keying them, so a
     * change to that shape must surface here.
     */
    public function testStatsArePassedThroughUnchanged(): void
    {
        $shape = $this->mapper()->adminUser($this->member(), self::STATS);

        self::assertSame(self::STATS, $shape['stats']);
    }

    /**
     * The permission this shape carries must not have leaked sideways. These are
     * the shapes any member can reach; none of them may name an email address.
     */
    public function testTheCommunityUserShapesStillCarryNoEmail(): void
    {
        $mapper = $this->mapper();
        $member = $this->member();

        foreach (['userSummary', 'publicProfile'] as $shapeName) {
            self::assertArrayNotHasKey('email', $mapper->{$shapeName}($member), $shapeName);
            self::assertArrayNotHasKey('isAdmin', $mapper->{$shapeName}($member), $shapeName);
        }
    }
}
