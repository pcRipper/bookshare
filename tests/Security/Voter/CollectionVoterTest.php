<?php

namespace App\Tests\Security\Voter;

use App\Entity\BookCollection;
use App\Entity\User;
use App\Repository\CollectionRequestRepository;
use App\Security\Voter\CollectionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for {@see CollectionVoter}: a collection may be edited/deleted only
 * by its owner, and only while it isn't actively borrowed.
 */
class CollectionVoterTest extends TestCase
{
    public function testOwnerCanEditAndDeleteWhenNotBorrowed(): void
    {
        $owner = new User();
        $collection = (new BookCollection())->setOwner($owner);
        $voter = $this->voter(active: false);
        $token = $this->tokenFor($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $collection, [CollectionVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $collection, [CollectionVoter::DELETE]));
    }

    public function testOwnerCannotEditOrDeleteWhileBorrowed(): void
    {
        $owner = new User();
        $collection = (new BookCollection())->setOwner($owner);
        $voter = $this->voter(active: true);
        $token = $this->tokenFor($owner);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $collection, [CollectionVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $collection, [CollectionVoter::DELETE]));
    }

    public function testNonOwnerIsDenied(): void
    {
        $collection = (new BookCollection())->setOwner(new User());
        $voter = $this->voter(active: false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenFor(new User()), $collection, [CollectionVoter::EDIT]));
    }

    public function testUnauthenticatedUserIsDenied(): void
    {
        $collection = (new BookCollection())->setOwner(new User());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter(false)->vote($this->tokenFor(null), $collection, [CollectionVoter::EDIT]));
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $collection = (new BookCollection())->setOwner(new User());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter(false)->vote($this->tokenFor(new User()), $collection, ['OTHER']));
    }

    public function testAbstainsOnUnsupportedSubject(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter(false)->vote($this->tokenFor(new User()), new \stdClass(), [CollectionVoter::EDIT]));
    }

    private function voter(bool $active): CollectionVoter
    {
        $repo = $this->createStub(CollectionRequestRepository::class);
        $repo->method('hasActiveForCollection')->willReturn($active);

        return new CollectionVoter($repo);
    }

    private function tokenFor(?User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
