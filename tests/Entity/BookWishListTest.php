<?php

namespace App\Tests\Entity;

use App\Entity\Book;
use App\Entity\User;
use App\Enum\WishPriority;
use PHPUnit\Framework\TestCase;

/**
 * The wish flag and its priority are one invariant, kept by the entity: a book
 * that isn't wanted has no priority, a wanted one always has a level. Every
 * query in BookRepository leans on there being no third combination.
 */
class BookWishListTest extends TestCase
{
    public function testABookIsOwnedAndUnprioritisedByDefault(): void
    {
        $book = new Book();

        self::assertFalse($book->isWished());
        self::assertNull($book->getWishPriority());
    }

    public function testWishingWithoutALevelFallsBackToTheDefault(): void
    {
        $book = (new Book())->setWish(true);

        self::assertTrue($book->isWished());
        self::assertSame(WishPriority::DEFAULT, $book->getWishPriority());
    }

    public function testUnwishingClearsTheLevel(): void
    {
        $book = (new Book())->setWish(true, WishPriority::Urgent);

        $book->setWish(false, WishPriority::Urgent);

        self::assertFalse($book->isWished());
        self::assertNull($book->getWishPriority());
    }

    public function testAcquiringMovesTheBookOntoTheShelf(): void
    {
        $owner = new User();
        $book = (new Book())->setOwner($owner)->setWish(true, WishPriority::VeryInterested);

        $book->acquire();

        self::assertFalse($book->isWished());
        self::assertNull($book->getWishPriority());
        // Acquiring changes nothing about who holds it — it was always home.
        self::assertTrue($book->isHome());
    }

    public function testPrioritiesRankByTheirBackingValue(): void
    {
        // The ordering the wish-list query relies on (ORDER BY wish_priority DESC).
        self::assertGreaterThan(WishPriority::VeryInterested->value, WishPriority::Urgent->value);
        self::assertGreaterThan(WishPriority::CanWait->value, WishPriority::VeryInterested->value);
    }
}
