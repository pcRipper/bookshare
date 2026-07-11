<?php

namespace App\Tests\Entity;

use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class BookCollectionTest extends TestCase
{
    public function testNewCollectionStartsEmptyWithCreatedAt(): void
    {
        $collection = new BookCollection();

        self::assertCount(0, $collection->getBooks());
        self::assertNotNull($collection->getCreatedAt());
        self::assertNull($collection->getDescription());
        self::assertNull($collection->getCoverUrl());
    }

    public function testAddBookIsIdempotent(): void
    {
        $collection = new BookCollection();
        $book = new Book();

        $collection->addBook($book)->addBook($book);

        self::assertCount(1, $collection->getBooks());
        self::assertTrue($collection->getBooks()->contains($book));
    }

    public function testRemoveAndClearBooks(): void
    {
        $collection = new BookCollection();
        $a = new Book();
        $b = new Book();
        $collection->addBook($a)->addBook($b);

        $collection->removeBook($a);
        self::assertCount(1, $collection->getBooks());
        self::assertFalse($collection->getBooks()->contains($a));

        $collection->clearBooks();
        self::assertCount(0, $collection->getBooks());
    }

    public function testFluentSetters(): void
    {
        $owner = new User();
        $collection = (new BookCollection())
            ->setName('Series')
            ->setDescription('A run of novels.')
            ->setCoverUrl('http://c/x.jpg')
            ->setOwner($owner);

        self::assertSame('Series', $collection->getName());
        self::assertSame('A run of novels.', $collection->getDescription());
        self::assertSame('http://c/x.jpg', $collection->getCoverUrl());
        self::assertSame($owner, $collection->getOwner());
    }
}
