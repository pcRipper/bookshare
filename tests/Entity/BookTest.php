<?php

namespace App\Tests\Entity;

use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\BookStatus;
use PHPUnit\Framework\TestCase;

class BookTest extends TestCase
{
    public function testNewBookDefaultsToOwnStatusAndHasCreatedAt(): void
    {
        $book = new Book();

        self::assertSame(BookStatus::Own, $book->getStatus());
        self::assertNull($book->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $book->getCreatedAt());
        self::assertCount(0, $book->getCategories());
    }

    public function testSetOwnerInitialisesCurrentHolderToOwner(): void
    {
        $owner = new User();
        $book = (new Book())->setOwner($owner);

        self::assertSame($owner, $book->getOwner());
        self::assertSame($owner, $book->getCurrentHolder());
        self::assertTrue($book->isHome());
    }

    public function testSetOwnerDoesNotOverwriteAnExistingHolder(): void
    {
        $owner = new User();
        $borrower = new User();

        $book = (new Book())->setOwner($owner);
        $book->setCurrentHolder($borrower);

        // Re-assigning the owner must not yank the book back from the borrower.
        $book->setOwner($owner);

        self::assertSame($borrower, $book->getCurrentHolder());
        self::assertFalse($book->isHome());
    }

    public function testIsHomeReflectsHolderVsOwner(): void
    {
        $owner = new User();
        $borrower = new User();
        $book = (new Book())->setOwner($owner);

        self::assertTrue($book->isHome());

        $book->setCurrentHolder($borrower);
        self::assertFalse($book->isHome());

        $book->setCurrentHolder($owner);
        self::assertTrue($book->isHome());
    }

    public function testCategoriesAddAreDeduplicated(): void
    {
        $book = new Book();
        $cat = new Category();

        $book->addCategory($cat)->addCategory($cat);

        self::assertCount(1, $book->getCategories());
        self::assertTrue($book->getCategories()->contains($cat));
    }

    public function testRemoveAndClearCategories(): void
    {
        $book = new Book();
        $a = new Category();
        $b = new Category();
        $book->addCategory($a)->addCategory($b);

        $book->removeCategory($a);
        self::assertCount(1, $book->getCategories());
        self::assertFalse($book->getCategories()->contains($a));

        $book->clearCategories();
        self::assertCount(0, $book->getCategories());
    }

    public function testScalarSettersAreFluentAndStore(): void
    {
        $book = (new Book())
            ->setTitle('Dune')
            ->setAuthor('Frank Herbert')
            ->setIsbn('978-0441013593')
            ->setCoverPath('/covers/dune.jpg')
            ->setStatus(BookStatus::Lent);

        self::assertSame('Dune', $book->getTitle());
        self::assertSame('Frank Herbert', $book->getAuthor());
        self::assertSame('978-0441013593', $book->getIsbn());
        self::assertSame('/covers/dune.jpg', $book->getCoverPath());
        self::assertSame(BookStatus::Lent, $book->getStatus());
    }
}
