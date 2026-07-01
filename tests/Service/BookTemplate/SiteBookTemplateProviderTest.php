<?php

namespace App\Tests\Service\BookTemplate;

use App\Entity\Book;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Service\BookTemplate\SiteBookTemplateProvider;
use PHPUnit\Framework\TestCase;

class SiteBookTemplateProviderTest extends TestCase
{
    private function book(string $title, string $author, ?string $isbn = null, ?string $cover = null, ?string $lang = null): Book
    {
        return (new Book())
            ->setOwner(new User())
            ->setTitle($title)
            ->setAuthor($author)
            ->setIsbn($isbn)
            ->setCoverPath($cover)
            ->setLanguage($lang);
    }

    public function testKeyIsSite(): void
    {
        $provider = new SiteBookTemplateProvider($this->createStub(BookRepository::class));

        self::assertSame('site', $provider->key());
    }

    public function testMapsBooksToTemplateMetadataOnly(): void
    {
        $repo = $this->createStub(BookRepository::class);
        $repo->method('searchTemplates')->willReturn([
            $this->book('Dune', 'Frank Herbert', '978-1', 'http://c/1.jpg', 'en'),
        ]);

        $result = (new SiteBookTemplateProvider($repo))->search('dune', 12);

        self::assertCount(1, $result);
        self::assertSame('Dune', $result[0]->title);
        self::assertSame('Frank Herbert', $result[0]->author);
        self::assertSame('978-1', $result[0]->isbn);
        self::assertSame('http://c/1.jpg', $result[0]->coverPath);
        self::assertSame('en', $result[0]->language);
    }

    public function testCollapsesIdenticalCopiesFromDifferentOwners(): void
    {
        $repo = $this->createStub(BookRepository::class);
        $repo->method('searchTemplates')->willReturn([
            $this->book('Dune', 'Frank Herbert', '978-1', 'http://c/1.jpg', 'en'),
            $this->book('Dune', 'Frank Herbert', '978-1', 'http://c/1.jpg', 'en'),
            $this->book('Dune', 'Frank Herbert', '978-2', 'http://c/1.jpg', 'en'), // different ISBN → kept
        ]);

        $result = (new SiteBookTemplateProvider($repo))->search('dune', 12);

        self::assertCount(2, $result);
        self::assertSame('978-1', $result[0]->isbn);
        self::assertSame('978-2', $result[1]->isbn);
    }

    public function testCapsResultsAtLimitAfterCollapsing(): void
    {
        $repo = $this->createStub(BookRepository::class);
        $repo->method('searchTemplates')->willReturn([
            $this->book('A', 'X'),
            $this->book('B', 'X'),
            $this->book('C', 'X'),
        ]);

        $result = (new SiteBookTemplateProvider($repo))->search('x', 2);

        self::assertCount(2, $result);
    }

    public function testBlankQueryReturnsEmptyWithoutHittingTheRepository(): void
    {
        $repo = $this->createMock(BookRepository::class);
        $repo->expects($this->never())->method('searchTemplates');

        self::assertSame([], (new SiteBookTemplateProvider($repo))->search('   ', 12));
    }
}
