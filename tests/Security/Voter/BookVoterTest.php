<?php

namespace App\Tests\Security\Voter;

use App\Entity\Book;
use App\Entity\User;
use App\Security\Voter\BookVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for {@see BookVoter}: a book may be edited/deleted only by its
 * owner, and only while it's home (not out on loan).
 */
class BookVoterTest extends TestCase
{
    private BookVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new BookVoter();
    }

    public function testOwnerCanEditAndDeleteBookAtHome(): void
    {
        $owner = $this->user();
        $book = $this->book($owner); // holder defaults to owner ⇒ home

        $token = $this->tokenFor($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $book, [BookVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $book, [BookVoter::DELETE]));
    }

    public function testOwnerCannotEditOrDeleteBookOnLoan(): void
    {
        $owner = $this->user();
        $borrower = $this->user();
        $book = $this->book($owner);
        $book->setCurrentHolder($borrower); // out on loan ⇒ locked

        $token = $this->tokenFor($owner);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $book, [BookVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $book, [BookVoter::DELETE]));
    }

    public function testNonOwnerCannotEditHomeBook(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $book = $this->book($owner);

        $token = $this->tokenFor($stranger);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $book, [BookVoter::EDIT]));
    }

    public function testUnauthenticatedUserIsDenied(): void
    {
        $book = $this->book($this->user());

        $token = $this->tokenFor(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $book, [BookVoter::EDIT]));
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $owner = $this->user();
        $book = $this->book($owner);

        $token = $this->tokenFor($owner);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $book, ['SOME_OTHER_ATTRIBUTE']));
    }

    public function testAbstainsOnUnsupportedSubject(): void
    {
        $token = $this->tokenFor($this->user());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, new \stdClass(), [BookVoter::EDIT]));
    }

    private function user(): User
    {
        // Distinct object identities are all the voter compares; no persistence needed.
        return (new User())->setEmail('u'.spl_object_id(new \stdClass()).'@example.test');
    }

    private function book(User $owner): Book
    {
        return (new Book())->setOwner($owner);
    }

    private function tokenFor(?User $user): TokenInterface
    {
        // A stub (not a mock): we only need getUser() to return a value, not to
        // assert calls on the token.
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
