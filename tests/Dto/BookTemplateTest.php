<?php

namespace App\Tests\Dto;

use App\Dto\BookTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookTemplateTest extends TestCase
{
    public function testIdenticalTemplatesShareADedupeKey(): void
    {
        $a = new BookTemplate('Dune', 'Frank Herbert', '978-0441013593', 'http://c/1.jpg', 'en');
        $b = new BookTemplate('Dune', 'Frank Herbert', '978-0441013593', 'http://c/1.jpg', 'en');

        self::assertSame($a->dedupeKey(), $b->dedupeKey());
    }

    public function testTitleAndAuthorAreComparedCaseAndWhitespaceInsensitively(): void
    {
        $a = new BookTemplate('Dune', 'Frank Herbert');
        $b = new BookTemplate('  DUNE ', 'frank herbert');

        self::assertSame($a->dedupeKey(), $b->dedupeKey());
    }

    /** Any one of the five collapse fields differing keeps templates distinct. */
    #[DataProvider('distinguishingFields')]
    public function testAnyDifferingFieldMakesADistinctKey(BookTemplate $other): void
    {
        $base = new BookTemplate('Dune', 'Frank Herbert', '111', 'http://c/1.jpg', 'en');

        self::assertNotSame($base->dedupeKey(), $other->dedupeKey());
    }

    public static function distinguishingFields(): array
    {
        return [
            'language' => [new BookTemplate('Dune', 'Frank Herbert', '111', 'http://c/1.jpg', 'fr')],
            'isbn'     => [new BookTemplate('Dune', 'Frank Herbert', '222', 'http://c/1.jpg', 'en')],
            'cover'    => [new BookTemplate('Dune', 'Frank Herbert', '111', 'http://c/2.jpg', 'en')],
            'author'   => [new BookTemplate('Dune', 'Someone Else', '111', 'http://c/1.jpg', 'en')],
            'title'    => [new BookTemplate('Other', 'Frank Herbert', '111', 'http://c/1.jpg', 'en')],
        ];
    }
}
